<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * A Lua function value (C: LClosure in lobject.h).
 *
 * $code is the PHP closure the emitter generated for $proto; every Lua
 * closure of the same Proto shares it. Calling convention (see AGENTS.md,
 * "Runtime conventions"):
 *
 *     ($closure->code)(Coroutine $L, LuaClosure $closure, array $arguments, int $callstatus = 0): ?array
 *
 * returns the list of results, or null when the function ended in a tail
 * call that the caller must finish (Calls::finishTailCall).
 */
final class LuaClosure
{
    /**
     * @param list<UpVal> $upvals
     */
    public function __construct(
        public readonly Proto $proto,
        public readonly \Closure $code,
        public array $upvals,
    ) {
    }
}
