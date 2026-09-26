<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * State of a table constructor being parsed; mirrors C 'ConsControl' in
 * lparser.c.
 *
 * @internal
 */
final class ConsControl
{
    /** last list item read */
    public ExpDesc $v;

    /** total number of 'record' elements */
    public int $nh = 0;

    /** number of array elements already stored */
    public int $na = 0;

    /** number of array elements pending to be stored */
    public int $tostore = 0;

    public function __construct(
        /** table descriptor */
        public ExpDesc $t,
    ) {
        $this->v = new ExpDesc();
    }
}
