<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Io;

use LuaPhp\Runtime\MemoryLimit;
use LuaPhp\Runtime\Standalone;

/**
 * A C stdio stream (FILE *) as glibc implements it, over a PHP stream:
 * what liolib.c and loslib.c need from <stdio.h>.
 *
 * Why not PHP streams alone: Lua programs can observe stdio buffering
 * (a second handle does not see unflushed output; setvbuf changes that),
 * ungetc, the error indicator and errno. So this class keeps its own
 * output buffer (glibc's rules: block-sized, "line" flushes through the
 * last '\n', "no" writes through) and a pushback buffer for ungetc; reads
 * go through PHP's stream (its read buffer plays the role of glibc's).
 *
 * Standard output is special: print() and io.write share C's stdout
 * buffer, so this stream writes through PHP's output buffer, like print
 * (see Standalone::configurePhp and flushStdout).
 *
 * Every failing operation sets Errno::$errno and the error indicator.
 */
final class CFile
{
    // stdio.h: buffering modes
    public const IOFBF = 0;
    public const IOLBF = 1;
    public const IONBF = 2;

    // stdio.h: BUFSIZ
    private const BUFSIZ = 8192;

    /** largest read request honoured; C's buffer allocation fails beyond memory ("not enough memory") */
    public const MAX_READ_REQUEST = 1 << 46;

    /** reads take at most this many bytes from the stream at once */
    private const READ_PIECE = 65536;

    /** lines are read in pieces of at most this many bytes (fgets allocates that much for every call) */
    private const LINE_PIECE = 1024;

    /** @var \WeakMap<CFile, true>|null open streams, for fflush(NULL) */
    private static ?\WeakMap $openFiles = null;

    /** C: ferror */
    public bool $error = false;

    private string $writeBuffer = '';

    /** bytes pushed back by ungetc, read before the stream */
    private string $pushback = '';

    private int $bufferMode;

    /** size of the stdio buffer; null until the first write allocates it (1 when unbuffered) */
    private ?int $bufferSize = null;

    /** C: _IO_CURRENTLY_PUTTING (the last operation was a write) */
    private bool $putting = false;

    /**
     * C: _IO_write_end == _IO_write_ptr, as glibc leaves it when the
     * buffer was last set up for line or no buffering: a fully buffered
     * write then sees no space and flushes first.
     */
    private bool $writeEndPinned = true;

    /** append mode wrote since the last seek: the real position is the end of file */
    private bool $atEndAfterAppend = false;

    /** a directory opened for reading: C's fopen succeeds, every read fails (EISDIR) */
    private bool $isDirectory = false;

    private bool $closed = false;

    /**
     * @param resource $stream
     * @param resource|null $process popen: the child process (proc_open)
     */
    private function __construct(
        private $stream,
        private readonly bool $readable,
        private readonly bool $writable,
        private readonly bool $ownsStream,
        private readonly bool $isStdout = false,
        private $process = null,
        private readonly bool $append = false,
    ) {
        $this->bufferMode = self::IOFBF;
        self::$openFiles ??= new \WeakMap();
        self::$openFiles[$this] = true;
    }

    /** glibc _IO_file_doallocate: st_blksize if smaller than BUFSIZ */
    private static function defaultBufferSize($stream): int
    {
        $status = @fstat($stream);
        $blockSize = \is_array($status) ? ($status['blksize'] ?? -1) : -1;
        return ($blockSize > 0 && $blockSize < self::BUFSIZ) ? $blockSize : self::BUFSIZ;
    }

    /**
     * A file name as a plain file path for PHP: PHP would treat
     * "scheme://..." (and "data:") as a stream wrapper URL.
     */
    public static function plainPath(string $filename): string
    {
        if (preg_match('~^([a-zA-Z0-9+.-]+://|data:)~i', $filename) === 1) {
            return './' . $filename;
        }
        return $filename;
    }

