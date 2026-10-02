<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Lib\Io\Errno;
use LuaPhp\Lib\Io\PipeSignal;

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
        self::writeBytes($bytes);
    }

    /**
     * $bytes to the process's stderr, as lauxlib.h's lua_writestringerror
     * and lua.c's messages write them: a write error is ignored, except
     * EPIPE, which ends a command-line process where SIGPIPE would
     * (PipeSignal)
     */
    public static function writeBytes(string $bytes): void
    {
        if (PipeSignal::$killed) {
            return;
        }
        Errno::clearPhpError();
        if (@fwrite(STDERR, $bytes) !== \strlen($bytes)) {
            Errno::setFromPhpError();
            PipeSignal::afterFailedWrite(Errno::$errno);
        }
    }
}
