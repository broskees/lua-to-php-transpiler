<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * C (glibc) printf conversions of a double, reproduced exactly: '%.<p>e',
 * '%.<p>f' and '%.<p>g', plus Lua's float-to-string (lobject.c tostringbuff
 * with luaconf.h LUAI_NUMFFORMAT "%.14g").
 *
 * PHP's own sprintf is not C's (%g spells exponents differently and
 * precision is capped at 53), so digits come from the exact decimal
 * expansion of the double, rounded half-to-even on exact ties like glibc
 * in the default rounding mode. inf, -inf, nan and -nan are spelled as
 * glibc spells them; the sign of a NaN is its sign bit.
 *
 * Not yet ported: flags, field width, uppercase conversions and '%a'
 * (string.format will need them).
 */
final class NumberFormat
{
    private const LIMB_BASE = 1000000000;  // 9 decimal digits per limb
    private const LIMB_DIGITS = 9;

    /**
     * lobject.c: tostringbuff (float case): "%.14g", plus ".0" when the
     * result looks like an integer (luac.c PrintConstant does the same).
     */
    public static function luaNumberToString(float $value): string
    {
        $formatted = self::formatG($value, 14);
        if (strspn($formatted, '-0123456789') === strlen($formatted)) {  // looks like an int?
            $formatted .= '.0';
        }
        return $formatted;
    }

    // C printf "%.<precision>e"
    public static function formatE(float $value, int $precision): string
    {
        if (!is_finite($value)) {
            return self::nonFiniteText($value);
        }
        [$digits, $fractionDigitCount] = self::exactDecimal(abs($value));
        return self::signText($value) . self::eStyle($digits, $fractionDigitCount, $precision);
    }

    // C printf "%.<precision>f"
    public static function formatF(float $value, int $precision): string
    {
        if (!is_finite($value)) {
            return self::nonFiniteText($value);
        }
        [$digits, $fractionDigitCount] = self::exactDecimal(abs($value));
        return self::signText($value) . self::fStyle($digits, $fractionDigitCount, $precision);
    }

    /**
     * C printf "%.<precision>g": style e if the exponent X (after rounding
     * to 'precision' significant digits) is < -4 or >= precision, else style
     * f with precision - 1 - X decimals; trailing zeros and a trailing point
     * are removed.
     */
    public static function formatG(float $value, int $precision): string
    {
        if (!is_finite($value)) {
            return self::nonFiniteText($value);
        }
        if ($precision === 0) {
            $precision = 1;
        }
        [$digits, $fractionDigitCount] = self::exactDecimal(abs($value));
        [, $exponent] = self::roundToSignificantDigits($digits, $fractionDigitCount, $precision);
        if ($precision > $exponent && $exponent >= -4) {
            $formatted = self::fStyle($digits, $fractionDigitCount, $precision - 1 - $exponent);
            return self::signText($value) . self::removeTrailingZeros($formatted);
        }
        $formatted = self::eStyle($digits, $fractionDigitCount, $precision - 1);
        $exponentPosition = strpos($formatted, 'e');
        $mantissaText = substr($formatted, 0, $exponentPosition);
        $exponentText = substr($formatted, $exponentPosition);
        return self::signText($value) . self::removeTrailingZeros($mantissaText) . $exponentText;
    }

    private static function eStyle(string $digits, int $fractionDigitCount, int $precision): string
    {
        [$roundedDigits, $exponent] = self::roundToSignificantDigits($digits, $fractionDigitCount, $precision + 1);
        $mantissaText = $roundedDigits[0];
        if ($precision > 0) {
            $mantissaText .= '.' . substr($roundedDigits, 1);
        }
        $exponentText = ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        return $mantissaText . 'e' . $exponentText;
    }

    private static function fStyle(string $digits, int $fractionDigitCount, int $precision): string
    {
        $scaledDigits = self::roundToFractionDigits($digits, $fractionDigitCount, $precision);
        if ($precision === 0) {
            return $scaledDigits;
        }
        $scaledDigits = str_pad($scaledDigits, $precision + 1, '0', STR_PAD_LEFT);
        return substr($scaledDigits, 0, -$precision) . '.' . substr($scaledDigits, -$precision);
    }

    /**
     * Round to $significantDigitCount significant digits.
     * Returns [exactly that many digits, decimal exponent of the first digit].
     *
     * @return array{string, int}
     */
    private static function roundToSignificantDigits(string $digits, int $fractionDigitCount, int $significantDigitCount): array
    {
        if ($digits === '0') {
            return [str_repeat('0', $significantDigitCount), 0];
        }
        $exponent = strlen($digits) - 1 - $fractionDigitCount;
        $keptFractionDigits = $significantDigitCount - 1 - $exponent;
        $roundedDigits = self::roundToFractionDigits($digits, $fractionDigitCount, $keptFractionDigits);
        if (strlen($roundedDigits) > $significantDigitCount) {  // carry made one more digit (9.99 -> 10.0)
            $exponent++;
            $roundedDigits = substr($roundedDigits, 0, $significantDigitCount);
        }
        return [$roundedDigits, $exponent];
    }