    /** stdio.h: fopen ($mode already validated by the caller) */
    public static function open(string $filename, string $mode): ?self
    {
        if ($filename === '') {
            Errno::$errno = Errno::ENOENT;
            return null;
        }
        Errno::clearPhpError();
        $stream = @fopen(self::plainPath($filename), $mode);
        if ($stream === false) {
            Errno::setFromPhpError(Errno::ENOENT);
            return null;
        }
        $update = str_contains($mode, '+');
        $file = new self($stream, $mode[0] === 'r' || $update, $mode[0] !== 'r' || $update, true, false, null, $mode[0] === 'a');
        $status = @fstat($stream);
        $file->isDirectory = \is_array($status) && ($status['mode'] & 0170000) === 0040000;
        if ($mode[0] === 'a' && !$update) {  // glibc: write-only append streams start at the end
            @fseek($stream, 0, SEEK_END);
        }
        return $file;
    }

    /** stdio.h: tmpfile */
    public static function tmpfile(): ?self
    {
        Errno::clearPhpError();
        $stream = @tmpfile();
        if ($stream === false) {
            Errno::setFromPhpError();
            return null;
        }
        return new self($stream, true, true, true);
    }

    /** stdio.h: stdin, stdout, stderr (stderr is unbuffered) */
    public static function standard(int $fd): self
    {
        return match ($fd) {
            0 => new self(STDIN, true, false, false),
            1 => new self(STDOUT, false, true, false, true),
            default => (new self(STDERR, false, true, false))->unbuffered(),
        };
    }

    /** glibc: stderr is unbuffered (a one-byte buffer) */
    private function unbuffered(): self
    {
        $this->bufferMode = self::IONBF;
        $this->bufferSize = 1;
        return $this;
    }

    /**
     * stdio.h: popen: run $command with /bin/sh, reading its standard
     * output ($mode "r") or writing its standard input ("w").
     */
    public static function popen(string $command, string $mode): ?self
    {
        self::flushAll();  // liolib.c: l_popen does fflush(NULL) first
        // the other standard descriptors are inherited: passing PHP's STDOUT
        // would make PHP seek descriptor 1 to that stream's own idea of its position
        $descriptors = $mode === 'r' ? [1 => ['pipe', 'w']] : [0 => ['pipe', 'r']];
        Errno::clearPhpError();
        $process = @proc_open($command, $descriptors, $pipes);
        if ($process === false) {
            Errno::setFromPhpError();
            return null;
        }
        $stream = $mode === 'r' ? $pipes[1] : $pipes[0];
        return new self($stream, $mode === 'r', $mode === 'w', true, false, $process);
    }

    /** stdio.h: fflush(NULL): flush every output stream, standard output included */
    public static function flushAll(): void
    {
        foreach (self::$openFiles ?? [] as $file => $unused) {
            if ($file->writeBuffer !== '') {
                $file->flush();
            }
        }
        Standalone::flushStdout();
    }

    /** stdio.h: clearerr */
    public function clearError(): void
    {
        $this->error = false;
    }

    /** a read on a stream not open for reading: EBADF, like glibc */
    private function checkReadable(): bool
    {
        if (!$this->readable) {
            Errno::$errno = Errno::EBADF;
            $this->error = true;
            return false;
        }
        if ($this->isDirectory) {
            Errno::$errno = Errno::EISDIR;
            $this->error = true;
            return false;
        }
        if ($this->writeBuffer !== '' && !$this->flush()) {  // glibc: switch to get mode
            return false;
        }
        $this->putting = false;
        return true;
    }

    /** the error of a failed PHP read, if any (end of file is no error) */
    private function readFailed(): bool
    {
        if (error_get_last() === null) {
            return false;
        }
        Errno::setFromPhpError();
        $this->error = true;
        return true;
    }

    /** stdio.h: getc: a byte, or -1 at end of file or on error */
    public function getc(): int
    {
        if ($this->pushback !== '') {
            $byte = \ord($this->pushback[0]);
            $this->pushback = substr($this->pushback, 1);
            return $byte;
        }
        if (!$this->checkReadable()) {
            return -1;
        }
        Errno::clearPhpError();
        $character = @fgetc($this->stream);
        if ($character === false) {
            $this->readFailed();
            return -1;
        }
        return \ord($character);
    }

    /** stdio.h: ungetc (no-op for end of file) */
    public function ungetc(int $byte): void
    {
        if ($byte >= 0) {
            $this->pushback = \chr($byte) . $this->pushback;
        }
    }

