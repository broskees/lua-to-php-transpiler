<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Runtime\OutputSink;

/**
 * A sandbox's standard output and standard error (print, io.write, warn
 * once "@on", io.stderr: one sink, as the brief has them): collected for
 * Result::$output, or handed to the host's callable (Environment's
 * output). The budget charges every write first (GlobalState::
 * writeOutput), so nothing past outputBytes arrives here.
 *
 * @internal
 */
final class Sink implements OutputSink
{
    /** what the current run wrote, when collecting */
    public string $collected = '';

    /** bytes the current run wrote */
    public int $written = 0;

    public function __construct(
        /** the host's sink (a callable, or StdoutSink::write), or null to collect */
        private readonly ?\Closure $host,
    ) {
    }

    public function write(string $bytes): void
    {
        $this->written += \strlen($bytes);
        if ($this->host === null) {
            $this->collected .= $bytes;
            return;
        }
        ($this->host)($bytes);
    }
}
