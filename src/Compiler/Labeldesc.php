<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Description of a pending goto or a label; mirrors C 'Labeldesc' in
 * lparser.h.
 *
 * @internal
 */
final class Labeldesc
{
    /** goto that escapes upvalues */
    public bool $close = false;

    public function __construct(
        /** label identifier */
        public string $name,
        /** position in code */
        public int $pc,
        /** line where it appeared */
        public int $line,
        /** number of active variables in that position */
        public int $nactvar,
    ) {
    }
}
