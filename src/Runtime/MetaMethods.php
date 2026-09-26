<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Port of ltm.c: tag methods (metamethods).
 *
 * @internal
 */
final class MetaMethods
{
    // ltm.h: enum TMS ("ORDER TM")
    public const TM_INDEX = 0;
    public const TM_NEWINDEX = 1;
    public const TM_GC = 2;
    public const TM_MODE = 3;
    public const TM_LEN = 4;
    public const TM_EQ = 5;
    public const TM_ADD = 6;
    public const TM_SUB = 7;
    public const TM_MUL = 8;
    public const TM_MOD = 9;
    public const TM_POW = 10;
    public const TM_DIV = 11;
    public const TM_IDIV = 12;
    public const TM_BAND = 13;
    public const TM_BOR = 14;
    public const TM_BXOR = 15;
    public const TM_SHL = 16;
    public const TM_SHR = 17;
    public const TM_UNM = 18;
    public const TM_BNOT = 19;
    public const TM_LT = 20;
    public const TM_LE = 21;
    public const TM_CONCAT = 22;
    public const TM_CALL = 23;
    public const TM_CLOSE = 24;

    // ltm.c: luaT_eventname ("ORDER TM")
    public const EVENT_NAMES = [
        '__index', '__newindex',
        '__gc', '__mode', '__len', '__eq',
        '__add', '__sub', '__mul', '__mod', '__pow',
        '__div', '__idiv',
        '__band', '__bor', '__bxor', '__shl', '__shr',
        '__unm', '__bnot', '__lt', '__le',
        '__concat', '__call', '__close',
    ];

    /**
     * The metatable of any value (lapi.c: lua_getmetatable): tables and
     * userdata have their own, other types share one per type.
     */
    public static function metatableOf(Coroutine $L, mixed $value): ?LuaTable
    {
        if ($value instanceof LuaTable || $value instanceof Userdata) {
            return $value->metatable;
        }
        return $L->globalState->typeMetatables[LuaObject::type($value)] ?? null;
    }

    // ltm.c: luaT_gettmbyobj
    public static function getByObject(Coroutine $L, mixed $value, int $event): mixed
    {
        $metatable = self::metatableOf($L, $value);
        return $metatable === null ? null : ($metatable->hash[self::EVENT_NAMES[$event]] ?? null);
    }

    /**
     * ltm.c: luaT_objtypename. The name of the type of a value; tables and
     * userdata with a string '__name' metafield use it.
     */
    public static function objectTypeName(Coroutine $L, mixed $value): string
    {
        if ($value instanceof LuaTable || $value instanceof Userdata) {
            $name = $value->metatable?->hash['__name'] ?? null;
            if (\is_string($name)) {
                return $name;
            }
        }
        return LuaObject::typeName($value);
    }

    /** ltm.c: luaT_callTMres: call metamethod $f(p1, p2) and return its first result */
    public static function callTMres(Coroutine $L, mixed $f, mixed $p1, mixed $p2): mixed
    {
        // metamethod may yield only when called from Lua code
        if ($L->ci->func instanceof LuaClosure) {
            $results = Calls::call($L, $f, [$p1, $p2]);
        } else {
            $results = Calls::callNoYield($L, $f, [$p1, $p2]);
        }
        return $results[0] ?? null;
    }

    /** ltm.c: luaT_callTM: call metamethod $f(p1, p2, p3), no results */
    public static function callTM(Coroutine $L, mixed $f, mixed $p1, mixed $p2, mixed $p3): void
    {
        if ($L->ci->func instanceof LuaClosure) {
            Calls::call($L, $f, [$p1, $p2, $p3]);
        } else {
            Calls::callNoYield($L, $f, [$p1, $p2, $p3]);
        }
    }

    /**
     * ltm.c: callbinTM. Returns [found, result].
     *
     * @return array{bool, mixed}
     */
    private static function callBinTM(Coroutine $L, mixed $p1, mixed $p2, int $event): array
    {
        $tm = self::getByObject($L, $p1, $event);  // try first operand
        if ($tm === null) {
            $tm = self::getByObject($L, $p2, $event);  // try second operand
        }
        if ($tm === null) {
            return [false, null];
        }
        return [true, self::callTMres($L, $tm, $p1, $p2)];
    }

