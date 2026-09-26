<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * a Lua closure with exactly 1 upvalue (see LuaClosure)
 *
 * @internal
 */
final class LuaClosure1 extends LuaClosure
{
    public function __construct(
        Proto $proto,
        \Closure $code,
        public UpVal $u0,
    ) {
        $this->proto = $proto;
        $this->code = $code;
    }
}
