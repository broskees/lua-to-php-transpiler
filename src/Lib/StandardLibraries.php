<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaTable;

/**
 * Port of linit.c: luaL_openlibs, and the selection of libraries an
 * embedded state gets (openSelected).
 *
 * @internal
 */
final class StandardLibraries
{
    /** linit.c: loadedlibs, in its order (base is opened first, into _G) */
    private const OPENERS = [
        'package' => [PackageLib::class, 'open'],
        'coroutine' => [CoroutineLib::class, 'open'],
        'table' => [TableLib::class, 'open'],
        'io' => [IoLib::class, 'open'],
        'os' => [OsLib::class, 'open'],
        'string' => [StringLib::class, 'open'],
        'math' => [MathLib::class, 'open'],
        'utf8' => [Utf8Lib::class, 'open'],
        'debug' => [DebugLib::class, 'open'],
    ];

    /** what a sandbox takes out of each library (see openSelected) */
    private const SANDBOX_REMOVES = [
        'base' => ['dofile', 'loadfile', 'load'],
        'string' => ['dump'],
        'package' => ['config', 'cpath', 'loadlib', 'path', 'preload', 'searchers', 'searchpath'],
    ];

    public static function openAll(Coroutine $L): void
    {
        $globals = $L->globalState->globals;
        Auxiliary::registerLoaded($L, Lua::LUA_GNAME, BaseLib::open($L));
        foreach (self::OPENERS as $name => $opener) {
            $module = $opener($L);
            Auxiliary::registerLoaded($L, $name, $module);  // package.loaded[name] = module
            $globals->hash[$name] = $module;  // set global name
        }
    }

    /**
     * Opens the libraries in $names, in linit.c's order: "base", "package",
     * "string", "table", "math", "utf8", "coroutine", "io", "os", "debug",
     * or "lib.func" for single functions of a library (e.g. "os.time";
     * "base.print" for a global of the base library). A library of which
     * only some functions are named keeps just those (its module table is
     * the same table, so the string metatable's __index has only them too).
     *
     * $sandboxed applies a sandbox's restrictions: no dofile, loadfile nor
     * string.dump; load only with $allowLoad, then for text chunks only (and
     * compiled with step counting when the state counts steps);
     * collectgarbage only with "collect", "count" and "step"; package is
     * only package.loaded, and there is no 'require' until
     * PackageLib::openSandboxRequire installs one.
     *
     * An unknown name is an \InvalidArgumentException.
     *
     * @param list<string> $names
     */
    public static function openSelected(Coroutine $L, array $names, bool $sandboxed, bool $allowLoad): void
    {
        // library => true (all of it) or the list of its functions
        $selected = [];
        foreach ($names as $name) {
            [$library, $function] = str_contains($name, '.') ? explode('.', $name, 2) : [$name, null];
            if ($library !== 'base' && !isset(self::OPENERS[$library])) {
                throw new \InvalidArgumentException("unknown library '$name'");
            }
            if ($function === null) {
                $selected[$library] = true;
            } elseif (($selected[$library] ?? null) !== true) {
                $selected[$library][] = $function;
            }
        }
        $globals = $L->globalState->globals;
        if (isset($selected['base'])) {
            $module = BaseLib::open($L);
            Auxiliary::registerLoaded($L, Lua::LUA_GNAME, $module);
            if ($sandboxed) {
                BaseLib::restrictForSandbox($L, $allowLoad);
            }
            if ($selected['base'] !== true) {
                self::keepOnly($module, 'base', $selected['base'], $sandboxed);
            }
        }
        foreach (self::OPENERS as $library => $opener) {
            if (!isset($selected[$library])) {
                continue;
            }
            $module = ($sandboxed && $library === 'package') ? PackageLib::openSandboxed($L) : $opener($L);
            if ($sandboxed && $library === 'string') {
                $module->set('dump', null);
            }
            if ($selected[$library] !== true) {
                self::keepOnly($module, $library, $selected[$library], $sandboxed);
            }
            Auxiliary::registerLoaded($L, $library, $module);  // package.loaded[name] = module
            $globals->hash[$library] = $module;  // set global name
        }
    }

    /**
     * Removes from $module (of $library; base: the global table, which
     * keeps _G and _VERSION) every field but $functions. Naming a function
     * the library does not have is an \InvalidArgumentException, unless a
     * sandbox took it out.
     *
     * @param list<string> $functions
     */
    private static function keepOnly(LuaTable $module, string $library, array $functions, bool $sandboxed): void
    {
        foreach ($functions as $function) {
            if (!isset($module->hash[$function]) && !($sandboxed && \in_array($function, self::SANDBOX_REMOVES[$library] ?? [], true))) {
                throw new \InvalidArgumentException("unknown library function '$library.$function'");
            }
        }
        $kept = array_flip($functions);
        foreach (array_keys($module->hash) as $key) {
            if (!isset($kept[$key]) && !($library === 'base' && ($key === '_G' || $key === '_VERSION'))) {
                $module->set((string) $key, null);
            }
        }
    }
}
