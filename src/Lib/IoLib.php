<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Lib\Io\CFile;
use LuaPhp\Lib\Io\Errno;
use LuaPhp\Lib\Io\LuaStream;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\NumberFormat;
use LuaPhp\Runtime\StringToNumber;
use LuaPhp\Runtime\Userdata;
use LuaPhp\Runtime\Vm;

/**
 * Port of liolib.c: the io library and file handle methods.
 *
 * A file handle is a Userdata whose payload is a LuaStream (C:
 * luaL_Stream) holding a CFile (C: FILE *) and its close function; its
 * metatable is registry["FILE*"].
 *
 * @internal
 */
final class IoLib
{
    // lauxlib.h: LUA_FILEHANDLE
    private const LUA_FILEHANDLE = 'FILE*';

    // liolib.c: IO_PREFIX, IO_INPUT, IO_OUTPUT
    private const IO_PREFIX = '_IO_';
    private const IO_INPUT = self::IO_PREFIX . 'input';
    private const IO_OUTPUT = self::IO_PREFIX . 'output';

    // liolib.c: MAXARGLINE
    private const MAXARGLINE = 250;

    // liolib.c: L_MAXLENNUM
    private const L_MAXLENNUM = 200;

    // luaconf.h: LUAL_BUFFERSIZE (16 * sizeof(void*) * sizeof(lua_Number))
    private const LUAL_BUFFERSIZE = 1024;

    // liolib.c: luaopen_io
    public static function open(Coroutine $L): LuaTable
    {
        $io = new LuaTable();
        Auxiliary::setFunctions($io, [
            'close' => self::close(...),
            'flush' => self::flush(...),
            'input' => self::input(...),
            'lines' => self::lines(...),
            'open' => self::openFile(...),
            'output' => self::output(...),
            'popen' => self::popen(...),
            'read' => self::read(...),
            'tmpfile' => self::tmpfile(...),
            'type' => self::type(...),
            'write' => self::write(...),
        ]);
        self::createMeta($L);
        // create (and set) default files
        self::createStdFile($L, $io, CFile::standard(0, $L->globalState), self::IO_INPUT, 'stdin');
        self::createStdFile($L, $io, CFile::standard(1, $L->globalState), self::IO_OUTPUT, 'stdout');
        self::createStdFile($L, $io, CFile::standard(2, $L->globalState), null, 'stderr');
        return $io;
    }

    // liolib.c: createmeta
    private static function createMeta(Coroutine $L): void
    {
        // luaL_newmetatable: metatable for file handles
        $registry = $L->globalState->registry;
        $metatable = $registry->hash[self::LUA_FILEHANDLE] ?? null;
        if (!($metatable instanceof LuaTable)) {
            $metatable = new LuaTable();
            $metatable->hash['__name'] = self::LUA_FILEHANDLE;
            $registry->hash[self::LUA_FILEHANDLE] = $metatable;
        }
        // metamethods for file handles ('__index' is a placeholder until the method table is set)
        Auxiliary::setFunctions($metatable, [
            '__gc' => self::fileGc(...),
            '__close' => self::fileGc(...),
            '__tostring' => self::fileToString(...),
        ]);
        // methods for file handles
        $methods = new LuaTable();
        Auxiliary::setFunctions($methods, [
            'read' => self::fileRead(...),
            'write' => self::fileWrite(...),
            'lines' => self::fileLines(...),
            'flush' => self::fileFlush(...),
            'seek' => self::fileSeek(...),
            'close' => self::fileClose(...),
            'setvbuf' => self::fileSetvbuf(...),
        ]);
        $metatable->hash['__index'] = $methods;  // metatable.__index = method table
    }

    // liolib.c: createstdfile
    private static function createStdFile(Coroutine $L, LuaTable $io, CFile $file, ?string $registryKey, string $name): void
    {
        $handle = self::newPreFile($L);
        $handle->payload->file = $file;
        $handle->payload->closef = self::noClose(...);
        if ($registryKey !== null) {
            $L->globalState->registry->hash[$registryKey] = $handle;  // add file to registry
        }
        $io->hash[$name] = $handle;  // add file to module
    }

