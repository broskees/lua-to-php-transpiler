<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\LightUserdata;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MemoryLimit;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Userdata;
use LuaPhp\Runtime\Vm;

/**
 * Port of loadlib.c: the package library and 'require'.
 *
 * There is no dynamic library support (C: the "Fallback for other systems"
 * lsys_* functions): package.loadlib returns fail, DLMSG and "absent", and
 * the C searchers fail with DLMSG when they find a file.
 *
 * Functions that C gives the 'package' table as upvalue (require and the
 * searchers) read it from their NativeFunction's upvalues through
 * $L->ci->func, as lua_upvalueindex(1) does.
 */
final class PackageLib
{
    // luaconf.h, as the reference lua5.4 on Linux is built (LUA_ROOT
    // "/usr/local/" plus the distribution's "/usr/" directories)
    private const LUA_PATH_DEFAULT =
        '/usr/local/share/lua/5.4/?.lua;/usr/local/share/lua/5.4/?/init.lua;'
        . '/usr/share/lua/5.4/?.lua;/usr/share/lua/5.4/?/init.lua;'
        . '/usr/local/lib/lua/5.4/?.lua;/usr/local/lib/lua/5.4/?/init.lua;'
        . '/usr/lib/lua/5.4/?.lua;/usr/lib/lua/5.4/?/init.lua;'
        . './?.lua;./?/init.lua';
    private const LUA_CPATH_DEFAULT =
        '/usr/local/lib/lua/5.4/?.so;/usr/lib/lua/5.4/?.so;'
        . '/usr/local/lib/lua/5.4/loadall.so;/usr/lib/lua/5.4/loadall.so;./?.so';

    // luaconf.h
    private const LUA_DIRSEP = '/';
    private const LUA_PATH_SEP = ';';
    private const LUA_PATH_MARK = '?';
    private const LUA_EXEC_DIR = '!';
    private const LUA_IGMARK = '-';

    // loadlib.c: LUA_CSUBSEP / LUA_LSUBSEP
    private const LUA_CSUBSEP = self::LUA_DIRSEP;
    private const LUA_LSUBSEP = self::LUA_DIRSEP;

    // loadlib.c: LUA_POF / LUA_OFSEP
    private const LUA_POF = 'luaopen_';
    private const LUA_OFSEP = '_';

    // loadlib.c: CLIBS, LIB_FAIL and DLMSG of the fallback implementation
    private const CLIBS = '_CLIBS';
    private const LIB_FAIL = 'absent';
    private const DLMSG = 'dynamic libraries not enabled; check your Lua installation';

    // loadlib.c: LUA_PATH_VAR / LUA_CPATH_VAR; lualib.h: LUA_VERSUFFIX
    private const LUA_PATH_VAR = 'LUA_PATH';
    private const LUA_CPATH_VAR = 'LUA_CPATH';
    private const LUA_VERSUFFIX = '_5_4';

    // loadlib.c: error codes for 'lookforfunc'
    private const ERRLIB = 1;
    private const ERRFUNC = 2;

    // loadlib.c: luaopen_package
    public static function open(Coroutine $L): LuaTable
    {
        self::createClibsTable($L);
        $package = new LuaTable();
        Auxiliary::setFunctions($package, [
            'loadlib' => self::loadlib(...),
            'searchpath' => self::searchpathFunction(...),
        ]);
        self::createSearchersTable($package);
        // set paths
        self::setPath($L, $package, 'path', self::LUA_PATH_VAR, self::LUA_PATH_DEFAULT);
        self::setPath($L, $package, 'cpath', self::LUA_CPATH_VAR, self::LUA_CPATH_DEFAULT);
        // store config information
        $package->hash['config'] = self::LUA_DIRSEP . "\n" . self::LUA_PATH_SEP . "\n" . self::LUA_PATH_MARK . "\n"
            . self::LUA_EXEC_DIR . "\n" . self::LUA_IGMARK . "\n";
        $package->hash['loaded'] = self::registrySubtable($L, Lua::LUA_LOADED_TABLE);  // set field 'loaded'
        $package->hash['preload'] = self::registrySubtable($L, Lua::LUA_PRELOAD_TABLE);  // set field 'preload'
        // open lib into global table, with 'package' as upvalue
        $L->globalState->globals->hash['require'] = new NativeFunction('require', self::require(...), [$package]);
        return $package;
    }