    /**
     * The next line including its '\n' (the rest of the file if there is
     * no more '\n'; "" at end of file or on error).
     */
    public function readLine(): string
    {
        $line = '';
        if ($this->pushback !== '') {
            $newlinePosition = strpos($this->pushback, "\n");
            if ($newlinePosition !== false) {
                $line = substr($this->pushback, 0, $newlinePosition + 1);
                $this->pushback = substr($this->pushback, $newlinePosition + 1);
                return $line;
            }
            $line = $this->pushback;
            $this->pushback = '';
        }
        if (!$this->checkReadable()) {
            return $line;
        }
        if (@feof($this->stream) && (stream_get_meta_data($this->stream)['seekable'] ?? false)) {
            @fseek($this->stream, 0, SEEK_CUR);  // PHP's fgets stops at a remembered end of file; C reads again
        }
        $capacity = MemoryLimit::CHECK_ABOVE;  // $line may grow to this length before the next check
        while (true) {
            Errno::clearPhpError();
            $piece = @fgets($this->stream, self::LINE_PIECE + 1);  // (a whole line could be any size)
            if ($piece === false) {
                $this->readFailed();
                return $line;
            }
            if (\strlen($line) + \strlen($piece) > $capacity) {
                $capacity = MemoryLimit::grow(\strlen($line) + \strlen($piece));
            }
            $line .= $piece;
            if (str_ends_with($piece, "\n")) {
                return $line;
            }
        }
    }

    /** stdio.h: fread of up to $count bytes (fewer only at end of file or on error) */
    public function read(int $count): string
    {
        $data = substr($this->pushback, 0, $count);
        $this->pushback = substr($this->pushback, \strlen($data));
        if (\strlen($data) === $count || !$this->checkReadable()) {
            return $data;
        }
        $capacity = MemoryLimit::CHECK_ABOVE;  // $data may grow to this length before the next check
        while (\strlen($data) < $count) {
            Errno::clearPhpError();
            $piece = @fread($this->stream, min($count - \strlen($data), self::READ_PIECE));
            if ($piece === false || $piece === '') {
                $this->readFailed();
                break;
            }
            if (\strlen($data) + \strlen($piece) > $capacity) {
                $capacity = MemoryLimit::grow(\strlen($data) + \strlen($piece));
            }
            $data .= $piece;
        }
        return $data;
    }

    /** everything up to end of file */
    public function readAll(): string
    {
        $data = $this->pushback;
        $this->pushback = '';
        if (!$this->checkReadable()) {
            return $data;
        }
        $capacity = MemoryLimit::CHECK_ABOVE;  // $data may grow to this length before the next check
        while (true) {
            Errno::clearPhpError();
            $piece = @fread($this->stream, self::READ_PIECE);
            if ($piece === false || $piece === '') {
                $this->readFailed();
                return $data;
            }
            if (\strlen($data) + \strlen($piece) > $capacity) {
                $capacity = MemoryLimit::grow(\strlen($data) + \strlen($piece));
            }
            $data .= $piece;
        }
    }

    /**
     * stdio.h: fwrite of all of $data, buffered like glibc
     * (_IO_new_file_xsputn). False on error.
     */
    public function write(string $data): bool
    {
        if (!$this->writable) {
            Errno::$errno = Errno::EBADF;
            $this->error = true;
            return false;
        }
        if ($this->pushback !== '') {  // glibc: switch to put mode at the logical position
            $unread = \strlen($this->pushback);
            $this->pushback = '';
            @fseek($this->stream, -$unread, SEEK_CUR);
        }
        if ($this->isStdout) {
            return $this->writeStdout($data);
        }
        return $this->putString($data);
    }

