<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/** a Lua closure with exactly 2 upvalues (see LuaClosure) */
final class LuaClosure2 extends LuaClosure
{
    public function __construct(
        Proto $proto,
        \Closure $code,
        public UpVal $u0,
        public UpVal $u1,
    ) {
        $this->proto = $proto;
        $this->code = $code;
    }
}
