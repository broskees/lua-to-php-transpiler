<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * What a script run returns: its return values converted to PHP (a list),
 * what it printed, and what it used.
 */
final class Result
{
    /** @param list<mixed> $values */
    public function __construct(
        public readonly array $values,
        public readonly string $output,
        public readonly Usage $usage,
    ) {
    }
}
