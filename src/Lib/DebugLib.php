<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\CallInfo;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\Hooks;
use LuaPhp\Runtime\LightUserdata;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MetaMethods;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Standalone;
use LuaPhp\Runtime\Userdata;

/**
 * Port of ldblib.c (the debug library). The functions that take an
 * optional thread first work on that thread's CallInfo chain.
 */
final class DebugLib
{
    /** ldblib.c: HOOKKEY: registry field of the table mapping threads to their hook functions */
    private const HOOKKEY = '_HOOKKEY';

    // ldblib.c: hookf's hooknames, indexed by Lua::LUA_HOOK*
    private const HOOK_NAMES = ['call', 'return', 'line', 'count', 'tail call'];

    /** the closure installed as $L->hook (C: hookf), created once so gethook can recognize it */
    private static ?\Closure $hookFunction = null;

    // ldblib.c: luaopen_debug
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'debug' => self::debug(...),
            'getuservalue' => self::getuservalue(...),
            'gethook' => self::gethook(...),
            'getinfo' => self::getinfo(...),
            'getlocal' => self::getlocal(...),
            'getregistry' => self::getregistry(...),
            'getmetatable' => self::getmetatable(...),
            'getupvalue' => self::getupvalue(...),
            'upvaluejoin' => self::upvaluejoin(...),
            'upvalueid' => self::upvalueid(...),
            'setuservalue' => self::setuservalue(...),
            'sethook' => self::sethook(...),
            'setlocal' => self::setlocal(...),
            'setmetatable' => self::setmetatable(...),
            'setupvalue' => self::setupvalue(...),
            'traceback' => self::traceback(...),
            'setcstacklimit' => self::setcstacklimit(...),
        ]);
        return $library;
    }

    /**
     * ldblib.c: checkstack: a thread other than the running one may be in
     * any state, so its stack space is checked before use
     */
    private static function checkStack(Coroutine $L, Coroutine $L1, int $n): void
    {
        if ($L !== $L1 && !Calls::checkStack($L1, $n)) {
            Auxiliary::error($L, 'stack overflow');
        }
    }

    /**
     * ldblib.c: getthread: an optional thread as first argument. Returns
     * the thread to work on and the number of arguments to skip (0 or 1).
     *
     * @return array{Coroutine, int}
     */
    private static function getThread(Coroutine $L, array $args): array
    {
        if (($args[0] ?? null) instanceof Coroutine) {
            return [$args[0], 1];
        }
        return [$L, 0];  // function will operate over current thread
    }

    private static function isFunction(mixed $value): bool
    {
        return $value instanceof LuaClosure || $value instanceof NativeFunction;
    }

    /**
     * ldblib.c: db_getinfo: lua_getinfo's results in a new table, for a
     * function or for the function running at a stack level (fail when
     * the level is out of range).
     */
    private static function getinfo(Coroutine $L, array $args): array
    {
        [$L1, $arg] = self::getThread($L, $args);
        $options = DebugInfo::cString(Auxiliary::optString($L, $args, $arg + 2, 'flnSrtu'));
        self::checkStack($L, $L1, 3);
        Auxiliary::argCheck($L, !str_starts_with($options, '>'), $arg + 2, "invalid option '>'");
        $function = $args[$arg] ?? null;
        if (self::isFunction($function)) {  // info about a function? (C: the '>' option)
            $ci = null;
        } else {  // stack level
            $ci = DebugInfo::getStack($L1, self::toCInt(Auxiliary::checkInteger($L, $args, $arg + 1)));
            if ($ci === null) {
                return [null];  // level out of range
            }
        }
        $info = DebugInfo::getInfo($L1, $options, $ci, $function);
        if ($info === null) {
            Auxiliary::argError($L, $arg + 2, 'invalid option');
        }
        $result = new LuaTable();  // table to collect results
        if (str_contains($options, 'S')) {
            $result->hash['source'] = $info['source'];
            $result->hash['short_src'] = $info['short_src'];
            $result->hash['linedefined'] = $info['linedefined'];
            $result->hash['lastlinedefined'] = $info['lastlinedefined'];
            $result->hash['what'] = $info['what'];
        }
        if (str_contains($options, 'l')) {
            $result->hash['currentline'] = $info['currentline'];
        }
        if (str_contains($options, 'u')) {
            $result->hash['nups'] = $info['nups'];
            $result->hash['nparams'] = $info['nparams'];
            $result->hash['isvararg'] = $info['isvararg'];
        }
        if (str_contains($options, 'n')) {
            if ($info['name'] !== null) {  // settabss with NULL sets nil
                $result->hash['name'] = DebugInfo::cString($info['name']);
            }
            $result->hash['namewhat'] = $info['namewhat'];
        }
        if (str_contains($options, 'r')) {
            $result->hash['ftransfer'] = $info['ftransfer'];
            $result->hash['ntransfer'] = $info['ntransfer'];
        }
        if (str_contains($options, 't')) {
            $result->hash['istailcall'] = $info['istailcall'];
        }
        if (str_contains($options, 'L') && $info['activelines'] !== null) {
            $result->hash['activelines'] = $info['activelines'];
        }
        if (str_contains($options, 'f')) {
            $result->hash['func'] = $info['func'];
        }
        return [$result];  // return table
    }

    /**
     * ldblib.c: db_getlocal: name and value of local $n at a stack level
     * (lapi.c: lua_getlocal), or only the name of parameter $n of a
     * function.
     */
    private static function getlocal(Coroutine $L, array $args): array
    {
        [$L1, $arg] = self::getThread($L, $args);
        $n = self::toCInt(Auxiliary::checkInteger($L, $args, $arg + 2));  // local-variable index
        $function = $args[$arg] ?? null;
        if (self::isFunction($function)) {  // function argument?
            // lapi.c: lua_getlocal(L, NULL, n): live variables at function start (parameters)
            $name = $function instanceof LuaClosure ? DebugInfo::getLocalName($function->proto, $n, 0) : null;
            return [$name];  // return only name (there is no value)
        }
        // stack-level argument
        $level = self::toCInt(Auxiliary::checkInteger($L, $args, $arg + 1));
        $ci = DebugInfo::getStack($L1, $level);
        if ($ci === null) {  // out of range?
            Auxiliary::argError($L, $arg + 1, 'level out of range');
        }
        self::checkStack($L, $L1, 1);
        $name = DebugInfo::findLocal($L1, $ci, $n);
        if ($name === null) {
            return [null];  // no name (nor value)
        }
        return [$name, DebugInfo::localValue($ci, $n)];
    }

    /** ldblib.c: db_setlocal (lapi.c: lua_setlocal): the local's name, or nil */
    private static function setlocal(Coroutine $L, array $args): array
    {
        [$L1, $arg] = self::getThread($L, $args);
        $level = self::toCInt(Auxiliary::checkInteger($L, $args, $arg + 1));
        $n = self::toCInt(Auxiliary::checkInteger($L, $args, $arg + 2));
        $ci = DebugInfo::getStack($L1, $level);
        if ($ci === null) {  // out of range?
            Auxiliary::argError($L, $arg + 1, 'level out of range');
        }
        Auxiliary::checkAny($L, $args, $arg + 3);
        self::checkStack($L, $L1, 1);
        $name = DebugInfo::findLocal($L1, $ci, $n);
        if ($name !== null) {
            DebugInfo::setLocalValue($ci, $n, $args[$arg + 2]);
        }
        return [$name];
    }

    /**
     * ldblib.c: hookf: the hook debug.sethook installs: call the Lua hook
     * function registered for the thread with the event name and the line
     * (nil for events other than "line", or without line information).
     */
    private static function hookf(Coroutine $L, int $event, int $line, CallInfo $ci): void
    {
        $hookTable = $L->globalState->registry->hash[self::HOOKKEY] ?? null;
        $hook = $hookTable instanceof LuaTable ? $hookTable->get($L) : null;
        if (self::isFunction($hook)) {  // is there a hook function?
            Calls::callNoYield($L, $hook, [self::HOOK_NAMES[$event], $line >= 0 ? $line : null]);  // call hook function
        }
    }

    private static function hookFunction(): \Closure
    {
        return self::$hookFunction ??= self::hookf(...);
    }

    // ldblib.c: makemask: string mask (for 'sethook') -> bit mask
    private static function makeMask(string $smask, int $count): int
    {
        $smask = DebugInfo::cString($smask);
        $mask = 0;
        if (str_contains($smask, 'c')) {
            $mask |= Lua::LUA_MASKCALL;
        }
        if (str_contains($smask, 'r')) {
            $mask |= Lua::LUA_MASKRET;
        }
        if (str_contains($smask, 'l')) {
            $mask |= Lua::LUA_MASKLINE;
        }
        if ($count > 0) {
            $mask |= Lua::LUA_MASKCOUNT;
        }
        return $mask;
    }

    // ldblib.c: unmakemask: bit mask (for 'gethook') -> string mask
    private static function unmakeMask(int $mask): string
    {
        $smask = '';
        if ($mask & Lua::LUA_MASKCALL) {
            $smask .= 'c';
        }
        if ($mask & Lua::LUA_MASKRET) {
            $smask .= 'r';
        }
        if ($mask & Lua::LUA_MASKLINE) {
            $smask .= 'l';
        }
        return $smask;
    }

    // ldblib.c: db_sethook
    private static function sethook(Coroutine $L, array $args): array
    {
        [$L1, $arg] = self::getThread($L, $args);
        if (($args[$arg] ?? null) === null) {  // no hook?
            $hookValue = null;
            $func = null;  // turn off hooks
            $mask = 0;
            $count = 0;
        } else {
            $smask = Auxiliary::checkString($L, $args, $arg + 2);
            Auxiliary::checkType($L, $args, $arg + 1, Lua::LUA_TFUNCTION);
            $count = self::toCInt(Auxiliary::optInteger($L, $args, $arg + 3, 0));
            $hookValue = $args[$arg];
            $func = self::hookFunction();
            $mask = self::makeMask($smask, $count);
        }
        // luaL_getsubtable(L, LUA_REGISTRYINDEX, HOOKKEY)
        $registry = $L->globalState->registry;
        $hookTable = $registry->hash[self::HOOKKEY] ?? null;
        if (!($hookTable instanceof LuaTable)) {
            $hookTable = new LuaTable();
            $registry->hash[self::HOOKKEY] = $hookTable;
            // table just created; initialize it
            $hookTable->hash['__mode'] = 'k';  // hooktable.__mode = "k"
            $hookTable->metatable = $hookTable;  // metatable(hooktable) = hooktable
        }
        self::checkStack($L, $L1, 1);
        $hookTable->set($L1, $hookValue);  // hooktable[L1] = new Lua hook
        Hooks::setHook($L1, $func, $mask, $count);
        return [];
    }

    // ldblib.c: db_gethook
    private static function gethook(Coroutine $L, array $args): array
    {
        [$L1] = self::getThread($L, $args);
        $hook = $L1->hook;
        if ($hook === null) {  // no hook?
            return [null];
        }
        if ($hook !== self::hookFunction()) {  // external hook?
            $first = 'external hook';
        } else {  // hook table must exist
            self::checkStack($L, $L1, 1);
            $first = $L->globalState->registry->hash[self::HOOKKEY]->get($L1);  // 1st result = hooktable[L1]
        }
        return [$first, self::unmakeMask($L1->hookmask), $L1->basehookcount];
    }

    /**
     * ldblib.c: db_debug: read lines from standard input and run each one
     * as a chunk, until end of file or a line "cont".
     */
    private static function debug(Coroutine $L, array $args): array
    {
        while (true) {
            Standalone::flushStdout();
            fwrite(STDERR, 'lua_debug> ');  // lua_writestringerror
            $line = @fgets(STDIN, 250);  // C: fgets(buffer, 250, stdin)
            if ($line === false || $line === "cont\n") {
                return [];
            }
            try {
                $chunk = ChunkLoader::load($L, DebugInfo::cString($line), '=(debug command)', null);  // luaL_loadbuffer
                [$status, $value] = Calls::protectedCall($L, $chunk, []);
            } catch (LuaError $error) {  // syntax error
                $status = $error->status;
                $value = $error->value;
            }
            if ($status !== Lua::LUA_OK) {
                $message = DebugInfo::cString(Auxiliary::toLString($L, $value));
                Standalone::flushStdout();
                fwrite(STDERR, "$message\n");
            }
        }
    }

    /**
     * ldblib.c: db_traceback: a traceback of the running thread (from level
     * 1) or of another thread (from level 0); a message that is neither a
     * string, a number nor nil is returned untouched.
     */
    private static function traceback(Coroutine $L, array $args): array
    {
        [$L1, $arg] = self::getThread($L, $args);
        $message = $args[$arg] ?? null;
        $messageString = LuaObject::toStringCoerced($message);  // lua_tostring
        if ($messageString === null && $message !== null) {  // non-string 'msg'?
            return [$message];  // return it untouched
        }
        $level = self::toCInt(Auxiliary::optInteger($L, $args, $arg + 2, $L === $L1 ? 1 : 0));
        $messageString = $messageString === null ? null : DebugInfo::cString($messageString);
        return [Auxiliary::traceback($L, $L1, $messageString, $level)];
    }

    /** ldblib.c: db_setcstacklimit (lstate.c: lua_setcstacklimit, a no-op in 5.4 returning LUAI_MAXCCALLS) */
    private static function setcstacklimit(Coroutine $L, array $args): array
    {
        Auxiliary::checkInteger($L, $args, 1);
        return [Lua::LUAI_MAXCCALLS];
    }

    // ldblib.c: db_getregistry
    private static function getregistry(Coroutine $L, array $args): array
    {
        return [$L->globalState->registry];
    }

    // ldblib.c: db_getmetatable (lua_getmetatable: the raw metatable, ignoring '__metatable')
    private static function getmetatable(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 1);
        return [MetaMethods::metatableOf($L, $args[0])];  // nil if no metatable
    }

    /**
     * ldblib.c: db_setmetatable with lapi.c: lua_setmetatable: tables and
     * full userdata have their own metatable; any other value sets the
     * metatable shared by all values of its type.
     */
    private static function setmetatable(Coroutine $L, array $args): array
    {
        $metatableType = Auxiliary::argumentType($args, 2);
        Auxiliary::argExpected($L, $metatableType === Lua::LUA_TNIL || $metatableType === Lua::LUA_TTABLE, $args, 2, 'nil or table');
        $value = $args[0];
        $metatable = $args[1];
        if ($value instanceof LuaTable || $value instanceof Userdata) {
            Collector::setMetatable($L, $value, $metatable);
        } elseif ($metatable === null) {
            unset($L->globalState->typeMetatables[LuaObject::type($value)]);
        } else {
            $L->globalState->typeMetatables[LuaObject::type($value)] = $metatable;
        }
        return [$value];  // return 1st argument
    }

    /** C's (int) cast of a lua_Integer (keeps the low 32 bits, signed) */
    private static function toCInt(int $value): int
    {
        return (($value & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
    }

    // ldblib.c: db_getuservalue (lapi.c: lua_getiuservalue)
    private static function getuservalue(Coroutine $L, array $args): array
    {
        $n = self::toCInt(Auxiliary::optInteger($L, $args, 2, 1));
        $userdata = $args[0] ?? null;
        if (!($userdata instanceof Userdata)) {
            return [null];  // fail
        }
        if ($n <= 0 || $n > \count($userdata->userValues)) {
            return [null];  // LUA_TNONE: no such user value
        }
        return [$userdata->userValues[$n - 1], true];
    }

    // ldblib.c: db_setuservalue (lapi.c: lua_setiuservalue)
    private static function setuservalue(Coroutine $L, array $args): array
    {
        $n = self::toCInt(Auxiliary::optInteger($L, $args, 3, 1));
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TUSERDATA);
        Auxiliary::checkAny($L, $args, 2);
        $userdata = $args[0];
        if (!($n >= 1 && $n <= \count($userdata->userValues))) {
            return [null];  // fail: 'n' not in [1, uvalue(o)->nuvalue]
        }
        $userdata->userValues[$n - 1] = $args[1];
        return [$userdata];
    }

    /**
     * lapi.c: aux_upvalue: the name of upvalue $n of function $function
     * ("" for native functions, "(no name)" when stripped), or null.
     */
    private static function upvalueName(mixed $function, int $n): ?string
    {
        if ($function instanceof NativeFunction) {
            return ($n >= 1 && $n <= \count($function->upvalues)) ? '' : null;
        }
        if ($function instanceof LuaClosure) {
            $upvalueDescriptions = $function->proto->upvalues;
            if (!($n >= 1 && $n <= \count($upvalueDescriptions))) {
                return null;  // 'n' not in [1, p->sizeupvalues]
            }
            return $upvalueDescriptions[$n - 1]->name ?? '(no name)';
        }
        return null;  // not a closure
    }

    // ldblib.c: db_getupvalue (auxupvalue with get = 1; lapi.c: lua_getupvalue)
    private static function getupvalue(Coroutine $L, array $args): array
    {
        $n = Auxiliary::checkInteger($L, $args, 2);  // upvalue index
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TFUNCTION);  // closure
        $function = $args[0];
        $name = self::upvalueName($function, $n);
        if ($name === null) {
            return [];
        }
        $value = $function instanceof LuaClosure ? $function->upvals[$n - 1]->v : $function->upvalues[$n - 1];
        return [$name, $value];
    }

    // ldblib.c: db_setupvalue (auxupvalue with get = 0; lapi.c: lua_setupvalue)
    private static function setupvalue(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 3);
        $n = Auxiliary::checkInteger($L, $args, 2);
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TFUNCTION);
        $function = $args[0];
        $name = self::upvalueName($function, $n);
        if ($name === null) {
            return [];
        }
        if ($function instanceof LuaClosure) {
            $function->upvals[$n - 1]->v = $args[2];
        } else {
            $function->upvalues[$n - 1] = $args[2];
        }
        return [$name];
    }

    /**
     * ldblib.c: checkupval with lapi.c: lua_upvalueid: the identity of
     * upvalue $n of the function in argument $functionArgument, or null.
     */
    private static function upvalueIdentity(Coroutine $L, array $args, int $functionArgument, int $indexArgument): ?object
    {
        $n = Auxiliary::checkInteger($L, $args, $indexArgument);  // upvalue index
        Auxiliary::checkType($L, $args, $functionArgument, Lua::LUA_TFUNCTION);  // closure
        $function = $args[$functionArgument - 1];
        if ($function instanceof LuaClosure) {
            return ($n >= 1 && $n <= \count($function->proto->upvalues)) ? $function->upvals[$n - 1] : null;
        }
        if ($n >= 1 && $n <= \count($function->upvalues)) {
            // a C closure's upvalue slot: identify it by (function, index)
            return $function->upvalueSlotIdentity($n);
        }
        return null;  // light C functions have no upvalues
    }

    // ldblib.c: db_upvalueid
    private static function upvalueid(Coroutine $L, array $args): array
    {
        $identity = self::upvalueIdentity($L, $args, 1, 2);
        return [$identity === null ? null : LightUserdata::pointingTo($identity)];
    }

    // ldblib.c: db_upvaluejoin (lapi.c: lua_upvaluejoin)
    private static function upvaluejoin(Coroutine $L, array $args): array
    {
        $n1 = Auxiliary::checkInteger($L, $args, 2);
        Auxiliary::argCheck($L, self::upvalueIdentity($L, $args, 1, 2) !== null, 2, 'invalid upvalue index');
        $n2 = Auxiliary::checkInteger($L, $args, 4);
        Auxiliary::argCheck($L, self::upvalueIdentity($L, $args, 3, 4) !== null, 4, 'invalid upvalue index');
        Auxiliary::argCheck($L, !($args[0] instanceof NativeFunction), 1, 'Lua function expected');
        Auxiliary::argCheck($L, !($args[2] instanceof NativeFunction), 3, 'Lua function expected');
        $args[0]->upvals[$n1 - 1] = $args[2]->upvals[$n2 - 1];
        return [];
    }
}
