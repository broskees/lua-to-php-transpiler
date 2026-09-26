<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * The process's standard output, as bin/lua and lua2php scripts use it:
 * PHP's output buffer plays C's stdout buffer (Standalone::configurePhp),
 * and flush() is fflush(stdout) (print flushes after each line, like
 * lua_writeline). Only for command-line programs: flushing ends every
 * output buffer PHP has open.
 *
 * @internal
 */
final class StandardOutput implements OutputSink
{
    public function write(string $bytes): void
    {
        echo $bytes;
    }

    public function flush(): void
    {
        Standalone::flushStdout();
    }
}
