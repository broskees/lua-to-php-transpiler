<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * One variable in the left-hand side of an assignment, chained to the
 * previous ones; mirrors C 'struct LHS_assign' in lparser.c.
 */
final class LhsAssign
{
    /** variable (global, local, upvalue, or indexed) */
    public ExpDesc $v;

    public function __construct(
        public ?LhsAssign $prev,
    ) {
        $this->v = new ExpDesc();
    }
}
