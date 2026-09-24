<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Lib\Io\CFile;
use LuaPhp\Lib\Io\Errno;
use LuaPhp\Lib\Os\CTime;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\Standalone;
use LuaPhp\Runtime\Vm;

/**
 * Port of loslib.c: the os library. Dates and times go through CTime
 * (C's time.h as glibc behaves in the "C" locale).
 */
final class OsLib
{
    // loslib.c: LUA_STRFTIMEOPTIONS (C99 specification)
    private const LUA_STRFTIMEOPTIONS = 'aAbBcCdDeFgGhHIjmMnprRStTuUVwWxXyYzZ%'
        . '||' . 'EcECExEXEyEY' . 'OdOeOHOIOmOMOSOuOUOVOwOWOy';

    // loslib.c: LUA_TMPNAMTEMPLATE "/tmp/lua_XXXXXX" without the "XXXXXX"
    private const TMPNAM_TEMPLATE_PREFIX = '/tmp/lua_';

    // limits.h
    private const INT_MAX = 2147483647;
    private const INT_MIN = -2147483648;

    /** set once os.setlocale has put the process in C's initial "C" locale */
    private static bool $localeInitialized = false;

    // loslib.c: luaopen_os
    public static function open(Coroutine $L): LuaTable
    {
        $os = new LuaTable();
        Auxiliary::setFunctions($os, [
            'clock' => self::clock(...),
            'date' => self::date(...),
            'difftime' => self::difftime(...),
            'execute' => self::execute(...),
            'exit' => self::exit(...),
            'getenv' => self::getenv(...),
            'remove' => self::remove(...),
            'rename' => self::rename(...),
            'setlocale' => self::setlocale(...),
            'time' => self::time(...),
            'tmpname' => self::tmpname(...),
        ]);
        return $os;
    }

    // loslib.c: os_execute (l_system is system(3): /bin/sh -c, standard files inherited)
    private static function execute(Coroutine $L, array $args): array
    {
        $command = Auxiliary::optString($L, $args, 1, null);
        Errno::$errno = 0;
        if ($command === null) {
            return [is_executable('/bin/sh')];  // true if there is a shell
        }
        // standard descriptors inherited (see CFile::popen)
        $process = @proc_open(DebugInfo::cString($command), [], $pipes);
        if ($process === false) {
            Errno::setFromPhpError();
            return Errno::execResult(null);
        }
        return Errno::execResult(CFile::waitForProcess($process));
    }

    // loslib.c: os_remove (stdio.h: remove, glibc: unlink, or rmdir for a directory)
    private static function remove(Coroutine $L, array $args): array
    {
        $filename = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        Errno::$errno = 0;
        if ($filename === '') {
            Errno::$errno = Errno::ENOENT;
            return Errno::fileResult(false, $filename);
        }
        $path = CFile::plainPath($filename);
        Errno::clearPhpError();
        $removed = @unlink($path);
        if (!$removed) {
            Errno::setFromPhpError(Errno::ENOENT);
            if (Errno::$errno === Errno::EISDIR) {
                Errno::clearPhpError();
                $removed = @rmdir($path);
                if (!$removed) {
                    Errno::setFromPhpError(Errno::ENOENT);
                }
            }
        }
        return Errno::fileResult($removed, $filename);
    }

    // loslib.c: os_rename
    private static function rename(Coroutine $L, array $args): array
    {
        $fromName = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $toName = DebugInfo::cString(Auxiliary::checkString($L, $args, 2));
        Errno::$errno = 0;
        if ($fromName === '' || $toName === '') {
            Errno::$errno = Errno::ENOENT;
            return Errno::fileResult(false, null);
        }
        Errno::clearPhpError();
        $renamed = @rename(CFile::plainPath($fromName), CFile::plainPath($toName));
        if (!$renamed) {
            Errno::setFromPhpError(Errno::ENOENT);
        }
        return Errno::fileResult($renamed, null);
    }