    /** lauxlib.c: luaL_getsubtable(L, LUA_REGISTRYINDEX, $name) */
    private static function registrySubtable(Coroutine $L, string $name): LuaTable
    {
        $registry = $L->globalState->registry;
        $table = $registry->hash[$name] ?? null;
        if (!($table instanceof LuaTable)) {
            $table = new LuaTable();
            $registry->hash[$name] = $table;
        }
        return $table;
    }

    /**
     * loadlib.c: createclibstable: registry.CLIBS keeps the loaded C
     * libraries (always empty here), with a finalizer that unloads them.
     */
    private static function createClibsTable(Coroutine $L): void
    {
        $clibs = self::registrySubtable($L, self::CLIBS);
        $metatable = new LuaTable();
        Auxiliary::setFunctions($metatable, ['__gc' => self::gctm(...)]);
        Collector::setMetatable($L, $clibs, $metatable);  // set CLIBS metatable
    }

    // loadlib.c: gctm (lsys_unloadlib does nothing without dynamic libraries)
    private static function gctm(Coroutine $L, array $args): array
    {
        Auxiliary::len($L, $args[0] ?? null);
        return [];
    }

    // loadlib.c: createsearcherstable
    private static function createSearchersTable(LuaTable $package): void
    {
        $searchers = [
            'searcher_preload' => self::searcherPreload(...),
            'searcher_Lua' => self::searcherLua(...),
            'searcher_C' => self::searcherC(...),
            'searcher_Croot' => self::searcherCroot(...),
        ];
        $table = new LuaTable(\count($searchers));
        $index = 1;
        foreach ($searchers as $name => $searcher) {
            // set 'package' as upvalue for all searchers
            $table->arr[$index++] = new NativeFunction($name, $searcher, [$package]);
        }
        $package->hash['searchers'] = $table;
    }

    /** lua_upvalueindex(1) of the running native function: the 'package' table */
    private static function packageUpvalue(Coroutine $L): mixed
    {
        return $L->ci->func->upvalues[0] ?? null;
    }

    /*
    ** {==================================================================
    ** Set Paths
    ** ===================================================================
    */

    /** loadlib.c: noenv: registry.LUA_NOENV as a boolean */
    private static function noEnv(Coroutine $L): bool
    {
        $value = $L->globalState->registry->hash['LUA_NOENV'] ?? null;
        return $value !== null && $value !== false;
    }

    // loadlib.c: setpath
    private static function setPath(Coroutine $L, LuaTable $package, string $fieldName, string $envName, string $default): void
    {
        $path = getenv($envName . self::LUA_VERSUFFIX);  // try versioned name
        if ($path === false) {  // no versioned environment variable?
            $path = getenv($envName);  // try unversioned name
        }
        if ($path === false || self::noEnv($L)) {  // no environment variable?
            $value = $default;  // use default
        } elseif (($defaultMark = strpos($path, self::LUA_PATH_SEP . self::LUA_PATH_SEP)) === false) {
            $value = $path;  // nothing to change
        } else {  // path contains a ";;": insert default path in its place
            $value = '';
            if ($defaultMark > 0) {  // is there a prefix before ';;'?
                $value .= substr($path, 0, $defaultMark) . self::LUA_PATH_SEP;
            }
            $value .= $default;  // add default
            if ($defaultMark < \strlen($path) - 2) {  // is there a suffix after ';;'?
                $value .= self::LUA_PATH_SEP . substr($path, $defaultMark + 2);
            }
        }
        $package->hash[$fieldName] = $value;  // package[fieldname] = path value
    }

    /* }================================================================== */

