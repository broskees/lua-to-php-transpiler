<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/** Several return values of a PHP function called from Lua: see Lua::multiple(). */
final class Multiple
{
    /** @param list<mixed> $values */
    public function __construct(
        public readonly array $values,
    ) {
    }
}