    // loslib.c: os_tmpname (lua_tmpnam: mkstemp of LUA_TMPNAMTEMPLATE, then close)
    private static function tmpname(Coroutine $L, array $args): array
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        for ($attempt = 0; $attempt < 100; $attempt++) {  // mkstemp: replace "XXXXXX", create exclusively
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $characters[random_int(0, 61)];
            }
            $name = self::TMPNAM_TEMPLATE_PREFIX . $suffix;
            $file = @fopen($name, 'x');
            if ($file !== false) {
                fclose($file);
                @chmod($name, 0600);
                return [$name];
            }
            if (!file_exists($name)) {
                break;  // not a name clash: the directory is unusable
            }
        }
        Auxiliary::error($L, 'unable to generate a unique filename');
    }

    // loslib.c: os_getenv
    private static function getenv(Coroutine $L, array $args): array
    {
        $name = DebugInfo::cString(Auxiliary::checkString($L, $args, 1));
        $value = $name === '' ? false : getenv($name);
        return [$value === false ? null : $value];  // if NULL push nil
    }

    // loslib.c: os_clock (clock(): processor time of the process)
    private static function clock(Coroutine $L, array $args): array
    {
        $usage = getrusage();
        $microseconds = $usage['ru_utime.tv_sec'] * 1000000 + $usage['ru_utime.tv_usec']
            + $usage['ru_stime.tv_sec'] * 1000000 + $usage['ru_stime.tv_usec'];
        return [$microseconds / 1000000.0];
    }

    /*
    ** {======================================================
    ** Time/Date operations
    ** { year=%Y, month=%m, day=%d, hour=%H, min=%M, sec=%S,
    **   wday=%w+1, yday=%j, isdst=? }
    ** =======================================================
    */

    /** loslib.c: setallfields: set all fields from broken-down time $tm in $table */
    private static function setAllFields(Coroutine $L, LuaTable $table, array $tm): void
    {
        Vm::setTable($L, $table, 'year', $tm['year'] + 1900);
        Vm::setTable($L, $table, 'month', $tm['mon'] + 1);
        Vm::setTable($L, $table, 'day', $tm['mday']);
        Vm::setTable($L, $table, 'hour', $tm['hour']);
        Vm::setTable($L, $table, 'min', $tm['min']);
        Vm::setTable($L, $table, 'sec', $tm['sec']);
        Vm::setTable($L, $table, 'yday', $tm['yday'] + 1);
        Vm::setTable($L, $table, 'wday', $tm['wday'] + 1);
        if ($tm['isdst'] >= 0) {  // setboolfield: undefined values are not set
            Vm::setTable($L, $table, 'isdst', $tm['isdst'] > 0);
        }
    }

    // loslib.c: getboolfield
    private static function getBoolField(Coroutine $L, LuaTable $table, string $key): int
    {
        $value = Vm::getTable($L, $table, $key);
        if ($value === null) {
            return -1;
        }
        return $value === false ? 0 : 1;
    }

    // loslib.c: getfield
    private static function getField(Coroutine $L, LuaTable $table, string $key, int $default, int $delta): int
    {
        $value = Vm::getTable($L, $table, $key);  // get field and its type
        $result = Vm::toInteger($value);
        if ($result === null) {  // field is not an integer?
            if ($value !== null) {  // some other value?
                Auxiliary::error($L, "field '$key' is not an integer");
            } elseif ($default < 0) {  // absent field; no default?
                Auxiliary::error($L, "field '$key' missing in date table");
            }
            return $default;
        }
        if (!($result >= 0 ? $result - $delta <= self::INT_MAX : self::INT_MIN + $delta <= $result)) {
            Auxiliary::error($L, "field '$key' is out-of-bound");
        }
        return $result - $delta;
    }

    /**
     * loslib.c: checkoption: the conversion specifier at $position of
     * $format (after a '%'), or an argument error.
     */
    private static function checkOption(Coroutine $L, string $format, int $position): string
    {
        $options = self::LUA_STRFTIMEOPTIONS;
        $optionsLength = \strlen($options);
        $conversionLength = \strlen($format) - $position;
        $optionLength = 1;  // length of options being checked
        for ($i = 0; $i < $optionsLength && $optionLength <= $conversionLength; $i += $optionLength) {
            if ($options[$i] === '|') {  // next block?
                $optionLength++;  // will check options with next length (+1)
            } elseif (substr_compare($format, substr($options, $i, $optionLength), $position, $optionLength) === 0) {
                return substr($format, $position, $optionLength);  // match
            }
        }
        Auxiliary::argError($L, 1, "invalid conversion specifier '%" . DebugInfo::cString(substr($format, $position)) . "'");
    }

    /** loslib.c: l_checktime (time_t is 64-bit, so every integer fits) */
    private static function checkTime(Coroutine $L, array $args, int $arg): int
    {
        return Auxiliary::checkInteger($L, $args, $arg);
    }

    // loslib.c: os_date
    private static function date(Coroutine $L, array $args): array
    {
        $format = Auxiliary::optString($L, $args, 1, '%c');
        $t = ($args[1] ?? null) === null ? time() : self::checkTime($L, $args, 2);
        $position = 0;
        if (str_starts_with($format, '!')) {  // UTC?
            $tm = CTime::gmtime($t);
            $position = 1;  // skip '!'
        } else {
            $tm = CTime::localtime($t);
        }
        if ($tm === null) {  // invalid date?
            Auxiliary::error($L, 'date result cannot be represented in this installation');
        }
        if (DebugInfo::cString(substr($format, $position)) === '*t') {
            $table = new LuaTable();  // 9 = number of fields
            self::setAllFields($L, $table, $tm);
            return [$table];
        }
        $result = '';
        $length = \strlen($format);
        while ($position < $length) {
            $percentPosition = strpos($format, '%', $position);
            if ($percentPosition === false) {  // no more conversion specifiers
                $result .= substr($format, $position);
                break;
            }
            $result .= substr($format, $position, $percentPosition - $position);
            $conversion = self::checkOption($L, $format, $percentPosition + 1);
            $result .= CTime::strftime('%' . $conversion, $tm);
            $position = $percentPosition + 1 + \strlen($conversion);
        }
        return [$result];
    }

    // loslib.c: os_time
    private static function time(Coroutine $L, array $args): array
    {
        if (($args[0] ?? null) === null) {  // called without args?
            return [time()];  // get current time
        }
        $table = Auxiliary::checkTable($L, $args, 1);
        $tm = [
            'year' => self::getField($L, $table, 'year', -1, 1900),
            'mon' => self::getField($L, $table, 'month', -1, 1),
            'mday' => self::getField($L, $table, 'day', -1, 0),
            'hour' => self::getField($L, $table, 'hour', 12, 0),
            'min' => self::getField($L, $table, 'min', 0, 0),
            'sec' => self::getField($L, $table, 'sec', 0, 0),
            'isdst' => self::getBoolField($L, $table, 'isdst'),
        ];
        $t = CTime::mktime($tm);
        if ($t === null) {  // mktime failed: the fields stay as they were
            $tm += ['yday' => -1, 'wday' => -1];
        }
        self::setAllFields($L, $table, $tm);  // update fields with normalized values
        if ($t === null || $t === -1) {
            Auxiliary::error($L, 'time result cannot be represented in this installation');
        }
        return [$t];
    }

    // loslib.c: os_difftime
    private static function difftime(Coroutine $L, array $args): array
    {
        $t1 = self::checkTime($L, $args, 1);
        $t2 = self::checkTime($L, $args, 2);
        return [(float) ($t1 - $t2)];
    }

    /* }====================================================== */

    // loslib.c: os_setlocale (on the process's C locale, as C does)
    private static function setlocale(Coroutine $L, array $args): array
    {
        $categories = [LC_ALL, LC_COLLATE, LC_CTYPE, LC_MONETARY, LC_NUMERIC, LC_TIME];
        $locale = Auxiliary::optString($L, $args, 1, null);
        $option = Auxiliary::checkOption($L, $args, 2, 'all', ['all', 'collate', 'ctype', 'monetary', 'numeric', 'time']);
        if (!self::$localeInitialized) {  // a C program starts in the "C" locale; PHP does not
            \setlocale(LC_ALL, 'C');
            self::$localeInitialized = true;
        }
        if ($locale === null) {
            $result = \setlocale($categories[$option], '0');  // query
        } else {
            $locale = DebugInfo::cString($locale);
            // PHP reads "0" as a query; for C it is just an unknown locale name
            $result = $locale === '0' ? false : \setlocale($categories[$option], $locale);
        }
        return [$result === false ? null : $result];
    }

    // loslib.c: os_exit
    private static function exit(Coroutine $L, array $args): array
    {
        $first = $args[0] ?? null;
        if (\is_bool($first)) {
            $status = $first ? 0 : 1;  // EXIT_SUCCESS / EXIT_FAILURE
        } else {
            $status = Auxiliary::optInteger($L, $args, 1, 0);
        }
        $close = $args[1] ?? null;
        if ($close !== null && $close !== false) {
            // lua_close: close the main thread's pending to-be-closed variables
            $main = $L->globalState->mainThread;
            Calls::closeProtected($main, $main->ci, $main->baseCi, Lua::LUA_OK, null);
        }
        // exit: flush all streams, then end the process
        CFile::flushAll();
        Standalone::flushStdout();
        exit($status & 0xFF);
    }
}
