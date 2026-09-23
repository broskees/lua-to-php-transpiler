<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\StringToNumber;
use LuaPhp\Runtime\Vm;

/**
 * Port of lstrlib.c: the string library.
 *
 * Phase 1B provides the string metatable ('__index' = the string table)
 * with the arithmetic metamethods that coerce numeric strings
 * ("10" + 1 == 11). The string functions themselves are Phase 2.
 */
final class StringLib
{
    // lstrlib.c: luaopen_string
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        self::createMetatable($L, $library);
        return $library;
    }

    // lstrlib.c: createmetatable
    private static function createMetatable(Coroutine $L, LuaTable $library): void
    {
        $metatable = new LuaTable();
        $events = [
            '__add' => Vm::LUA_OPADD,
            '__sub' => Vm::LUA_OPSUB,
            '__mul' => Vm::LUA_OPMUL,
            '__mod' => Vm::LUA_OPMOD,
            '__pow' => Vm::LUA_OPPOW,
            '__div' => Vm::LUA_OPDIV,
            '__idiv' => Vm::LUA_OPIDIV,
            '__unm' => Vm::LUA_OPUNM,
        ];
        foreach ($events as $event => $operator) {
            $metatable->hash[$event] = new NativeFunction(
                'arith' . substr($event, 1),
                static fn (Coroutine $L, array $args): array => self::arith($L, $args, $operator, $event),
            );
        }
        $L->globalState->typeMetatables[Lua::LUA_TSTRING] = $metatable;  // set table as metatable for strings
        $metatable->hash['__index'] = $library;  // metatable.__index = string
    }

    /** lstrlib.c: tonum: the number an argument converts to, or null */
    private static function toNumber(mixed $value): int|float|null
    {
        if (\is_int($value) || \is_float($value)) {  // already a number?
            return $value;
        }
        if (\is_string($value)) {  // check whether it is a numerical string
            return StringToNumber::convert($value);
        }
        return null;
    }

    // lstrlib.c: arith
    private static function arith(Coroutine $L, array $args, int $operator, string $event): array
    {
        $first = self::toNumber($args[0] ?? null);
        $second = self::toNumber($args[1] ?? null);
        if ($first !== null && $second !== null) {
            return [Vm::arith($L, $operator, $first, $second)];  // result will be on the top
        }
        // lstrlib.c: trymt
        $metamethodOwner = $args[1] ?? null;
        $metamethod = \is_string($metamethodOwner) ? null : Auxiliary::getMetafield($L, $metamethodOwner, $event);
        if ($metamethod === null) {
            Auxiliary::error($L, 'attempt to ' . substr($event, 2) . " a '" . Auxiliary::argumentTypeName($args, 1)
                . "' with a '" . Auxiliary::argumentTypeName($args, 2) . "'");
        }
        $results = Calls::call($L, $metamethod, [$args[0] ?? null, $args[1] ?? null]);  // call metamethod
        return [$results[0] ?? null];
    }
}
