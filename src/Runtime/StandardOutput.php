<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Lib\Io\CFile;

/**
 * The process's standard output, as bin/lua and lua2php scripts use it:
 * C's stdout, the one stream with glibc's buffering that print and every
 * such state's io.stdout share (Lib\Io\CFile::standardOutput). write()
 * is lauxlib.h's lua_writestring and flush() fflush(stdout) (print
 * flushes after each line, like lua_writeline); like their C callers,
 * they ignore write errors (io.write and io.stdout report them).
 *
 * @internal
 */
final class StandardOutput implements OutputSink
{
    public function write(string $bytes): void
    {
        CFile::standardOutput()->write($bytes);
    }

    public function flush(): void
    {
        CFile::standardOutput()->flush();
    }
}
