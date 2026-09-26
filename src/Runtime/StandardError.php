<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * The process's standard error, unbuffered like C's stderr (the default
 * GlobalState::$errorOutput). io.stderr writes to it as a C stream of its
 * own (Lib\Io\CFile), which reports write errors.
 *
 * @internal
 */
final class StandardError implements OutputSink
{
    public function write(string $bytes): void
    {
        fwrite(STDERR, $bytes);
    }
}
