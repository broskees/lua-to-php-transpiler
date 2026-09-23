<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\LightUserdata;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;

/**
 * Port of ldblib.c (the debug library). Phase 1B provides the upvalue
 * functions; the rest (getinfo, getlocal, sethook, ...) is Phase 4.
 */
final class DebugLib
{
    // ldblib.c: luaopen_debug
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'getupvalue' => self::getupvalue(...),
            'setupvalue' => self::setupvalue(...),
            'upvalueid' => self::upvalueid(...),
            'upvaluejoin' => self::upvaluejoin(...),
        ]);
        return $library;
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