    /**
     * loadlib.c: lookforfunc: look for C function $symbol in the dynamic
     * library $path. Returns [0, true] ($symbol "*": library loaded) or
     * [error code, error message]; without dynamic libraries lsys_load and
     * lsys_sym always fail.
     *
     * @return array{int, mixed}
     */
    private static function lookForFunction(Coroutine $L, string $path, string $symbol): array
    {
        $clibs = $L->globalState->registry->hash[self::CLIBS] ?? null;
        $library = Vm::getTable($L, $clibs, $path);  // checkclib: registry.CLIBS[path]
        if (!($library instanceof LightUserdata || $library instanceof Userdata)) {  // must load library?
            return [self::ERRLIB, self::DLMSG];  // lsys_load: unable to load library
        }
        if (str_starts_with($symbol, '*')) {  // loading only library (no function)?
            return [0, true];
        }
        return [self::ERRFUNC, self::DLMSG];  // lsys_sym: unable to find function
    }

    // loadlib.c: ll_loadlib
    private static function loadlib(Coroutine $L, array $args): array
    {
        $path = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $init = DebugInfo::cString(Auxiliary::checkString($L, $args, 2));
        [$status, $result] = self::lookForFunction($L, $path, $init);
        if ($status === 0) {  // no errors?
            return [$result];  // return the loaded function
        }
        // error: return fail, error message, and where
        return [null, $result, $status === self::ERRLIB ? self::LIB_FAIL : 'init'];
    }

    /*
    ** {======================================================
    ** 'require' function
    ** =======================================================
    */

    // loadlib.c: readable
    private static function readable(string $filename): bool
    {
        if ($filename === '') {
            return false;  // fopen("") fails (PHP's fopen throws instead)
        }
        $file = @fopen($filename, 'r');  // try to open file
        if ($file === false) {
            return false;  // open failed
        }
        fclose($file);
        return true;
    }

    /**
     * loadlib.c: pusherrornotfound: for ";blabla.so;blublu.so" the string
     *
     *     no file 'blabla.so'
     *     	no file 'blublu.so'
     */
    private static function errorNotFound(string $path): string
    {
        MemoryLimit::reserve(\strlen($path) + 10 + substr_count($path, self::LUA_PATH_SEP) * 11);
        return "no file '" . str_replace(self::LUA_PATH_SEP, "'\n\tno file '", $path) . "'";
    }

    /**
     * loadlib.c: searchpath: returns [filename, null] for the first
     * readable file, or [null, error message]. Arguments are C strings.
     * With $precompiledFiles (scripts bin/lua2php generates, see
     * ChunkLoader::loadFile) a file whose precompiled PHP exists counts
     * as readable too: the Lua file need not be there. Another PHP file
     * there, or the precompiled file of another name ("x" and "x.lua"
     * share x.php), does not count.
     *
     * @return array{?string, ?string}
     */
    private static function searchPath(string $name, string $path, string $separator, string $directorySeparator, bool $precompiledFiles): array
    {
        $name = DebugInfo::cString($name);
        $path = DebugInfo::cString($path);
        $separator = DebugInfo::cString($separator);
        $directorySeparator = DebugInfo::cString($directorySeparator);
        // separator is non-empty and appears in 'name'?
        if ($separator !== '' && str_contains($name, $separator[0])) {
            MemoryLimit::reserve(\strlen($name) + substr_count($name, $separator) * max(0, \strlen($directorySeparator) - \strlen($separator)));
            $name = str_replace($separator, $directorySeparator, $name);  // replace it by 'dirsep'
        }
        // add path to the buffer, replacing marks ('?') with the file name
        MemoryLimit::reserve(\strlen($path) + substr_count($path, self::LUA_PATH_MARK) * \strlen($name));
        $pathName = str_replace(self::LUA_PATH_MARK, $name, $path);
        // loadlib.c: getnextfilename: the names in 'name1;name2;...' (none for an empty path)
        $end = \strlen($pathName);
        for ($start = 0; $pathName !== '' && $start <= $end; $start = $separatorPosition + 1) {
            $separatorPosition = strpos($pathName, self::LUA_PATH_SEP, $start);
            if ($separatorPosition === false) {
                $separatorPosition = $end;  // name goes until the end
            }
            $filename = substr($pathName, $start, $separatorPosition - $start);
            if (($precompiledFiles && ChunkLoader::hasPrecompiledFile($filename)) || self::readable($filename)) {  // does file exist and is readable?
                return [$filename, null];  // return that name
            }
        }
        return [null, self::errorNotFound($pathName)];  // not found
    }

