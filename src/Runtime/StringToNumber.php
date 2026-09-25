<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Port of lobject.c luaO_str2num (l_str2int, l_str2d, l_str2dloc), as used by
 * tonumber(s), string->number coercions and the lexer's read_numeral.
 *
 * Returns the int or float the whole string denotes, or null when it is not
 * a numeral. Callers in C (lua_stringtonumber, l_strton) accept a numeral
 * only when it spans the entire string, so a string with an embedded '\0'
 * is never a numeral.
 *
 * On this platform lua_str2number and lua_strx2number are both C99 strtod
 * (luaconf.h), which rounds decimal and hexadecimal numerals correctly.
 * Decimal digits go through PHP's float conversion (zend_strtod, also
 * correctly rounded); hexadecimal floats are rounded here, half to even.
 */
final class StringToNumber
{
    // lctype.c: lisspace (' ', '\t', '\n', '\v', '\f', '\r'; same as C isspace in the "C" locale)
    private const SPACE_CHARACTERS = " \t\n\x0B\x0C\r";

    // lobject.c: MAXBY10 (LUA_MAXINTEGER / 10) and MAXLASTD (LUA_MAXINTEGER % 10)
    private const MAXBY10 = 922337203685477580;
    private const MAXLASTD = 7;

    // strtod's decimal and hexadecimal numeral syntax, surrounded by isspace characters
    private const DECIMAL_NUMERAL = '/^[\x09-\x0D ]*([+-]?)((?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)[\x09-\x0D ]*$/D';
    private const HEXADECIMAL_NUMERAL = '/^[\x09-\x0D ]*([+-]?)0[xX]([0-9a-fA-F]*)(?:\.([0-9a-fA-F]*))?(?:[pP]([+-]?[0-9]+))?[\x09-\x0D ]*$/D';

    // lobject.c: luaO_str2num
    public static function convert(string $s): int|float|null
    {
        // Fast path: 1 to 18 decimal digits and nothing else is a decimal
        // integer numeral below 10^18, so it cannot overflow: l_str2int
        // accepts it with this value (leading zeros included).
        if (\strlen($s) <= 18 && ctype_digit($s)) {
            return (int) $s;
        }
        if (str_contains($s, "\0")) {
            return null;  // the C string would end early; callers require the whole length
        }
        $integer = self::l_str2int($s);
        if ($integer !== null) {  // try as an integer
            return $integer;
        }
        return self::l_str2d($s);  // else try as a float
    }

    // lobject.c: l_str2int (decimal overflow fails; hexadecimal wraps around)
    private static function l_str2int(string $s): ?int
    {
        $length = strlen($s);
        $position = strspn($s, self::SPACE_CHARACTERS);  // skip initial spaces
        $negative = false;  // lobject.c: isneg
        if ($position < $length && $s[$position] === '-') {
            $negative = true;
            $position++;
        } elseif ($position < $length && $s[$position] === '+') {
            $position++;
        }
        $accumulator = 0;  // lua_Unsigned bit pattern
        $empty = true;
        $isHexadecimal = ($s[$position] ?? '') === '0'
            && (($s[$position + 1] ?? '') === 'x' || ($s[$position + 1] ?? '') === 'X');
        if ($isHexadecimal) {
            $position += 2;  // skip '0x'
            while ($position < $length && ctype_xdigit($s[$position])) {
                $accumulator = ($accumulator << 4) | hexdec($s[$position]);  // wraps modulo 2^64
                $empty = false;
                $position++;
            }
        } else {
            while ($position < $length && ctype_digit($s[$position])) {
                $digit = ord($s[$position]) - 48;
                if ($accumulator >= self::MAXBY10
                    && ($accumulator > self::MAXBY10 || $digit > self::MAXLASTD + ($negative ? 1 : 0))) {
                    return null;  // overflow: do not accept it (as integer)
                }
                // only "-9223372036854775808" reaches 2^63, whose bit pattern is PHP_INT_MIN
                $accumulator = ($accumulator === self::MAXBY10 && $digit === 8)
                    ? PHP_INT_MIN
                    : $accumulator * 10 + $digit;
                $empty = false;
                $position++;
            }
        }
        $position += strspn($s, self::SPACE_CHARACTERS, $position);  // skip trailing spaces
        if ($empty || $position !== $length) {
            return null;  // something wrong in the numeral
        }
        if (!$negative) {
            return $accumulator;
        }
        return $accumulator === PHP_INT_MIN ? PHP_INT_MIN : -$accumulator;  // 0u - a
    }

