<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A light userdata (C: a bare pointer, LUA_TLIGHTUSERDATA): its type is
 * "userdata", it has no own metatable, and two light userdata are equal
 * when they point to the same thing. The runtime creates at most one
 * LightUserdata per pointed-to object (see pointingTo), so PHP object
 * identity is pointer equality.
 *
 * @internal
 */
final class LightUserdata
{
    /** @var \WeakMap<object, LightUserdata>|null */
    private static ?\WeakMap $instances = null;

    private function __construct(
        public readonly object $pointer,
    ) {
    }

    /** the light userdata for $pointer (the same one every time) */
    public static function pointingTo(object $pointer): self
    {
        self::$instances ??= new \WeakMap();
        return self::$instances[$pointer] ??= new self($pointer);
    }
}