    // loadlib.c: ll_searchpath
    private static function searchpathFunction(Coroutine $L, array $args): array
    {
        // the reference build (gcc) evaluates these C call arguments right to left
        $directorySeparator = Auxiliary::optString($L, $args, 4, self::LUA_DIRSEP);
        $separator = Auxiliary::optString($L, $args, 3, '.');
        $path = Auxiliary::checkString($L, $args, 2);
        $name = Auxiliary::checkString($L, $args, 1);
        [$filename, $error] = self::searchPath($name, $path, $separator, $directorySeparator, $L->globalState->filesArePrecompiled);
        if ($filename !== null) {
            return [$filename];
        }
        return [null, $error];  // return fail + error message
    }

    /** loadlib.c: findfile: search package[$pathFieldName] for $name */
    private static function findFile(Coroutine $L, string $name, string $pathFieldName, string $directorySeparator): array
    {
        $path = LuaObject::toStringCoerced(Vm::getTable($L, self::packageUpvalue($L), $pathFieldName));
        if ($path === null) {
            Auxiliary::error($L, "'package.$pathFieldName' must be a string");
        }
        return self::searchPath($name, $path, '.', $directorySeparator, $L->globalState->filesArePrecompiled);
    }

    /** loadlib.c: checkload: the open function and file name, or the error */
    private static function checkLoad(Coroutine $L, array $args, mixed $loader, bool $loaded, string $filename, mixed $error): array
    {
        if ($loaded) {  // module loaded successfully?
            return [$loader, $filename];  // return open function and file name (2nd argument to module)
        }
        Auxiliary::error($L, "error loading module '" . DebugInfo::cString(LuaObject::toStringCoerced($args[0] ?? null))
            . "' from file '$filename':\n\t" . DebugInfo::cString(LuaObject::toStringCoerced($error)));
    }

    // loadlib.c: searcher_Lua
    private static function searcherLua(Coroutine $L, array $args): array
    {
        $name = Auxiliary::checkString($L, $args, 1);
        [$filename, $error] = self::findFile($L, $name, 'path', self::LUA_LSUBSEP);
        if ($filename === null) {
            return [$error];  // module not found in this path
        }
        try {
            $loader = ChunkLoader::loadFile($L, $filename, null);  // luaL_loadfile
        } catch (LuaError $loadError) {
            return self::checkLoad($L, $args, null, false, $filename, $loadError->value);
        }
        return self::checkLoad($L, $args, $loader, true, $filename, null);
    }

    /**
     * loadlib.c: loadfunc: look for the open function of module $moduleName
     * in the C library $filename ("luaopen_X" for "X-Y", else
     * "luaopen_" + name with '.' replaced by '_').
     *
     * @return array{int, mixed}
     */
    private static function loadFunction(Coroutine $L, string $filename, string $moduleName): array
    {
        $moduleName = str_replace('.', self::LUA_OFSEP, $moduleName);
        $markPosition = strpos($moduleName, self::LUA_IGMARK);
        if ($markPosition !== false) {
            $openFunction = self::LUA_POF . substr($moduleName, 0, $markPosition);
            $result = self::lookForFunction($L, $filename, $openFunction);
            if ($result[0] !== self::ERRFUNC) {
                return $result;
            }
            $moduleName = substr($moduleName, $markPosition + 1);  // else go ahead and try old-style name
        }
        return self::lookForFunction($L, $filename, self::LUA_POF . $moduleName);
    }

