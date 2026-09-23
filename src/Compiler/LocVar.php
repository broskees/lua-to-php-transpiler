<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Description of a local variable for function prototypes (debug
 * information); mirrors C 'LocVar' in lobject.h.
 */
final class LocVar
{
    public function __construct(
        public ?string $varname,
        /** first point where variable is active */
        public int $startpc,
        /** first point where variable is dead */
        public int $endpc,
    ) {
    }
}