    /** liolib.c: newprefile: a new 'closed' file handle */
    private static function newPreFile(Coroutine $L): Userdata
    {
        $handle = new Userdata(new LuaStream());
        Collector::setMetatable($L, $handle, $L->globalState->registry->hash[self::LUA_FILEHANDLE]);  // luaL_setmetatable
        return $handle;
    }

    /** liolib.c: newfile: a file handle closed by fclose (its file is not open yet) */
    private static function newFile(Coroutine $L): Userdata
    {
        $handle = self::newPreFile($L);
        $handle->payload->closef = self::fileCloseFunction(...);
        return $handle;
    }

    /** lauxlib.c: luaL_testudata(L, arg, LUA_FILEHANDLE) */
    private static function testStream(Coroutine $L, mixed $value): ?LuaStream
    {
        if ($value instanceof Userdata && $value->payload instanceof LuaStream
            && $value->metatable !== null && $value->metatable === ($L->globalState->registry->hash[self::LUA_FILEHANDLE] ?? null)) {
            return $value->payload;
        }
        return null;
    }

    /** liolib.c: tolstream (luaL_checkudata(L, 1, LUA_FILEHANDLE)) */
    private static function toLStream(Coroutine $L, array $args): LuaStream
    {
        $stream = self::testStream($L, $args[0] ?? null);
        if ($stream === null) {
            Auxiliary::typeError($L, $args, 1, self::LUA_FILEHANDLE);
        }
        return $stream;
    }

    // liolib.c: tofile
    private static function toFile(Coroutine $L, array $args): CFile
    {
        $stream = self::toLStream($L, $args);
        if ($stream->closef === null) {  // isclosed
            Auxiliary::error($L, 'attempt to use a closed file');
        }
        return $stream->file;
    }