    /** ltm.c: luaT_trybinTM; returns the metamethod's result */
    public static function tryBinTM(Coroutine $L, mixed $p1, mixed $p2, int $event, int $slot1, int $slot2): mixed
    {
        [$found, $result] = self::callBinTM($L, $p1, $p2, $event);
        if ($found) {
            return $result;
        }
        switch ($event) {
            case self::TM_BAND:
            case self::TM_BOR:
            case self::TM_BXOR:
            case self::TM_SHL:
            case self::TM_SHR:
            case self::TM_BNOT:
                if ((\is_int($p1) || \is_float($p1)) && (\is_int($p2) || \is_float($p2))) {
                    DebugInfo::toIntError($L, $p1, $p2, $slot1, $slot2);
                }
                DebugInfo::opIntError($L, $p1, $p2, 'perform bitwise operation on', $slot1, $slot2);
                // no break: calls never return
            default:
                DebugInfo::opIntError($L, $p1, $p2, 'perform arithmetic on', $slot1, $slot2);
        }
    }

    /** ltm.c: luaT_tryconcatTM; returns the metamethod's result */
    public static function tryConcatTM(Coroutine $L, mixed $p1, mixed $p2, int $slot1, int $slot2): mixed
    {
        [$found, $result] = self::callBinTM($L, $p1, $p2, self::TM_CONCAT);
        if (!$found) {
            DebugInfo::concatError($L, $p1, $p2, $slot1, $slot2);
        }
        return $result;
    }

    /** ltm.c: luaT_trybinassocTM ($slot1 belongs to $p1, the register operand) */
    public static function tryBinAssocTM(Coroutine $L, mixed $p1, mixed $p2, bool $flip, int $event, int $slot1): mixed
    {
        if ($flip) {
            return self::tryBinTM($L, $p2, $p1, $event, DebugInfo::NO_SLOT, $slot1);
        }
        return self::tryBinTM($L, $p1, $p2, $event, $slot1, DebugInfo::NO_SLOT);
    }

    // ltm.c: luaT_trybiniTM
    public static function tryBinITM(Coroutine $L, mixed $p1, int $i2, bool $flip, int $event, int $slot1): mixed
    {
        return self::tryBinAssocTM($L, $p1, $i2, $flip, $event, $slot1);
    }

    /**
     * ltm.c: luaT_callorderTM. The reference build defines LUA_COMPAT_5_3,
     * hence LUA_COMPAT_LT_LE: without '__le', 'a <= b' is 'not (b < a)'.
     */
    public static function callOrderTM(Coroutine $L, mixed $p1, mixed $p2, int $event): bool
    {
        [$found, $result] = self::callBinTM($L, $p1, $p2, $event);  // try original event
        if ($found) {
            return $result !== null && $result !== false;
        }
        if ($event === self::TM_LE) {
            // try '!(p2 < p1)' for '(p1 <= p2)'
            $L->ci->callstatus |= Lua::CIST_LEQ;  // mark it is doing 'lt' for 'le'
            [$found, $result] = self::callBinTM($L, $p2, $p1, self::TM_LT);
            if ($found) {
                $L->ci->callstatus ^= Lua::CIST_LEQ;  // clear mark
                return $result === null || $result === false;
            }
            // else error will remove this 'ci'; no need to clear mark
        }
        DebugInfo::orderError($L, $p1, $p2);  // no metamethod found
    }

    // ltm.c: luaT_callorderiTM
    public static function callOrderITM(Coroutine $L, mixed $p1, int $v2, bool $flip, bool $isFloat, int $event): bool
    {
        $aux = $isFloat ? (float) $v2 : $v2;
        if ($flip) {  // arguments were exchanged?
            return self::callOrderTM($L, $aux, $p1, $event);
        }
        return self::callOrderTM($L, $p1, $aux, $event);
    }
}
