<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Io;

/**
 * C's 'errno' for the io and os libraries, with strerror() and the result
 * shapes of lauxlib.c's luaL_fileresult and luaL_execresult.
 *
 * PHP does not expose errno: failed PHP file functions report the C
 * library's strerror() text in their warning ("unlink(x): No such file or
 * directory"), so the number is recovered from that text.
 */
final class Errno
{
    // errno.h (Linux)
    public const EBADF = 9;
    public const ENOENT = 2;
    public const EINVAL = 22;
    public const ESPIPE = 29;
    public const EISDIR = 21;

    /** the last error, like C's global 'errno' (0: none) */
    public static int $errno = 0;

    /** @var array<int, string>|null errno => strerror text */
    private static ?array $messages = null;

    /** string.h: strerror */
    public static function strerror(int $errno): string
    {
        return posix_strerror($errno);
    }

    /**
     * Set errno from the warning of the PHP function that just failed
     * (see clearPhpError()); unknown messages give $default.
     */
    public static function setFromPhpError(int $default = 0): void
    {
        $message = error_get_last()['message'] ?? '';
        self::$errno = self::fromMessage($message) ?? $default;
    }

    /** forget PHP's last warning before calling a PHP function that may fail */
    public static function clearPhpError(): void
    {
        error_clear_last();
    }

    /** the errno whose strerror() text ends $message (the longest such text) */
    public static function fromMessage(string $message): ?int
    {
        if (self::$messages === null) {
            self::$messages = [];
            for ($errno = 1; $errno < 134; $errno++) {
                self::$messages[$errno] = posix_strerror($errno);
            }
        }
        $found = null;
        $foundLength = 0;
        foreach (self::$messages as $errno => $text) {
            if (\strlen($text) > $foundLength && str_ends_with($message, $text)) {
                $found = $errno;
                $foundLength = \strlen($text);
            }
        }
        return $found;
    }

    /**
     * lauxlib.c: luaL_fileresult: true, or fail plus "filename: message"
     * (or just the message) plus errno.
     *
     * @return list<mixed>
     */
    public static function fileResult(bool $ok, ?string $filename): array
    {
        $errno = self::$errno;
        if ($ok) {
            return [true];
        }
        $message = $errno !== 0 ? self::strerror($errno) : '(no extra info)';
        if ($filename !== null) {
            $message = $filename . ': ' . $message;
        }
        return [null, $message, $errno];
    }

    /**
     * lauxlib.c: luaL_execresult for a finished child process: $what is
     * "exit" (with the exit status) or "signal" (with the signal number);
     * null when waiting failed (errno says why).
     *
     * @param array{string, int}|null $termination
     * @return list<mixed>
     */
    public static function execResult(?array $termination): array
    {
        if ($termination === null && self::$errno !== 0) {  // error with an 'errno'?
            return self::fileResult(false, null);
        }
        [$what, $status] = $termination ?? ['exit', -1];  // l_inspectstat
        if ($what === 'exit' && $status === 0) {  // successful termination?
            return [true, $what, $status];
        }
        return [null, $what, $status];
    }
}