    /**
     * The exact value is $digits * 10^-$fractionDigitCount. Round it to
     * $keptFractionDigits digits after the decimal point (negative means
     * rounding to tens, hundreds, ...), half-to-even on exact ties.
     * Returns the rounded value * 10^$keptFractionDigits as a digit string
     * without leading zeros.
     */
    private static function roundToFractionDigits(string $digits, int $fractionDigitCount, int $keptFractionDigits): string
    {
        $droppedDigitCount = $fractionDigitCount - $keptFractionDigits;
        if ($droppedDigitCount <= 0) {
            $scaledDigits = $digits . str_repeat('0', -$droppedDigitCount);
            return ltrim($scaledDigits, '0') === '' ? '0' : ltrim($scaledDigits, '0');
        }
        // keep at least one (zero) digit in front of the dropped ones
        if (strlen($digits) <= $droppedDigitCount) {
            $digits = str_repeat('0', $droppedDigitCount - strlen($digits) + 1) . $digits;
        }
        $keptDigits = substr($digits, 0, -$droppedDigitCount);
        $droppedDigits = substr($digits, -$droppedDigitCount);

        $firstDroppedDigit = $droppedDigits[0];
        if ($firstDroppedDigit > '5') {
            $roundUp = true;
        } elseif ($firstDroppedDigit < '5') {
            $roundUp = false;
        } elseif (ltrim(substr($droppedDigits, 1), '0') !== '') {
            $roundUp = true;  // above the half
        } else {
            $lastKeptDigit = (int) $keptDigits[strlen($keptDigits) - 1];
            $roundUp = $lastKeptDigit % 2 === 1;  // exact tie: round half to even
        }
        if ($roundUp) {
            $keptDigits = self::incrementDigits($keptDigits);
        }
        $keptDigits = ltrim($keptDigits, '0');
        return $keptDigits === '' ? '0' : $keptDigits;
    }

    private static function incrementDigits(string $digits): string
    {
        for ($position = strlen($digits) - 1; $position >= 0; $position--) {
            if ($digits[$position] !== '9') {
                $digits[$position] = (string) ((int) $digits[$position] + 1);
                return $digits;
            }
            $digits[$position] = '0';
        }
        return '1' . $digits;
    }

    /**
     * Exact decimal expansion of a finite, non-negative double.
     * Returns [$digits, $fractionDigitCount] with value = $digits * 10^-$fractionDigitCount.
     *
     * @return array{string, int}
     */
    private static function exactDecimal(float $absoluteValue): array
    {
        $bits = unpack('P', pack('e', $absoluteValue))[1];
        $biasedExponent = ($bits >> 52) & 0x7ff;
        $mantissa = $bits & 0xFFFFFFFFFFFFF;
        if ($biasedExponent === 0) {
            $binaryExponent = -1074;  // subnormal
        } else {
            $mantissa |= 1 << 52;
            $binaryExponent = $biasedExponent - 1075;
        }
        if ($mantissa === 0) {
            return ['0', 0];
        }
        // value = mantissa * 2^binaryExponent; drop trailing zero bits to keep the numbers small
        while ($binaryExponent < 0 && ($mantissa & 1) === 0) {
            $mantissa >>= 1;
            $binaryExponent++;
        }
        if ($binaryExponent >= 0) {
            return [self::multiplyByPower($mantissa, 2, $binaryExponent), 0];
        }
        // mantissa / 2^n = mantissa * 5^n / 10^n
        return [self::multiplyByPower($mantissa, 5, -$binaryExponent), -$binaryExponent];
    }

    /**
     * Decimal digits of $start * $base^$exponent, computed with base-10^9
     * limbs (least significant first). $base is 2 or 5.
     */
    private static function multiplyByPower(int $start, int $base, int $exponent): string
    {
        $limbs = [];
        while ($start > 0) {
            $limbs[] = $start % self::LIMB_BASE;
            $start = intdiv($start, self::LIMB_BASE);
        }
        // largest power of the base that keeps limb * factor + carry within 63 bits
        $maximumStep = $base === 2 ? 30 : 13;
        while ($exponent > 0) {
            $step = min($exponent, $maximumStep);
            $factor = $base ** $step;
            $carry = 0;
            foreach ($limbs as $limbIndex => $limb) {
                $product = $limb * $factor + $carry;
                $limbs[$limbIndex] = $product % self::LIMB_BASE;
                $carry = intdiv($product, self::LIMB_BASE);
            }
            while ($carry > 0) {
                $limbs[] = $carry % self::LIMB_BASE;
                $carry = intdiv($carry, self::LIMB_BASE);
            }
            $exponent -= $step;
        }
        $digits = (string) $limbs[count($limbs) - 1];
        for ($limbIndex = count($limbs) - 2; $limbIndex >= 0; $limbIndex--) {
            $digits .= str_pad((string) $limbs[$limbIndex], self::LIMB_DIGITS, '0', STR_PAD_LEFT);
        }
        return $digits;
    }

    private static function removeTrailingZeros(string $numberText): string
    {
        if (!str_contains($numberText, '.')) {
            return $numberText;
        }
        return rtrim(rtrim($numberText, '0'), '.');
    }

    private static function hasSignBit(float $value): bool
    {
        return (ord(pack('E', $value)[0]) & 0x80) !== 0;
    }

    private static function signText(float $value): string
    {
        return self::hasSignBit($value) ? '-' : '';
    }

    // glibc spellings: inf, -inf, nan, -nan
    private static function nonFiniteText(float $value): string
    {
        return self::signText($value) . (is_nan($value) ? 'nan' : 'inf');
    }
}
