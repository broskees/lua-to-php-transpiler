<?php

declare(strict_types=1);

namespace LuaPhp\Lib\String;

use LuaPhp\Lib\StringLib;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;

/**
 * Port of lstrlib.c's PACK/UNPACK section: string.pack, string.packsize
 * and string.unpack, for the reference platform (x86-64 Linux: little
 * endian, short 2, int 4, long 8, size_t 8, lua_Integer 8, float 4,
 * double 8, maximum alignment 8).
 *
 * One instance is C's Header plus the read position in the format.
 */
final class Pack
{
    // lstrlib.c: KOption
    private const Kint = 0;  // signed integers
    private const Kuint = 1;  // unsigned integers
    private const Kfloat = 2;  // single-precision floating-point numbers
    private const Knumber = 3;  // Lua "native" floating-point numbers
    private const Kdouble = 4;  // double-precision floating-point numbers
    private const Kchar = 5;  // fixed-length strings
    private const Kstring = 6;  // strings with prefixed length
    private const Kzstr = 7;  // zero-terminated strings
    private const Kpadding = 8;  // padding
    private const Kpaddalign = 9;  // padding for alignment
    private const Knop = 10;  // no-op (configuration or spaces)

    // lstrlib.c: MAXINTSIZE (maximum size for the binary representation of an integer)
    private const MAXINTSIZE = 16;

    // lstrlib.c: SZINT (size of a lua_Integer)
    private const SZINT = 8;

    // lstrlib.c: MAXSIZE (INT_MAX, as sizeof(size_t) >= sizeof(int))
    private const MAXSIZE = 0x7fffffff;

    // lstrlib.c: offsetof(struct cD, u) with luaconf.h's LUAI_MAXALIGN
    private const NATIVE_MAXALIGN = 8;

    // C type sizes on the reference platform
    private const SIZEOF_SHORT = 2;
    private const SIZEOF_INT = 4;
    private const SIZEOF_LONG = 8;
    private const SIZEOF_SIZE_T = 8;

    // lstrlib.c: Header
    private bool $isLittle = true;  // nativeendian.little
    private int $maxAlign = 1;

    /** position of the next option in $format */
    private int $position = 0;

    // lstrlib.c: initheader
    private function __construct(
        private readonly Coroutine $L,
        /** the format up to its first '\0' (C reads it as a C string) */
        private readonly string $format,
    ) {
    }

    private function atEnd(): bool
    {
        return $this->position >= \strlen($this->format);
    }

    // lstrlib.c: digit
    private function isDigitAt(int $position): bool
    {
        return ctype_digit($this->format[$position] ?? '');
    }

    /** lstrlib.c: getnum: an integer numeral from the format, or $default if there is none */
    private function getNumber(int $default): int
    {
        if (!$this->isDigitAt($this->position)) {  // no number?
            return $default;
        }
        $number = 0;
        do {
            $number = $number * 10 + (\ord($this->format[$this->position++]) - \ord('0'));
        } while ($this->isDigitAt($this->position) && $number <= intdiv(self::MAXSIZE - 9, 10));
        return $number;
    }

    // lstrlib.c: getnumlimit
    private function getNumberLimit(int $default): int
    {
        $size = $this->getNumber($default);
        if ($size > self::MAXINTSIZE || $size <= 0) {
            Auxiliary::error($this->L, "integral size ($size) out of limits [1," . self::MAXINTSIZE . ']');
        }
        return $size;
    }

    /**
     * lstrlib.c: getoption: read and classify the next option.
     *
     * @return array{int, int} [KOption, size]
     */
    private function getOption(): array
    {
        $option = $this->format[$this->position++];
        switch ($option) {
            case 'b': return [self::Kint, 1];
            case 'B': return [self::Kuint, 1];
            case 'h': return [self::Kint, self::SIZEOF_SHORT];
            case 'H': return [self::Kuint, self::SIZEOF_SHORT];
            case 'l': return [self::Kint, self::SIZEOF_LONG];
            case 'L': return [self::Kuint, self::SIZEOF_LONG];
            case 'j': return [self::Kint, self::SZINT];
            case 'J': return [self::Kuint, self::SZINT];
            case 'T': return [self::Kuint, self::SIZEOF_SIZE_T];
            case 'f': return [self::Kfloat, 4];
            case 'n': return [self::Knumber, 8];
            case 'd': return [self::Kdouble, 8];
            case 'i': return [self::Kint, $this->getNumberLimit(self::SIZEOF_INT)];
            case 'I': return [self::Kuint, $this->getNumberLimit(self::SIZEOF_INT)];
            case 's': return [self::Kstring, $this->getNumberLimit(self::SIZEOF_SIZE_T)];
            case 'c':
                $size = $this->getNumber(-1);
                if ($size === -1) {
                    Auxiliary::error($this->L, "missing size for format option 'c'");
                }
                return [self::Kchar, $size];
            case 'z': return [self::Kzstr, 0];
            case 'x': return [self::Kpadding, 1];
            case 'X': return [self::Kpaddalign, 0];
            case ' ': break;
            case '<': $this->isLittle = true; break;
            case '>': $this->isLittle = false; break;
            case '=': $this->isLittle = true; break;  // native endianness
            case '!': $this->maxAlign = $this->getNumberLimit(self::NATIVE_MAXALIGN); break;
            default: Auxiliary::error($this->L, "invalid format option '$option'");
        }
        return [self::Knop, 0];
    }

