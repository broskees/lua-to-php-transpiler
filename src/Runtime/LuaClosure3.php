<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * a Lua closure with exactly 3 upvalues (see LuaClosure)
 *
 * @internal
 */
final class LuaClosure3 extends LuaClosure
{
    public function __construct(
        Proto $proto,
        \Closure $code,
        public UpVal $u0,
        public UpVal $u1,
        public UpVal $u2,
    ) {
        $this->proto = $proto;
        $this->code = $code;
    }
}
