<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Io;

/**
 * lauxlib.h: luaL_Stream, the payload of a file handle userdata: the
 * stream and the function that closes it (null marks a closed handle).
 *
 * @internal
 */
final class LuaStream
{
    public ?CFile $file = null;

    /** @var \Closure(\LuaPhp\Runtime\Coroutine, \LuaPhp\Runtime\Userdata): list<mixed>|null */
    public ?\Closure $closef = null;
}
