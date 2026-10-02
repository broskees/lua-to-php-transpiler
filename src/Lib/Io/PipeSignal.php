<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Io;

/**
 * SIGPIPE for the command line (bin/lua and the scripts bin/lua2php
 * generates): they behave as C programs started from a shell, whose
 * SIGPIPE has its default disposition. A write to a pipe or FIFO that
 * nobody reads any more kills such a process at that write: `lua5.4
 * script.lua | head -1` ends with status 141 (128 + SIGPIPE), the stdio
 * buffers are lost and no Lua code runs (no __close, no __gc).
 *
 * PHP's command line ignores SIGPIPE (php_cli.c), so
 * useDefaultDisposition() (Standalone::configurePhp) restores the default
 * with pcntl when the host has it: the kernel then kills the process as it
 * kills lua5.4. Without pcntl (hosts may disable it) the signal is
 * emulated: a write that fails with EPIPE ends the process with exit
 * status 141 (what a shell shows for the signal too), after which nothing
 * is written (CFile::writeOut, PHP's shutdown flush of stdout). Embedded
 * states never ask for this: the host's process keeps its disposition, and
 * EPIPE stays an error Lua sees.
 *
 * @internal
 */
final class PipeSignal
{
    // signal.h (Linux): SIGPIPE
    private const SIGPIPE = 13;

    /** a write that fails with EPIPE ends the process (no pcntl to restore SIGPIPE's default) */
    private static bool $emulated = false;

    /** the process is ending as SIGPIPE would end it: nothing more is written */
    public static bool $killed = false;

    /** SIGPIPE's default disposition for this process: with pcntl the real one, else its emulation */
    public static function useDefaultDisposition(): void
    {
        // (pcntl defines SIGPIPE and SIG_DFL; a disabled pcntl_signal does not exist)
        if (\function_exists('pcntl_signal') && pcntl_signal(SIGPIPE, SIG_DFL)) {
            return;
        }
        self::$emulated = true;
    }

    /** after a write failed with C's errno $errno: the end of the process where SIGPIPE would end it */
    public static function afterFailedWrite(int $errno): void
    {
        if (!self::$emulated || $errno !== Errno::EPIPE) {
            return;
        }
        self::$killed = true;
        exit(128 + self::SIGPIPE);  // (no finally block runs; destructors and the shutdown flush write nothing)
    }
}
