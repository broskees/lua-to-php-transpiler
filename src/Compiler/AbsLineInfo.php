<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Absolute line for a given instruction; mirrors C 'AbsLineInfo' in
 * lobject.h. Used when a line delta does not fit in a signed byte, and
 * periodically (every MAXIWTHABS instructions) to speed up line lookups.
 */
final class AbsLineInfo
{
    public function __construct(
        public int $pc,
        public int $line,
    ) {
    }
}
