<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * An output sink for command-line hosts (Environment's output): what the
 * script prints goes to PHP's standard output (echo) as it is printed,
 * through whatever output buffers the host has open (none are flushed or
 * closed). The default sink collects it into Result::$output instead; a
 * host may also pass a callable(string $bytes): void.
 */
final class StdoutSink
{
    public function write(string $bytes): void
    {
        echo $bytes;
    }
}
