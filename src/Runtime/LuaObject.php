<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Value helpers from lobject.c/lobject.h and lapi.c: type tags, type
 * names, number-to-string conversion and object "addresses".
 *
 * @internal
 */
final class LuaObject
{
    /** lapi.c: lua_type (Lua::LUA_T*) */
    public static function type(mixed $value): int
    {
        if ($value === null) {
            return Lua::LUA_TNIL;
        }
        if (\is_bool($value)) {
            return Lua::LUA_TBOOLEAN;
        }
        if (\is_int($value) || \is_float($value)) {
            return Lua::LUA_TNUMBER;
        }
        if (\is_string($value)) {
            return Lua::LUA_TSTRING;
        }
        if ($value instanceof LuaTable) {
            return Lua::LUA_TTABLE;
        }
        if ($value instanceof LuaClosure || $value instanceof NativeFunction) {
            return Lua::LUA_TFUNCTION;
        }
        if ($value instanceof Userdata) {
            return Lua::LUA_TUSERDATA;
        }
        if ($value instanceof Coroutine) {
            return Lua::LUA_TTHREAD;
        }
        if ($value instanceof LightUserdata) {
            return Lua::LUA_TLIGHTUSERDATA;
        }
        throw new \LogicException('not a Lua value: ' . get_debug_type($value));
    }

    /** ltm.h: ttypename (lua_typename of lua_type) */
    public static function typeName(mixed $value): string
    {
        return Lua::TYPE_NAMES[self::type($value) + 1];
    }

    /**
     * lobject.c: tostringbuff / luaO_tostring: integers with "%d", floats
     * with "%.14g" (plus ".0" when the result looks like an integer).
     */
    public static function numberToString(int|float $number): string
    {
        if (\is_int($number)) {
            return (string) $number;
        }
        return NumberFormat::luaNumberToString($number);
    }

    /**
     * lapi.c: lua_tolstring: strings as they are, numbers converted
     * (lvm.h: cvt2str), anything else null.
     */
    public static function toStringCoerced(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return self::numberToString($value);
        }
        return null;
    }

    /**
     * lapi.c: lua_topointer printed with "%p" (lobject.c: lua_pointer2str):
     * a stable, unique-while-alive fake address for an object.
     */
    public static function address(object $object): string
    {
        if ($object instanceof LightUserdata) {
            $object = $object->pointer;
        }
        return sprintf('0x5555%08x', spl_object_id($object) * 16);
    }
}
