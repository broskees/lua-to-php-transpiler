<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\LightUserdata;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MetaMethods;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Userdata;

/**
 * Port of ldblib.c (the debug library). Provided so far: the upvalue,
 * metatable, registry and user value functions; the rest (getinfo,
 * getlocal, sethook, ...) is Phase 4.
 */
final class DebugLib
{
    // ldblib.c: luaopen_debug
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'getuservalue' => self::getuservalue(...),
            'getregistry' => self::getregistry(...),
            'getmetatable' => self::getmetatable(...),
            'getupvalue' => self::getupvalue(...),
            'setmetatable' => self::setmetatable(...),
            'setupvalue' => self::setupvalue(...),
            'setuservalue' => self::setuservalue(...),
            'upvalueid' => self::upvalueid(...),
            'upvaluejoin' => self::upvaluejoin(...),
        ]);
        return $library;
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
            $value->metatable = $metatable;
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