    // lobject.c: l_str2d (strtod; 'inf' and 'nan' are rejected)
    private static function l_str2d(string $s): ?float
    {
        $specialPosition = strcspn($s, '.xXnN');  // strpbrk(s, ".xXnN")
        if ($specialPosition < strlen($s) && ($s[$specialPosition] === 'n' || $s[$specialPosition] === 'N')) {
            return null;  // reject 'inf' and 'nan'
        }
        if (preg_match(self::HEXADECIMAL_NUMERAL, $s, $match) === 1) {
            return self::hexadecimalToFloat($match[1] === '-', $match[2], $match[3] ?? '', $match[4] ?? '');
        }
        if (preg_match(self::DECIMAL_NUMERAL, $s, $match) === 1) {
            $magnitude = (float) $match[2];
            return $match[1] === '-' ? -$magnitude : $magnitude;
        }
        return null;
    }

    /**
     * strtod for "0x<integerDigits>.<fractionDigits>p<exponent>", rounded to
     * nearest, ties to even, with gradual underflow and overflow to infinity.
     */
    private static function hexadecimalToFloat(bool $negative, string $integerDigits, string $fractionDigits, string $exponentText): ?float
    {
        $allDigits = $integerDigits . $fractionDigits;
        if ($allDigits === '') {
            return null;  // "0x" or "0x." has no digits
        }
        $binaryExponent = self::clampedExponent($exponentText) - 4 * strlen($fractionDigits);

        $significantDigits = ltrim($allDigits, '0');
        if ($significantDigits === '') {
            return $negative ? -0.0 : 0.0;
        }
        $bits = '';
        foreach (str_split($significantDigits) as $hexDigit) {
            $bits .= str_pad(decbin(hexdec($hexDigit)), 4, '0', STR_PAD_LEFT);
        }
        $bits = ltrim($bits, '0');
        $bitCount = strlen($bits);
        // value = bits * 2^binaryExponent; its leading bit has weight 2^topExponent
        $topExponent = $binaryExponent + $bitCount - 1;
        if ($topExponent > 1023) {
            return $negative ? -INF : INF;
        }

        $isNormal = $topExponent >= -1022;
        // bits kept: 53 for normal numbers; down to weight 2^-1074 for subnormals
        $keptBitCount = $isNormal ? 53 : $topExponent + 1075;
        if ($keptBitCount >= $bitCount) {
            $mantissa = bindec($bits) << ($keptBitCount - $bitCount);  // exact
        } elseif ($keptBitCount >= 0) {
            $mantissa = $keptBitCount === 0 ? 0 : bindec(substr($bits, 0, $keptBitCount));
            $roundBit = $bits[$keptBitCount] === '1';
            $stickyBits = str_contains(substr($bits, $keptBitCount + 1), '1');
            if ($roundBit && ($stickyBits || ($mantissa & 1) === 1)) {
                $mantissa++;
            }
        } else {
            $mantissa = 0;  // below half of the smallest subnormal
        }

        if ($isNormal) {
            if ($mantissa === 1 << 53) {  // rounding carried into a new bit
                $mantissa >>= 1;
                $topExponent++;
                if ($topExponent > 1023) {
                    return $negative ? -INF : INF;
                }
            }
            $bitPattern = (($topExponent + 1023) << 52) | ($mantissa & ((1 << 52) - 1));
        } else {
            // subnormal: the mantissa is in units of 2^-1074; a carry into
            // bit 52 is exactly the smallest normal number's bit pattern
            $bitPattern = $mantissa;
        }
        $magnitude = unpack('E', pack('J', $bitPattern))[1];
        return $negative ? -$magnitude : $magnitude;
    }

    /** A decimal exponent, saturated far beyond any double's range. */
    private static function clampedExponent(string $exponentText): int
    {
        if ($exponentText === '') {
            return 0;
        }
        $negative = $exponentText[0] === '-';
        $digits = ltrim($exponentText, '+-');
        $digits = ltrim($digits, '0');
        $saturation = 1 << 40;
        $magnitude = strlen($digits) > 12 ? $saturation : min((int) $digits, $saturation);
        return $negative ? -$magnitude : $magnitude;
    }
}
