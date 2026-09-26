<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MemoryLimit;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Vm;

/**
 * Port of lutf8lib.c: the utf8 library.
 *
 * C reads the '\0' that ends every Lua string; here reads at the end of a
 * string give 0 explicitly.
 *
 * @internal
 */
final class Utf8Lib
{
    // lutf8lib.c: MAXUNICODE / MAXUTF
    private const MAXUNICODE = 0x10FFFF;
    private const MAXUTF = 0x7FFFFFFF;

    // lutf8lib.c: MSGInvalid
    private const MSG_INVALID = 'invalid UTF-8 code';

    // lutf8lib.c: UTF8PATT (pattern to match a single UTF-8 character)
    private const UTF8PATT = "[\0-\x7F\xC2-\xFD][\x80-\xBF]*";

    // limits.h: INT_MAX
    private const INT_MAX = 0x7fffffff;

    /**
     * lutf8lib.c: utf8_decode's limits: the minimum value for each
     * sequence length, to check for overlong representations. The first
     * entry (C: ~(utfint)0) forces an error for non-ascii bytes with no
     * continuation bytes.
     */
    private const LIMITS = [0xFFFFFFFF, 0x80, 0x800, 0x10000, 0x200000, 0x4000000];

    // lutf8lib.c: luaopen_utf8
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        $strictIterator = new NativeFunction('iter_auxstrict', static fn (Coroutine $L, array $args): array => self::iterAux($L, $args, true));
        $laxIterator = new NativeFunction('iter_auxlax', static fn (Coroutine $L, array $args): array => self::iterAux($L, $args, false));
        Auxiliary::setFunctions($library, [
            'offset' => self::byteOffset(...),
            'codepoint' => self::codepoint(...),
            'char' => self::utfChar(...),
            'len' => self::utfLen(...),
            'codes' => static fn (Coroutine $L, array $args): array => self::iterCodes($L, $args, $strictIterator, $laxIterator),
        ]);
        $library->hash['charpattern'] = self::UTF8PATT;
        return $library;
    }

    // lutf8lib.c: iscont
    private static function isContinuation(string $s, int $position): bool
    {
        return $position < \strlen($s) && (\ord($s[$position]) & 0xC0) === 0x80;
    }

    /** lutf8lib.c: u_posrelat: translate a relative string position (negative means back from end) */
    private static function relativePosition(int $position, int $length): int
    {
        if ($position >= 0) {
            return $position;
        }
        if (-$position > $length) {  // 0u - (size_t)pos > len
            return 0;
        }
        return $length + $position + 1;
    }

    /**
     * lutf8lib.c: utf8_decode: decode the UTF-8 sequence at $position.
     * Returns [position after it, code point], or null if the byte
     * sequence is invalid.
     *
     * @return array{int, int}|null
     */
    private static function decode(string $s, int $position, bool $strict): ?array
    {
        $length = \strlen($s);
        $c = $position < $length ? \ord($s[$position]) : 0;
        $result = 0;  // final result
        if ($c < 0x80) {  // ascii?
            $result = $c;
        } elseif ($c >= 0xFE) {  // c >= 1111 1110b ?
            return null;  // would need six or more continuation bytes
        } else {
            $count = 0;  // to count number of continuation bytes
            for (; $c & 0x40; $c <<= 1) {  // while it needs continuation bytes...
                $count++;
                $cc = $position + $count < $length ? \ord($s[$position + $count]) : 0;  // read next byte
                if (($cc & 0xC0) !== 0x80) {  // not a continuation byte?
                    return null;  // invalid byte sequence
                }
                $result = ($result << 6) | ($cc & 0x3F);  // add lower 6 bits from cont. byte
            }
            $result |= (($c & 0x7F) << ($count * 5));  // add first byte
            if ($result > self::MAXUTF || $result < self::LIMITS[$count]) {
                return null;  // invalid byte sequence
            }
            $position += $count;  // skip continuation bytes read
        }
        if ($strict) {
            // check for invalid code points; too large or surrogates
            if ($result > self::MAXUNICODE || (0xD800 <= $result && $result <= 0xDFFF)) {
                return null;
            }
        }
        return [$position + 1, $result];  // +1 to include first byte
    }

    /**
     * lutf8lib.c: utflen: utf8.len(s [, i [, j [, lax]]]) --> number of
     * characters that start in the range [i,j], or fail plus the current
     * position if 's' is not well formed in that interval
     */
    private static function utfLen(Coroutine $L, array $args): array
    {
        $count = 0;  // counter for the number of characters
        $s = Auxiliary::checkString($L, $args, 1);
        $length = \strlen($s);
        $start = self::relativePosition(Auxiliary::optInteger($L, $args, 2, 1), $length);
        $end = self::relativePosition(Auxiliary::optInteger($L, $args, 3, -1), $length);
        $lax = ($args[3] ?? null) !== null && ($args[3] ?? null) !== false;
        Auxiliary::argCheck($L, 1 <= $start && --$start <= $length, 2, 'initial position out of bounds');
        Auxiliary::argCheck($L, --$end < $length, 3, 'final position out of bounds');
        $L->globalState->budget?->chargeSteps(max(0, $end - $start + 1));  // (a step per byte: a decode is about an instruction's work)
        while ($start <= $end) {
            $decoded = self::decode($s, $start, !$lax);
            if ($decoded === null) {  // conversion error?
                return [null, $start + 1];  // return fail and current position
            }
            $start = $decoded[0];
            $count++;
        }
        return [$count];
    }

    /**
     * lutf8lib.c: codepoint: codepoint(s, [i, [j [, lax]]]) -> returns
     * codepoints for all characters that start in the range [i,j]
     */
    private static function codepoint(Coroutine $L, array $args): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $length = \strlen($s);
        $start = self::relativePosition(Auxiliary::optInteger($L, $args, 2, 1), $length);
        $end = self::relativePosition(Auxiliary::optInteger($L, $args, 3, $start), $length);
        $lax = ($args[3] ?? null) !== null && ($args[3] ?? null) !== false;
        Auxiliary::argCheck($L, $start >= 1, 2, 'out of bounds');
        Auxiliary::argCheck($L, $end <= $length, 3, 'out of bounds');
        if ($start > $end) {
            return [];  // empty interval; return no values
        }
        if ($end - $start >= self::INT_MAX) {  // (lua_Integer -> int) overflow?
            Auxiliary::error($L, 'string slice too long');
        }
        Auxiliary::checkStack($L, $end - $start + 1, 'string slice too long');
        $L->globalState->budget?->chargeSteps($end - $start + 1);
        $codes = [];
        for ($position = $start - 1; $position < $end;) {
            $decoded = self::decode($s, $position, !$lax);
            if ($decoded === null) {
                Auxiliary::error($L, self::MSG_INVALID);
            }
            [$position, $codes[]] = $decoded;
        }
        return $codes;
    }

    /** lobject.c: luaO_utf8esc: the UTF-8 bytes of $x (at most 0x7FFFFFFF) */
    private static function utf8Escape(int $x): string
    {
        if ($x < 0x80) {  // ascii?
            return \chr($x);
        }
        $bytes = '';  // need continuation bytes
        $maximumInFirstByte = 0x3f;
        do {  // add continuation bytes
            $bytes = \chr(0x80 | ($x & 0x3f)) . $bytes;
            $x >>= 6;  // remove added bits
            $maximumInFirstByte >>= 1;  // now there is one less bit available in first byte
        } while ($x > $maximumInFirstByte);  // still needs continuation byte?
        return \chr(((~$maximumInFirstByte << 1) | $x) & 0xFF) . $bytes;  // add first byte
    }

    // lutf8lib.c: pushutfchar
    private static function utfCharOf(Coroutine $L, array $args, int $arg): string
    {
        $code = Auxiliary::checkInteger($L, $args, $arg);
        Auxiliary::argCheck($L, $code >= 0 && $code <= self::MAXUTF, $arg, 'value out of range');  // (lua_Unsigned)code <= MAXUTF
        return self::utf8Escape($code);
    }

    /** lutf8lib.c: utfchar: utfchar(n1, n2, ...) -> char(n1)..char(n2)... */
    private static function utfChar(Coroutine $L, array $args): array
    {
        $count = \count($args);
        MemoryLimit::reserve(6 * $count, $L->globalState->budget);  // (at most 6 bytes each: MAXUTF is 0x7FFFFFFF)
        $L->globalState->budget?->chargeSteps($count);
        $result = '';
        for ($i = 1; $i <= $count; $i++) {
            $result .= self::utfCharOf($L, $args, $i);
        }
        return [$result];
    }

    /**
     * lutf8lib.c: byteoffset: offset(s, n, [i]) -> index where n-th
     * character counting from position 'i' starts; 0 means character at
     * 'i'.
     */
    private static function byteOffset(Coroutine $L, array $args): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $length = \strlen($s);
        $n = Auxiliary::checkInteger($L, $args, 2);
        $position = ($n >= 0) ? 1 : $length + 1;
        $position = self::relativePosition(Auxiliary::optInteger($L, $args, 3, $position), $length);
        Auxiliary::argCheck($L, 1 <= $position && --$position <= $length, 3, 'position out of bounds');
        $initialPosition = $position;
        if ($n === 0) {
            // find beginning of current byte sequence
            while ($position > 0 && self::isContinuation($s, $position)) {
                $position--;
            }
        } else {
            if (self::isContinuation($s, $position)) {
                Auxiliary::error($L, 'initial position is a continuation byte');
            }
            if ($n < 0) {
                while ($n < 0 && $position > 0) {  // move back
                    do {  // find beginning of previous character
                        $position--;
                    } while ($position > 0 && self::isContinuation($s, $position));
                    $n++;
                }
            } else {
                $n--;  // do not move for 1st character
                while ($n > 0 && $position < $length) {
                    do {  // find beginning of next character
                        $position++;
                    } while (self::isContinuation($s, $position));  // (cannot pass final '\0')
                    $n--;
                }
            }
        }
        $L->globalState->budget?->chargeSteps(abs($position - $initialPosition));
        if ($n === 0) {  // did it find given character?
            return [$position + 1];
        }
        return [null];  // no such character
    }

    // lutf8lib.c: iter_aux
    private static function iterAux(Coroutine $L, array $args, bool $strict): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $length = \strlen($s);
        $n = Vm::toInteger($args[1] ?? null) ?? 0;  // lua_tointeger (0 if not a number)
        if ($n < 0) {
            return [];  // (lua_Unsigned)n >= len: no more codepoints
        }
        if ($n < $length) {
            $skipFrom = $n;
            while (self::isContinuation($s, $n)) {
                $n++;  // go to next character
            }
            $L->globalState->budget?->chargeSteps($n - $skipFrom);
        }
        if ($n >= $length) {
            return [];  // no more codepoints
        }
        $decoded = self::decode($s, $n, $strict);
        if ($decoded === null || self::isContinuation($s, $decoded[0])) {
            Auxiliary::error($L, self::MSG_INVALID);
        }
        return [$n + 1, $decoded[1]];
    }

    // lutf8lib.c: iter_codes
    private static function iterCodes(Coroutine $L, array $args, NativeFunction $strictIterator, NativeFunction $laxIterator): array
    {
        $lax = ($args[1] ?? null) !== null && ($args[1] ?? null) !== false;
        $s = Auxiliary::checkString($L, $args, 1);
        Auxiliary::argCheck($L, !self::isContinuation($s, 0), 1, self::MSG_INVALID);
        return [$lax ? $laxIterator : $strictIterator, $args[0], 0];
    }
}