    /**
     * glibc _IO_new_file_xsputn: put $data through the buffer. Line
     * buffering flushes through the last '\n' when $data fits the
     * buffer; data that does not fit fills the buffer, which is flushed,
     * then whole blocks are written directly (all of it with a buffer
     * under 128 bytes, as when unbuffered) and the rest is buffered.
     */
    private function putString(string $data): bool
    {
        $toDo = \strlen($data);
        if ($toDo === 0) {
            return true;
        }
        $mustFlush = false;
        $count = 0;
        if ($this->bufferMode === self::IOLBF && $this->putting) {
            $count = $this->bufferSize - \strlen($this->writeBuffer);
            if ($count >= $toDo) {
                $newlinePosition = strrpos($data, "\n");
                if ($newlinePosition !== false) {
                    $count = $newlinePosition + 1;
                    $mustFlush = true;
                }
            }
        } elseif ($this->bufferMode === self::IOFBF && $this->putting && !$this->writeEndPinned) {
            $count = $this->bufferSize - \strlen($this->writeBuffer);  // space available
        }
        $position = 0;
        if ($count > 0) {  // first fill the buffer
            $count = min($count, $toDo);
            $this->writeBuffer .= substr($data, 0, $count);
            $position = $count;
            $toDo -= $count;
        }
        if ($toDo === 0 && !$mustFlush) {
            return true;
        }
        // next flush the (full) buffer (_IO_OVERFLOW: allocates it and enters put mode)
        $this->bufferSize ??= self::defaultBufferSize($this->stream);
        if (!$this->putting) {
            $this->putting = true;
            $this->writeEndPinned = $this->bufferMode !== self::IOFBF;
        }
        if (!$this->flush()) {
            return false;
        }
        // try to maintain alignment: write a whole number of blocks
        $blockSize = $this->bufferSize;
        $directCount = $toDo - ($blockSize >= 128 ? $toDo % $blockSize : 0);
        if ($directCount > 0) {
            if (!$this->writeOut(substr($data, $position, $directCount))) {
                return false;
            }
            $position += $directCount;
            $toDo -= $directCount;
        }
        if ($toDo === 0) {
            return true;
        }
        // now write out the remainder (_IO_default_xsputn)
        $rest = substr($data, $position);
        if ($this->bufferMode === self::IOFBF) {
            while ($rest !== '') {
                $space = $this->bufferSize - \strlen($this->writeBuffer);
                if ($space === 0) {  // buffer full: flush it and go on
                    if (!$this->flush()) {
                        return false;
                    }
                    continue;
                }
                $this->writeBuffer .= substr($rest, 0, $space);
                $rest = substr($rest, $space);
            }
            return true;
        }
        // line buffered or unbuffered: character by character (_IO_new_file_overflow)
        $length = \strlen($rest);
        for ($i = 0; $i < $length; $i++) {
            if (\strlen($this->writeBuffer) >= $this->bufferSize && !$this->flush()) {
                return false;
            }
            $this->writeBuffer .= $rest[$i];
            if (($this->bufferMode === self::IONBF || $rest[$i] === "\n") && !$this->flush()) {
                return false;
            }
        }
        return true;
    }

    /** standard output: through PHP's output buffer, which print() uses too */
    private function writeStdout(string $data): bool
    {
        if ($this->bufferMode === self::IOLBF && str_contains($data, "\n")) {
            $newlinePosition = strrpos($data, "\n");
            echo substr($data, 0, $newlinePosition + 1);
            Standalone::flushStdout();
            echo substr($data, $newlinePosition + 1);
            return true;
        }
        echo $data;
        if ($this->bufferMode === self::IONBF) {
            Standalone::flushStdout();
        }
        return true;
    }

    /** write $data to the underlying stream now */
    private function writeOut(string $data): bool
    {
        $length = \strlen($data);
        $written = 0;
        while ($written < $length) {
            Errno::clearPhpError();
            $count = @fwrite($this->stream, $written === 0 ? $data : substr($data, $written));
            if ($count === false || $count === 0) {
                Errno::setFromPhpError(Errno::EBADF);
                $this->error = true;
                return false;
            }
            $written += $count;
        }
        if ($this->append) {
            $this->atEndAfterAppend = true;
        }
        return true;
    }

    /** stdio.h: fflush */
    public function flush(): bool
    {
        if ($this->isStdout) {
            Standalone::flushStdout();
            return true;
        }
        if ($this->writeBuffer === '') {
            return true;
        }
        $data = $this->writeBuffer;
        $this->writeBuffer = '';
        $this->writeEndPinned = $this->bufferMode !== self::IOFBF;  // glibc: new_do_write
        return $this->writeOut($data);
    }

    /**
     * stdio.h: setvbuf with a NULL buffer, as glibc's _IO_setvbuf: the
     * size is ignored; "full" allocates the default buffer if there is
     * none, "line" keeps whatever buffer there is (even the one byte of
     * an unbuffered stream), "no" flushes and uses one byte.
     */
    public function setBuffering(int $mode): bool
    {
        if ($mode === self::IONBF) {
            if (!$this->flush()) {
                return false;
            }
            $this->bufferSize = 1;
            $this->writeEndPinned = true;
        } elseif ($mode === self::IOFBF) {
            $this->bufferSize ??= self::defaultBufferSize($this->stream);
        }
        $this->bufferMode = $mode;
        return true;
    }

