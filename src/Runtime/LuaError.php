<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A Lua error in flight (C: luaD_throw). $value is the Lua error object
 * (any Lua value); $status is Lua::LUA_ERRRUN, LUA_ERRSYNTAX, LUA_ERRMEM
 * or LUA_ERRERR. Only LUA_ERRRUN errors go through the message handler of
 * xpcall (ldebug.c: luaG_errormsg).
 *
 * When a LuaError is thrown, the thread's CallInfo chain is left as it was
 * at the raise point; the catcher (a protected call) runs the message
 * handler on top of it, then unwinds (see Calls::protectedCall).
 *
 * @internal
 */
final class LuaError extends \Exception
{
    public function __construct(
        public mixed $value,
        public int $status = Lua::LUA_ERRRUN,
    ) {
        parent::__construct(\is_string($value) ? $value : 'Lua error object (' . get_debug_type($value) . ')');
    }

    /**
     * lapi.c: lua_error, how C functions raise $value: a memory error when
     * it is the memory error message (C compares it with the interned
     * short string G(L)->memerrmsg), else a regular error, which goes
     * through the message handler.
     */
    public static function raise(mixed $value): never
    {
        throw new self($value, $value === Lua::MEMERRMSG ? Lua::LUA_ERRMEM : Lua::LUA_ERRRUN);
    }
}
