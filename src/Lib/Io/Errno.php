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
    public const ECHILD = 10;
    public const EINVAL = 22;
    public const ESPIPE = 29;
    public const EISDIR = 21;

    /** the last error, like C's global 'errno' (0: none) */
    public static int $errno = 0;

    /** @var array<int, string>|null errno => strerror text */
    private static ?array $messages = null;

    /**
     * glibc's strerror() texts of Linux errno values (errno 41 and 58 are
     * unassigned), generated with posix_strerror on glibc 2.44
     */
    private const GLIBC_MESSAGES = [
        1 => 'Operation not permitted',
        2 => 'No such file or directory',
        3 => 'No such process',
        4 => 'Interrupted system call',
        5 => 'Input/output error',
        6 => 'No such device or address',
        7 => 'Argument list too long',
        8 => 'Exec format error',
        9 => 'Bad file descriptor',
        10 => 'No child processes',
        11 => 'Resource temporarily unavailable',
        12 => 'Cannot allocate memory',
        13 => 'Permission denied',
        14 => 'Bad address',
        15 => 'Block device required',
        16 => 'Device or resource busy',
        17 => 'File exists',
        18 => 'Invalid cross-device link',
        19 => 'No such device',
        20 => 'Not a directory',
        21 => 'Is a directory',
        22 => 'Invalid argument',
        23 => 'Too many open files in system',
        24 => 'Too many open files',
        25 => 'Inappropriate ioctl for device',
        26 => 'Text file busy',
        27 => 'File too large',
        28 => 'No space left on device',
        29 => 'Illegal seek',
        30 => 'Read-only file system',
        31 => 'Too many links',
        32 => 'Broken pipe',
        33 => 'Numerical argument out of domain',
        34 => 'Numerical result out of range',
        35 => 'Resource deadlock avoided',
        36 => 'File name too long',
        37 => 'No locks available',
        38 => 'Function not implemented',
        39 => 'Directory not empty',
        40 => 'Too many levels of symbolic links',
        42 => 'No message of desired type',
        43 => 'Identifier removed',
        44 => 'Channel number out of range',
        45 => 'Level 2 not synchronized',
        46 => 'Level 3 halted',
        47 => 'Level 3 reset',
        48 => 'Link number out of range',
        49 => 'Protocol driver not attached',
        50 => 'No CSI structure available',
        51 => 'Level 2 halted',
        52 => 'Invalid exchange',
        53 => 'Invalid request descriptor',
        54 => 'Exchange full',
        55 => 'No anode',
        56 => 'Invalid request code',
        57 => 'Invalid slot',
        59 => 'Bad font file format',
        60 => 'Device not a stream',
        61 => 'No data available',
        62 => 'Timer expired',
        63 => 'Out of streams resources',
        64 => 'Machine is not on the network',
        65 => 'Package not installed',
        66 => 'Object is remote',
        67 => 'Link has been severed',
        68 => 'Advertise error',
        69 => 'Srmount error',
        70 => 'Communication error on send',
        71 => 'Protocol error',
        72 => 'Multihop attempted',
        73 => 'RFS specific error',
        74 => 'Bad message',
        75 => 'Value too large for defined data type',
        76 => 'Name not unique on network',
        77 => 'File descriptor in bad state',
        78 => 'Remote address changed',
        79 => 'Can not access a needed shared library',
        80 => 'Accessing a corrupted shared library',
        81 => '.lib section in a.out corrupted',
        82 => 'Attempting to link in too many shared libraries',
        83 => 'Cannot exec a shared library directly',
        84 => 'Invalid or incomplete multibyte or wide character',
        85 => 'Interrupted system call should be restarted',
        86 => 'Streams pipe error',
        87 => 'Too many users',
        88 => 'Socket operation on non-socket',
        89 => 'Destination address required',
        90 => 'Message too long',
        91 => 'Protocol wrong type for socket',
        92 => 'Protocol not available',
        93 => 'Protocol not supported',
        94 => 'Socket type not supported',
        95 => 'Operation not supported',
        96 => 'Protocol family not supported',
        97 => 'Address family not supported by protocol',
        98 => 'Address already in use',
        99 => 'Cannot assign requested address',
        100 => 'Network is down',
        101 => 'Network is unreachable',
        102 => 'Network dropped connection on reset',
        103 => 'Software caused connection abort',
        104 => 'Connection reset by peer',
        105 => 'No buffer space available',
        106 => 'Transport endpoint is already connected',
        107 => 'Transport endpoint is not connected',
        108 => 'Cannot send after transport endpoint shutdown',
        109 => 'Too many references: cannot splice',
        110 => 'Connection timed out',
        111 => 'Connection refused',
        112 => 'Host is down',
        113 => 'No route to host',
        114 => 'Operation already in progress',
        115 => 'Operation now in progress',
        116 => 'Stale file handle',
        117 => 'Structure needs cleaning',
        118 => 'Not a XENIX named type file',
        119 => 'No XENIX semaphores available',
        120 => 'Is a named type file',
        121 => 'Remote I/O error',
        122 => 'Disk quota exceeded',
        123 => 'No medium found',
        124 => 'Wrong medium type',
        125 => 'Operation canceled',
        126 => 'Required key not available',
        127 => 'Key has expired',
        128 => 'Key has been revoked',
        129 => 'Key was rejected by service',
        130 => 'Owner died',
        131 => 'State not recoverable',
        132 => 'Operation not possible due to RF-kill',
        133 => 'Memory page has hardware error',
    ];

    /**
     * string.h: strerror. posix_strerror is the host C library's text, as
     * in PHP's warnings; hosts may disable it (disable_functions), then
     * glibc's texts stand in.
     */
    public static function strerror(int $errno): string
    {
        if (\function_exists('posix_strerror')) {
            return posix_strerror($errno);
        }
        return self::glibcStrerror($errno);
    }

    /** glibc: strerror (for errno 1 and up) */
    public static function glibcStrerror(int $errno): string
    {
        return self::GLIBC_MESSAGES[$errno] ?? "Unknown error $errno";
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
                self::$messages[$errno] = self::strerror($errno);
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