    /**
     * stdio.h: fseeko followed by ftello: the new position, or null on
     * error (errno set).
     */
    public function seek(int $offset, int $whence): ?int
    {
        if (!$this->flush()) {
            return null;
        }
        $this->putting = false;
        if ($whence === SEEK_CUR) {
            $offset -= \strlen($this->pushback);
            if ($this->atEndAfterAppend) {  // PHP's idea of the position ignores O_APPEND
                @fseek($this->stream, 0, SEEK_END);
            }
        }
        $this->atEndAfterAppend = false;
        $this->pushback = '';
        $stream = $this->stream;
        if ($this->isStdout) {  // PHP writes stdout past its STDOUT stream; use the descriptor itself
            $stream = @fopen('php://fd/1', 'r');
            if ($stream === false) {
                Errno::$errno = Errno::EBADF;
                return null;
            }
        }
        try {
            if (!(stream_get_meta_data($stream)['seekable'] ?? false)) {
                Errno::$errno = Errno::ESPIPE;
                return null;
            }
            if (@fseek($stream, $offset, $whence) !== 0) {
                Errno::$errno = Errno::EINVAL;  // resulting offset would be negative
                return null;
            }
            $position = @ftell($stream);
            return $position === false ? null : $position;
        } finally {
            if ($stream !== $this->stream) {
                fclose($stream);
            }
        }
    }

    /**
     * stdio.h: fclose (true on success). For a popen stream use pclose().
     */
    public function close(): bool
    {
        $flushed = $this->flush();
        $this->closed = true;
        unset(self::$openFiles[$this]);
        if (!$this->ownsStream) {
            return $flushed;
        }
        $closed = @fclose($this->stream);
        return $flushed && $closed;
    }

    /**
     * stdio.h: pclose: close the pipe and wait for the command. Returns
     * ["exit", status] or ["signal", number], or null if waiting failed.
     *
     * @return array{string, int}|null
     */
    public function pclose(): ?array
    {
        $this->close();
        $process = $this->process;
        $this->process = null;
        return self::waitForProcess($process);
    }

    /**
     * Wait for a proc_open child and decode its status (loslib.c:
     * l_inspectstat). Hosts may disable pcntl (disable_functions): then
     * proc_get_status is polled until the child has terminated.
     *
     * @param resource $process
     * @return array{string, int}|null
     */
    public static function waitForProcess($process): ?array
    {
        $status = proc_get_status($process);
        $pcntlAvailable = \function_exists('pcntl_waitpid') && \function_exists('pcntl_get_last_error')
            && \function_exists('pcntl_wifsignaled') && \function_exists('pcntl_wtermsig') && \function_exists('pcntl_wexitstatus');
        if ($status['running'] && !$pcntlAvailable) {
            for ($delayMicroseconds = 100; $status['running']; $status = proc_get_status($process)) {
                usleep($delayMicroseconds);
                $delayMicroseconds = min(2 * $delayMicroseconds, 20000);
            }
        }
        if (!$status['running']) {  // terminated, and reaped by proc_get_status
            proc_close($process);
            if ($status['signaled']) {
                return ['signal', $status['termsig']];
            }
            if ($status['exitcode'] === -1) {  // no status: waitpid failed (SIGCHLD ignored: reaped at once)
                Errno::$errno = Errno::ECHILD;
                return null;
            }
            return ['exit', $status['exitcode']];
        }
        do {
            $pid = pcntl_waitpid($status['pid'], $waitStatus);
        } while ($pid === -1 && pcntl_get_last_error() === PCNTL_EINTR);
        proc_close($process);
        if ($pid === -1) {
            Errno::$errno = pcntl_get_last_error();
            return null;
        }
        if (pcntl_wifsignaled($waitStatus)) {
            return ['signal', pcntl_wtermsig($waitStatus)];
        }
        return ['exit', pcntl_wexitstatus($waitStatus)];
    }

    /** a stream dropped without fclose is closed like C's __gc would (liolib.c: f_gc) */
    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        if ($this->process !== null) {
            $this->pclose();
            return;
        }
        if ($this->ownsStream) {
            $this->close();
        } elseif ($this->writeBuffer !== '') {
            $this->flush();
        }
    }
}