    // liolib.c: io_type
    private static function type(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 1);
        $stream = self::testStream($L, $args[0]);
        if ($stream === null) {
            return [null];  // not a file
        }
        return [$stream->closef === null ? 'closed file' : 'file'];
    }

    // liolib.c: f_tostring
    private static function fileToString(Coroutine $L, array $args): array
    {
        $stream = self::toLStream($L, $args);
        if ($stream->closef === null) {
            return ['file (closed)'];
        }
        return ['file (' . LuaObject::address($stream->file) . ')'];
    }

    /** liolib.c: aux_close: call the handle's close function, marking it closed */
    private static function auxClose(Coroutine $L, Userdata $handle): array
    {
        $stream = $handle->payload;
        $closeFunction = $stream->closef;
        $stream->closef = null;  // mark stream as closed
        return $closeFunction($L, $handle);  // close it
    }

    // liolib.c: f_close
    private static function fileClose(Coroutine $L, array $args): array
    {
        self::toFile($L, $args);  // make sure argument is an open stream
        return self::auxClose($L, $args[0]);
    }

    // liolib.c: io_close
    private static function close(Coroutine $L, array $args): array
    {
        if ($args === []) {  // no argument?
            $args = [$L->globalState->registry->hash[self::IO_OUTPUT] ?? null];  // use default output
        }
        return self::fileClose($L, $args);
    }

    // liolib.c: f_gc (also '__close')
    private static function fileGc(Coroutine $L, array $args): array
    {
        $stream = self::toLStream($L, $args);
        if ($stream->closef !== null && $stream->file !== null) {
            self::auxClose($L, $args[0]);  // ignore closed and incompletely open files
        }
        return [];
    }

    /** liolib.c: io_fclose: close function of regular files */
    private static function fileCloseFunction(Coroutine $L, Userdata $handle): array
    {
        Errno::$errno = 0;
        return Errno::fileResult($handle->payload->file->close(), null);
    }

    /** liolib.c: io_pclose: close function of 'popen' files */
    private static function pipeCloseFunction(Coroutine $L, Userdata $handle): array
    {
        Errno::$errno = 0;
        return Errno::execResult($handle->payload->file->pclose());
    }

    /** liolib.c: io_noclose: close function of the standard files (keeps them open) */
    private static function noClose(Coroutine $L, Userdata $handle): array
    {
        $handle->payload->closef = self::noClose(...);  // keep file opened
        return [null, 'cannot close standard file'];
    }

    /** liolib.c: l_checkmode: 'mode' matches '[rwa]%+?[L_MODEEXT]*' (L_MODEEXT "b") */
    private static function checkMode(string $mode): bool
    {
        if ($mode === '' || !str_contains('rwa', $mode[0])) {
            return false;
        }
        $rest = substr($mode, 1);
        if (str_starts_with($rest, '+')) {  // skip if char is '+'
            $rest = substr($rest, 1);
        }
        return strspn($rest, 'b') === \strlen($rest);  // check extensions
    }

    /** liolib.c: opencheck: open a file or raise an error */
    private static function openCheck(Coroutine $L, string $filename, string $mode): Userdata
    {
        $handle = self::newFile($L);
        $filename = DebugInfo::cString($filename);
        $handle->payload->file = CFile::open($filename, $mode);
        if ($handle->payload->file === null) {
            Auxiliary::error($L, "cannot open file '$filename' (" . Errno::strerror(Errno::$errno) . ')');
        }
        return $handle;
    }

    // liolib.c: io_open
    private static function openFile(Coroutine $L, array $args): array
    {
        $filename = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $mode = DebugInfo::cString(Auxiliary::optString($L, $args, 2, 'r'));
        $handle = self::newFile($L);
        Auxiliary::argCheck($L, self::checkMode($mode), 2, 'invalid mode');
        Errno::$errno = 0;
        $handle->payload->file = CFile::open($filename, $mode);
        if ($handle->payload->file === null) {
            return Errno::fileResult(false, $filename);
        }
        return [$handle];
    }

    // liolib.c: io_popen
    private static function popen(Coroutine $L, array $args): array
    {
        $command = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $mode = DebugInfo::cString(Auxiliary::optString($L, $args, 2, 'r'));
        $handle = self::newPreFile($L);
        Auxiliary::argCheck($L, $mode === 'r' || $mode === 'w', 2, 'invalid mode');  // l_checkmodep
        Errno::$errno = 0;
        $handle->payload->file = CFile::popen($command, $mode, $L->globalState);
        $handle->payload->closef = self::pipeCloseFunction(...);
        if ($handle->payload->file === null) {
            return Errno::fileResult(false, $command);
        }
        return [$handle];
    }

    // liolib.c: io_tmpfile
    private static function tmpfile(Coroutine $L, array $args): array
    {
        $handle = self::newFile($L);
        Errno::$errno = 0;
        $handle->payload->file = CFile::tmpfile();
        if ($handle->payload->file === null) {
            return Errno::fileResult(false, null);
        }
        return [$handle];
    }

    /**
     * liolib.c: getiofile: the default input or output file handle (it
     * must be open).
     */
    private static function getIoFile(Coroutine $L, string $registryKey): Userdata
    {
        $handle = $L->globalState->registry->hash[$registryKey];
        if ($handle->payload->closef === null) {  // isclosed
            Auxiliary::error($L, 'default ' . substr($registryKey, \strlen(self::IO_PREFIX)) . ' file is closed');
        }
        return $handle;
    }

    // liolib.c: g_iofile
    private static function ioFile(Coroutine $L, array $args, string $registryKey, string $mode): array
    {
        $registry = $L->globalState->registry;
        if (($args[0] ?? null) !== null) {
            $filename = LuaObject::toStringCoerced($args[0]);
            if ($filename !== null) {
                $registry->hash[$registryKey] = self::openCheck($L, $filename, $mode);
            } else {
                self::toFile($L, $args);  // check that it's a valid file handle
                $registry->hash[$registryKey] = $args[0];
            }
        }
        return [$registry->hash[$registryKey]];  // return current value
    }

    // liolib.c: io_input
    private static function input(Coroutine $L, array $args): array
    {
        return self::ioFile($L, $args, self::IO_INPUT, 'r');
    }

    // liolib.c: io_output
    private static function output(Coroutine $L, array $args): array
    {
        return self::ioFile($L, $args, self::IO_OUTPUT, 'w');
    }

    /**
     * liolib.c: aux_lines: the iteration function for 'lines', a closure
     * over io_readline with upvalues: the file, the number of formats,
     * whether to close the file when finished, and the formats.
     *
     * @param list<mixed> $formats
     */
    private static function auxLines(Coroutine $L, Userdata $handle, array $formats, bool $toClose): NativeFunction
    {
        $count = \count($formats);  // number of arguments to read
        Auxiliary::argCheck($L, $count <= self::MAXARGLINE, self::MAXARGLINE + 2, 'too many arguments');
        return new NativeFunction('io_readline', self::readLineIterator(...), [$handle, $count, $toClose, ...$formats]);
    }

    // liolib.c: f_lines
    private static function fileLines(Coroutine $L, array $args): array
    {
        self::toFile($L, $args);  // check that it's a valid file handle
        return [self::auxLines($L, $args[0], \array_slice($args, 1), false)];
    }

    /**
     * liolib.c: io_lines. When it opens the file, it also returns the file
     * as the fourth result (the to-be-closed variable of a generic for).
     */
    private static function lines(Coroutine $L, array $args): array
    {
        if ($args === []) {
            $args[] = null;  // at least one argument
        }
        if ($args[0] === null) {  // no file name?
            $args[0] = $L->globalState->registry->hash[self::IO_INPUT] ?? null;  // get default input
            self::toFile($L, $args);  // check that it's a valid file handle
            $toClose = false;  // do not close it after iteration
        } else {  // open a new file
            $filename = Auxiliary::checkString($L, $args, 1);
            $args[0] = self::openCheck($L, $filename, 'r');
            $toClose = true;  // close it after iteration
        }
        $iterator = self::auxLines($L, $args[0], \array_slice($args, 1), $toClose);
        if ($toClose) {
            return [$iterator, null, null, $args[0]];  // iteration function, state, control, to-be-closed file
        }
        return [$iterator];
    }

    /*
    ** {======================================================
    ** READ
    ** =======================================================
    */

    /** C isspace in the "C" locale */
    private static function isSpace(int $c): bool
    {
        return $c === 0x20 || ($c >= 0x09 && $c <= 0x0D);
    }

    /**
     * liolib.c: read_number: read the longest prefix of a numeral (at most
     * L_MAXLENNUM characters), then convert it with lua_stringtonumber.
     *
     * @return array{bool, mixed}
     */
    private static function readNumber(CFile $f): array
    {
        $buffer = '';
        $invalid = false;
        do {
            $c = $f->getc();
        } while (self::isSpace($c));  // skip spaces
        // liolib.c: nextc: add current char to buffer (if not out of space) and read next one
        $nextc = static function () use ($f, &$c, &$buffer, &$invalid): bool {
            if (\strlen($buffer) >= self::L_MAXLENNUM) {  // buffer overflow?
                $invalid = true;  // invalidate result
                return false;
            }
            $buffer .= \chr($c);  // save current char
            $c = $f->getc();  // read next one
            return true;
        };
        // liolib.c: test2: accept current char if it is in 'set' (of size 2)
        $test2 = static function (string $set) use (&$c, $nextc): bool {
            if ($c === \ord($set[0]) || $c === \ord($set[1])) {
                return $nextc();
            }
            return false;
        };
        // liolib.c: readdigits: read a sequence of (hex)digits
        $readDigits = static function (bool $hex) use (&$c, $nextc): int {
            $count = 0;
            while ($c >= 0 && ($hex ? ctype_xdigit(\chr($c)) : ctype_digit(\chr($c))) && $nextc()) {
                $count++;
            }
            return $count;
        };
        $count = 0;
        $hex = false;
        $decimalPoint = '..';  // locale decimal point ("C" locale) and '.'
        $test2('-+');  // optional sign
        if ($test2('00')) {
            if ($test2('xX')) {
                $hex = true;  // numeral is hexadecimal
            } else {
                $count = 1;  // count initial '0' as a valid digit
            }
        }
        $count += $readDigits($hex);  // integral part
        if ($test2($decimalPoint)) {  // decimal point?
            $count += $readDigits($hex);  // fractional part
        }
        if ($count > 0 && $test2($hex ? 'pP' : 'eE')) {  // exponent mark?
            $test2('-+');  // exponent sign
            $readDigits(false);  // exponent digits
        }
        $f->ungetc($c);  // unread look-ahead char
        $number = $invalid ? null : StringToNumber::convert($buffer);
        if ($number !== null) {
            return [true, $number];  // ok, it is a valid number
        }
        return [false, null];  // invalid format: read fails
    }

    /**
     * liolib.c: test_eof
     *
     * @return array{bool, string}
     */
    private static function testEof(CFile $f): array
    {
        $c = $f->getc();
        $f->ungetc($c);  // no-op when c == EOF
        return [$c !== -1, ''];
    }

    /**
     * liolib.c: read_line: ok if it read something (a newline or
     * anything else)
     *
     * @return array{bool, string}
     */
    private static function readLine(CFile $f, bool $chop): array
    {
        $line = $f->readLine();
        $hasNewline = str_ends_with($line, "\n");
        if ($chop && $hasNewline) {
            $line = substr($line, 0, -1);
        }
        return [$hasNewline || $line !== '', $line];
    }

    /**
     * liolib.c: read_chars: true iff it read something. A request C
     * could not allocate a buffer for (negative counts are huge sizes)
     * fails like luaL_prepbuffsize.
     *
     * @return array{bool, string}
     */
    private static function readChars(CFile $f, int $count): array
    {
        if ($count < 0 || $count > CFile::MAX_READ_REQUEST) {
            LuaError::raise(Lua::MEMERRMSG);  // lauxlib.c: resizebox
        }
        $data = $f->read($count);
        return [$data !== '', $data];
    }

    /**
     * liolib.c: g_read: read with the formats at argument positions
     * $first.. of $args (Lua numbering).
     *
     * @return list<mixed>
     */
    private static function gRead(Coroutine $L, CFile $f, array $args, int $first): array
    {
        $argumentCount = \count($args) - ($first - 1);
        $f->clearError();
        Errno::$errno = 0;
        $results = [];
        if ($argumentCount <= 0) {  // no arguments?
            [$success, $results[]] = self::readLine($f, true);
        } else {
            // ensure stack space for all results and for auxlib's buffer
            Auxiliary::checkStack($L, $argumentCount + Lua::LUA_MINSTACK, 'too many arguments');
            $success = true;
            for ($n = $first; $argumentCount-- > 0 && $success; $n++) {
                $format = $args[$n - 1];
                if (\is_int($format) || \is_float($format)) {
                    $count = Auxiliary::checkInteger($L, $args, $n);
                    [$success, $results[]] = $count === 0 ? self::testEof($f) : self::readChars($f, $count);
                    continue;
                }
                $option = DebugInfo::cString(Auxiliary::checkString($L, $args, $n));
                if (str_starts_with($option, '*')) {
                    $option = substr($option, 1);  // skip optional '*' (for compatibility)
                }
                switch ($option[0] ?? '') {
                    case 'n':  // number
                        [$success, $results[]] = self::readNumber($f);
                        break;
                    case 'l':  // line
                        [$success, $results[]] = self::readLine($f, true);
                        break;
                    case 'L':  // line with end-of-line
                        [$success, $results[]] = self::readLine($f, false);
                        break;
                    case 'a':  // file
                        $results[] = $f->readAll();  // read entire file
                        $success = true;  // always success
                        break;
                    default:
                        Auxiliary::argError($L, $n, 'invalid format');
                }
            }
        }
        if ($f->error) {
            return Errno::fileResult(false, null);
        }
        if (!$success) {
            array_pop($results);  // remove last result
            $results[] = null;  // push nil instead
        }
        return $results;
    }

    // liolib.c: io_read
    private static function read(Coroutine $L, array $args): array
    {
        return self::gRead($L, self::getIoFile($L, self::IO_INPUT)->payload->file, $args, 1);
    }

    // liolib.c: f_read
    private static function fileRead(Coroutine $L, array $args): array
    {
        return self::gRead($L, self::toFile($L, $args), $args, 2);
    }

    /** liolib.c: io_readline: the iteration function of 'lines' */
    private static function readLineIterator(Coroutine $L, array $args): array
    {
        $upvalues = $L->ci->func->upvalues;
        $handle = $upvalues[0];
        $stream = $handle instanceof Userdata && $handle->payload instanceof LuaStream ? $handle->payload : null;
        $count = Vm::toInteger($upvalues[1] ?? null) ?? 0;
        if ($stream === null || $stream->closef === null) {  // file is already closed?
            Auxiliary::error($L, 'file is already closed');
        }
        $readArguments = [$args[0] ?? null];  // lua_settop(L, 1)
        Auxiliary::checkStack($L, $count, 'too many arguments');
        for ($i = 1; $i <= $count; $i++) {  // push arguments to 'g_read'
            $readArguments[] = $upvalues[2 + $i] ?? null;
        }
        $results = self::gRead($L, $stream->file, $readArguments, 2);
        $first = $results[0];
        if ($first !== null && $first !== false) {  // read at least one value?
            return $results;  // return them
        }
        // first result is false: EOF or error
        if (\count($results) > 1) {  // is there error information?
            Auxiliary::error($L, DebugInfo::cString(LuaObject::toStringCoerced($results[1])));  // 2nd result is error message
        }
        $toClose = $upvalues[2] ?? null;
        if ($toClose !== null && $toClose !== false) {  // generator created file?
            self::auxClose($L, $handle);  // close it
        }
        return [];
    }

    /* }====================================================== */

    /**
     * liolib.c: g_write: write the arguments from position $arg on; a
     * number is written with LUA_INTEGER_FMT or LUA_NUMBER_FMT.
     *
     * @return list<mixed>
     */
    private static function gWrite(Coroutine $L, CFile $f, array $args, int $arg, Userdata $handle): array
    {
        $count = \count($args);
        $status = true;
        Errno::$errno = 0;
        for (; $arg <= $count; $arg++) {
            $value = $args[$arg - 1];
            if (\is_int($value) || \is_float($value)) {
                // optimization: could be done exactly as for strings
                $text = \is_int($value) ? (string) $value : NumberFormat::formatG($value, 14);
                $written = $f->write($text);
                $status = $status && $written;
            } else {
                $text = Auxiliary::checkString($L, $args, $arg);
                $status = $status && $f->write($text);
            }
        }
        if ($status) {
            return [$handle];  // file handle already on stack top
        }
        return Errno::fileResult(false, null);
    }

    // liolib.c: io_write
    private static function write(Coroutine $L, array $args): array
    {
        $handle = self::getIoFile($L, self::IO_OUTPUT);
        return self::gWrite($L, $handle->payload->file, $args, 1, $handle);
    }

    // liolib.c: f_write
    private static function fileWrite(Coroutine $L, array $args): array
    {
        $f = self::toFile($L, $args);
        return self::gWrite($L, $f, $args, 2, $args[0]);  // file is returned
    }

    // liolib.c: f_seek
    private static function fileSeek(Coroutine $L, array $args): array
    {
        $modes = [SEEK_SET, SEEK_CUR, SEEK_END];
        $f = self::toFile($L, $args);
        $option = Auxiliary::checkOption($L, $args, 2, 'cur', ['set', 'cur', 'end']);
        $offset = Auxiliary::optInteger($L, $args, 3, 0);
        Errno::$errno = 0;
        $position = $f->seek($offset, $modes[$option]);
        if ($position === null) {
            return Errno::fileResult(false, null);  // error
        }
        return [$position];
    }

    // liolib.c: f_setvbuf
    private static function fileSetvbuf(Coroutine $L, array $args): array
    {
        $modes = [CFile::IONBF, CFile::IOFBF, CFile::IOLBF];
        $f = self::toFile($L, $args);
        $option = Auxiliary::checkOption($L, $args, 2, null, ['no', 'full', 'line']);
        Auxiliary::optInteger($L, $args, 3, self::LUAL_BUFFERSIZE);  // glibc ignores the size without a buffer
        Errno::$errno = 0;
        return Errno::fileResult($f->setBuffering($modes[$option]), null);
    }

    // liolib.c: io_flush
    private static function flush(Coroutine $L, array $args): array
    {
        $f = self::getIoFile($L, self::IO_OUTPUT)->payload->file;
        Errno::$errno = 0;
        return Errno::fileResult($f->flush(), null);
    }

    // liolib.c: f_flush
    private static function fileFlush(Coroutine $L, array $args): array
    {
        $f = self::toFile($L, $args);
        Errno::$errno = 0;
        return Errno::fileResult($f->flush(), null);
    }
}