    // loadlib.c: searcher_C
    private static function searcherC(Coroutine $L, array $args): array
    {
        $name = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        [$filename, $error] = self::findFile($L, $name, 'cpath', self::LUA_CSUBSEP);
        if ($filename === null) {
            return [$error];  // module not found in this path
        }
        [$status, $result] = self::loadFunction($L, $filename, $name);
        return self::checkLoad($L, $args, $result, $status === 0, $filename, $result);
    }

    // loadlib.c: searcher_Croot
    private static function searcherCroot(Coroutine $L, array $args): array
    {
        $name = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $dotPosition = strpos($name, '.');
        if ($dotPosition === false) {
            return [];  // is root
        }
        [$filename, $error] = self::findFile($L, substr($name, 0, $dotPosition), 'cpath', self::LUA_CSUBSEP);
        if ($filename === null) {
            return [$error];  // root not found
        }
        [$status, $result] = self::loadFunction($L, $filename, $name);
        if ($status === self::ERRFUNC) {  // open function not found
            return ["no module '$name' in file '$filename'"];
        }
        if ($status !== 0) {
            return self::checkLoad($L, $args, null, false, $filename, $result);  // real error
        }
        return [$result, $filename];  // filename will be 2nd argument to module
    }

    // loadlib.c: searcher_preload
    private static function searcherPreload(Coroutine $L, array $args): array
    {
        $name = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $preload = $L->globalState->registry->hash[Lua::LUA_PRELOAD_TABLE] ?? null;
        $loader = Vm::getTable($L, $preload, $name);
        if ($loader === null) {  // not found?
            return ["no field package.preload['$name']"];
        }
        return [$loader, ':preload:'];
    }

    /**
     * loadlib.c: findloader: ask each searcher for a loader of $name.
     * Returns [loader, loader data].
     *
     * @return array{mixed, mixed}
     */
    private static function findLoader(Coroutine $L, string $name): array
    {
        $searchers = Vm::getTable($L, self::packageUpvalue($L), 'searchers');
        if (!($searchers instanceof LuaTable)) {
            Auxiliary::error($L, "'package.searchers' must be a table");
        }
        $message = '';  // to build error message
        // iterate over available searchers to find a loader
        for ($i = 1; ; $i++) {
            $searcher = $searchers->get($i);
            if ($searcher === null) {  // no more searchers?
                Auxiliary::error($L, "module '$name' not found:" . DebugInfo::cString($message));
            }
            $results = Calls::call($L, $searcher, [$name]);  // call it
            $loader = $results[0] ?? null;
            if ($loader instanceof LuaClosure || $loader instanceof NativeFunction) {  // did it find a loader?
                return [$loader, $results[1] ?? null];  // module loader found
            }
            $searcherMessage = LuaObject::toStringCoerced($loader);
            if ($searcherMessage !== null) {  // searcher returned error message?
                $message .= "\n\t" . $searcherMessage;  // concatenate error message
            }
        }
    }

    // loadlib.c: ll_require
    private static function require(Coroutine $L, array $args): array
    {
        $fullName = Auxiliary::checkString($L, $args, 1);
        $name = DebugInfo::cString($fullName);
        $loadedTable = $L->globalState->registry->hash[Lua::LUA_LOADED_TABLE] ?? null;
        $module = Vm::getTable($L, $loadedTable, $name);  // LOADED[name]
        if ($module !== null && $module !== false) {  // is it there?
            return [$module];  // package is already loaded
        }
        // else must load package
        [$loader, $loaderData] = self::findLoader($L, $name);
        // run loader to load module, with name and loader data as arguments
        $results = Calls::call($L, $loader, [$fullName, $loaderData]);
        $result = $results[0] ?? null;
        if ($result !== null) {  // non-nil return?
            Vm::setTable($L, $loadedTable, $name, $result);  // LOADED[name] = returned value
        }
        $module = Vm::getTable($L, $loadedTable, $name);
        if ($module === null) {  // module set no value?
            $module = true;  // use true as result
            Vm::setTable($L, $loadedTable, $name, true);  // LOADED[name] = true
        }
        return [$module, $loaderData];  // return module result and loader data
    }

    /* }====================================================== */
}
