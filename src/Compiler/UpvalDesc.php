<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Description of an upvalue for function prototypes; mirrors C 'Upvaldesc'
 * in lobject.h.
 */
final class UpvalDesc
{
    public function __construct(
        /** upvalue name (debug information); null when stripped */
        public ?string $name,
        /** whether it is in stack (register) of the enclosing function */
        public bool $instack,
        /** index of upvalue (in stack or in outer function's list) */
        public int $idx,
        /** kind of corresponding variable (VDKREG, RDKCONST, ... in lparser.h) */
        public int $kind,
    ) {
    }
}
