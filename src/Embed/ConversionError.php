<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * A value that cannot cross between PHP and Lua. $path says where the bad
 * value is, in the notation of the side it comes from: PHP keys for PHP
 * values ("args[0]['when']", "config['db']", "recent()" for what a PHP
 * function returned), Lua keys for Lua values ("result[1].tags[3]" for the
 * third element of field 'tags' of the first value a script returned).
 * getMessage() is "$reason at $path".
 */
final class ConversionError extends \RuntimeException
{
    /** @internal */
    public function __construct(
        public readonly string $reason,
        public readonly string $path,
    ) {
        parent::__construct($path === '' ? $reason : "$reason at $path");
    }
}
