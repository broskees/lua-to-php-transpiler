<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * PHP warnings and notices never reach Lua's output: while Lua code runs
 * they are exceptions (\ErrorException, which no pcall catches: a crash
 * that shows the bug), except where PHP code silences them with '@'.
 * bin/lua installs the handler for the whole process
 * (Standalone::configurePhp); code that embeds the runtime wraps each call
 * into Lua with call(), which leaves the host's handler as it was.
 *
 * @internal
 */
final class PhpErrors
{
    /**
     * Runs $body with the handler installed and restores the previous
     * handler afterwards, also when $body throws. Calls nest: a PHP
     * function that Lua calls may start another call() (say, for another
     * state), and each restores what it found.
     */
    public static function call(callable $body): mixed
    {
        set_error_handler(self::throwAsException(...));
        try {
            return $body();
        } finally {
            restore_error_handler();
        }
    }

    /** the handler: a PHP warning or notice becomes an \ErrorException, unless silenced with '@' */
    public static function throwAsException(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;  // silenced with @
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
}