    /**
     * lstrlib.c: getdetails: the next option, its size and the padding
     * that aligns it after $totalSize bytes.
     *
     * @return array{int, int, int} [KOption, size, ntoalign]
     */
    private function getDetails(int $totalSize): array
    {
        [$option, $size] = $this->getOption();
        $align = $size;  // usually, alignment follows size
        if ($option === self::Kpaddalign) {  // 'X' gets alignment from following option
            if ($this->atEnd()) {
                Auxiliary::argError($this->L, 1, "invalid next option for option 'X'");
            }
            [$nextOption, $align] = $this->getOption();
            if ($nextOption === self::Kchar || $align === 0) {
                Auxiliary::argError($this->L, 1, "invalid next option for option 'X'");
            }
        }
        if ($align <= 1 || $option === self::Kchar) {  // need no alignment?
            return [$option, $size, 0];
        }
        if ($align > $this->maxAlign) {  // enforce maximum alignment
            $align = $this->maxAlign;
        }
        if (($align & ($align - 1)) !== 0) {  // not a power of 2?
            Auxiliary::argError($this->L, 1, 'format asks for alignment not power of 2');
        }
        return [$option, $size, ($align - ($totalSize & ($align - 1))) & ($align - 1)];
    }

    /**
     * lstrlib.c: packint: $n in $size bytes; bytes beyond a lua_Integer
     * are the sign extension of negative numbers.
     */
    private function packInteger(int $n, int $size, bool $negative): string
    {
        $bytes = '';
        for ($i = 0; $i < $size; $i++) {
            if ($i < self::SZINT) {
                $bytes .= \chr(($n >> (8 * $i)) & 0xFF);
            } else {
                $bytes .= $negative ? "\xFF" : "\0";
            }
        }
        return $this->isLittle ? $bytes : strrev($bytes);
    }

    /** lstrlib.c: unpackint */
    private function unpackInteger(string $data, int $position, int $size, bool $isSigned): int
    {
        $bytes = substr($data, $position, $size);
        if (!$this->isLittle) {
            $bytes = strrev($bytes);  // now least significant byte first
        }
        $result = 0;
        $limit = min($size, self::SZINT);
        for ($i = $limit - 1; $i >= 0; $i--) {
            $result = ($result << 8) | \ord($bytes[$i]);
        }
        if ($size < self::SZINT) {  // real size smaller than lua_Integer?
            if ($isSigned) {  // needs sign extension?
                $mask = 1 << ($size * 8 - 1);
                $result = ($result ^ $mask) - $mask;  // do sign extension
            }
        } elseif ($size > self::SZINT) {  // must check unread bytes
            $mask = (!$isSigned || $result >= 0) ? 0 : 0xFF;
            for ($i = $limit; $i < $size; $i++) {
                if (\ord($bytes[$i]) !== $mask) {
                    Auxiliary::error($this->L, "$size-byte integer does not fit into Lua Integer");
                }
            }
        }
        return $result;
    }

    /** bytes of a float/double in the header's byte order */
    private function floatBytes(string $nativeBytes): string
    {
        return $this->isLittle ? $nativeBytes : strrev($nativeBytes);
    }

