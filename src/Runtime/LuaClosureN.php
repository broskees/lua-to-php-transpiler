<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * a Lua closure with no upvalues or more than
 * LuaClosure::MAX_UPVALUE_PROPERTIES of them, in a list (see LuaClosure)
 *
 * @internal
 */
final class LuaClosureN extends LuaClosure
{
    /**
     * @param list<UpVal> $upvals
     */
    public function __construct(
        Proto $proto,
        \Closure $code,
        public array $upvals,
    ) {
        $this->proto = $proto;
        $this->code = $code;
    }

    public function getUpval(int $index): UpVal
    {
        return $this->upvals[$index];
    }

    public function setUpval(int $index, UpVal $upval): void
    {
        $this->upvals[$index] = $upval;
    }
}