    // lstrlib.c: str_pack
    public static function pack(Coroutine $L, array $args): string
    {
        $header = new self($L, DebugInfo::cString(Auxiliary::checkString($L, $args, 1)));  // format string
        $arg = 1;  // current argument to pack
        $totalSize = 0;  // accumulate total size of result
        $args[] = null;  // mark to separate arguments from string buffer (a missing argument is "nil")
        $result = '';
        while (!$header->atEnd()) {
            [$option, $size, $alignmentPadding] = $header->getDetails($totalSize);
            $totalSize += $alignmentPadding + $size;
            $result .= str_repeat("\0", $alignmentPadding);  // fill alignment
            $arg++;
            switch ($option) {
                case self::Kint:  // signed integers
                    $n = Auxiliary::checkInteger($L, $args, $arg);
                    if ($size < self::SZINT) {  // need overflow check?
                        $limit = 1 << ($size * 8 - 1);
                        Auxiliary::argCheck($L, -$limit <= $n && $n < $limit, $arg, 'integer overflow');
                    }
                    $result .= $header->packInteger($n, $size, $n < 0);
                    break;
                case self::Kuint:  // unsigned integers
                    $n = Auxiliary::checkInteger($L, $args, $arg);
                    if ($size < self::SZINT) {  // need overflow check?
                        Auxiliary::argCheck($L, $n >= 0 && $n < (1 << ($size * 8)), $arg, 'unsigned overflow');
                    }
                    $result .= $header->packInteger($n, $size, false);
                    break;
                case self::Kfloat:  // C float
                    $result .= $header->floatBytes(pack('g', (float) Auxiliary::checkNumber($L, $args, $arg)));
                    break;
                case self::Knumber:  // Lua float
                case self::Kdouble:  // C double
                    $result .= $header->floatBytes(pack('e', (float) Auxiliary::checkNumber($L, $args, $arg)));
                    break;
                case self::Kchar:  // fixed-size string
                    $string = Auxiliary::checkString($L, $args, $arg);
                    $length = \strlen($string);
                    Auxiliary::argCheck($L, $length <= $size, $arg, 'string longer than given size');
                    $result .= $string . str_repeat("\0", $size - $length);  // pad extra space
                    break;
                case self::Kstring:  // strings with length count
                    $string = Auxiliary::checkString($L, $args, $arg);
                    $length = \strlen($string);
                    Auxiliary::argCheck(
                        $L,
                        $size >= self::SIZEOF_SIZE_T || $length < (1 << ($size * 8)),
                        $arg,
                        'string length does not fit in given size',
                    );
                    $result .= $header->packInteger($length, $size, false) . $string;  // pack length, add string
                    $totalSize += $length;
                    break;
                case self::Kzstr:  // zero-terminated string
                    $string = Auxiliary::checkString($L, $args, $arg);
                    Auxiliary::argCheck($L, !str_contains($string, "\0"), $arg, 'string contains zeros');
                    $result .= $string . "\0";  // add zero at the end
                    $totalSize += \strlen($string) + 1;
                    break;
                case self::Kpadding:
                    $result .= "\0";
                    $arg--;  // undo increment
                    break;
                default:  // Kpaddalign, Knop
                    $arg--;  // undo increment
                    break;
            }
        }
        return $result;
    }

    // lstrlib.c: str_packsize
    public static function packsize(Coroutine $L, array $args): int
    {
        $header = new self($L, DebugInfo::cString(Auxiliary::checkString($L, $args, 1)));  // format string
        $totalSize = 0;  // accumulate total size of result
        while (!$header->atEnd()) {
            [$option, $size, $alignmentPadding] = $header->getDetails($totalSize);
            Auxiliary::argCheck($L, $option !== self::Kstring && $option !== self::Kzstr, 1, 'variable-length format');
            $size += $alignmentPadding;  // total space used by option
            Auxiliary::argCheck($L, $totalSize <= self::MAXSIZE - $size, 1, 'format result too large');
            $totalSize += $size;
        }
        return $totalSize;
    }

    /**
     * lstrlib.c: str_unpack: the unpacked values followed by the position
     * after them.
     *
     * @return list<mixed>
     */
    public static function unpack(Coroutine $L, array $args): array
    {
        $header = new self($L, DebugInfo::cString(Auxiliary::checkString($L, $args, 1)));
        $data = Auxiliary::checkString($L, $args, 2);
        $dataLength = \strlen($data);
        $position = StringLib::relativePosition(Auxiliary::optInteger($L, $args, 3, 1), $dataLength) - 1;
        Auxiliary::argCheck($L, $position <= $dataLength, 3, 'initial position out of string');
        $results = [];
        while (!$header->atEnd()) {
            [$option, $size, $alignmentPadding] = $header->getDetails($position);
            Auxiliary::argCheck($L, $alignmentPadding + $size <= $dataLength - $position, 2, 'data string too short');
            $position += $alignmentPadding;  // skip alignment
            // stack space for item + next position
            Auxiliary::checkStack($L, \count($results) + 2, 'too many results');
            switch ($option) {
                case self::Kint:
                case self::Kuint:
                    $results[] = $header->unpackInteger($data, $position, $size, $option === self::Kint);
                    break;
                case self::Kfloat:
                    $results[] = unpack('g', $header->floatBytes(substr($data, $position, 4)))[1];
                    break;
                case self::Knumber:
                case self::Kdouble:
                    $results[] = unpack('e', $header->floatBytes(substr($data, $position, 8)))[1];
                    break;
                case self::Kchar:
                    $results[] = substr($data, $position, $size);
                    break;
                case self::Kstring:
                    $length = $header->unpackInteger($data, $position, $size, false);  // a size_t
                    Auxiliary::argCheck($L, $length >= 0 && $length <= $dataLength - $position - $size, 2, 'data string too short');
                    $results[] = substr($data, $position + $size, $length);
                    $position += $length;  // skip string
                    break;
                case self::Kzstr:
                    $zeroPosition = strpos($data, "\0", $position);
                    $length = ($zeroPosition === false ? $dataLength : $zeroPosition) - $position;
                    Auxiliary::argCheck($L, $position + $length < $dataLength, 2, "unfinished string for format 'z'");
                    $results[] = substr($data, $position, $length);
                    $position += $length + 1;  // skip string plus final '\0'
                    break;
                default:  // Kpaddalign, Kpadding, Knop
                    break;
            }
            $position += $size;
        }
        $results[] = $position + 1;  // next position
        return $results;
    }
}
