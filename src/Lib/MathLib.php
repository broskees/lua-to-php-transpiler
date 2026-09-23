<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\Vm;

/**
 * Port of lmathlib.c: the math library, including the LUA_COMPAT_MATHLIB
 * functions (the reference build defines LUA_COMPAT_5_3).
 *
 * Not ported yet: math.random and math.randomseed (xoshiro256**, Phase 2).
 */
final class MathLib
{
    // lmathlib.c: luaopen_math
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'abs' => self::abs(...),
            'acos' => static fn (Coroutine $L, array $args): array => [acos((float) Auxiliary::checkNumber($L, $args, 1))],
            'asin' => static fn (Coroutine $L, array $args): array => [asin((float) Auxiliary::checkNumber($L, $args, 1))],
            'atan' => self::atan(...),
            'ceil' => self::ceil(...),
            'cos' => static fn (Coroutine $L, array $args): array => [cos((float) Auxiliary::checkNumber($L, $args, 1))],
            'deg' => static fn (Coroutine $L, array $args): array => [(float) Auxiliary::checkNumber($L, $args, 1) * (180.0 / M_PI)],
            'exp' => static fn (Coroutine $L, array $args): array => [exp((float) Auxiliary::checkNumber($L, $args, 1))],
            'tointeger' => self::tointeger(...),
            'floor' => self::floor(...),
            'fmod' => self::fmod(...),
            'ult' => self::ult(...),
            'log' => self::log(...),
            'max' => self::max(...),
            'min' => self::min(...),
            'modf' => self::modf(...),
            'rad' => static fn (Coroutine $L, array $args): array => [(float) Auxiliary::checkNumber($L, $args, 1) * (M_PI / 180.0)],
            'sin' => static fn (Coroutine $L, array $args): array => [sin((float) Auxiliary::checkNumber($L, $args, 1))],
            'sqrt' => static fn (Coroutine $L, array $args): array => [sqrt((float) Auxiliary::checkNumber($L, $args, 1))],
            'tan' => static fn (Coroutine $L, array $args): array => [tan((float) Auxiliary::checkNumber($L, $args, 1))],
            'type' => self::type(...),
            // LUA_COMPAT_MATHLIB
            'atan2' => self::atan(...),
            'cosh' => static fn (Coroutine $L, array $args): array => [cosh((float) Auxiliary::checkNumber($L, $args, 1))],
            'sinh' => static fn (Coroutine $L, array $args): array => [sinh((float) Auxiliary::checkNumber($L, $args, 1))],
            'tanh' => static fn (Coroutine $L, array $args): array => [tanh((float) Auxiliary::checkNumber($L, $args, 1))],
            'pow' => static fn (Coroutine $L, array $args): array => [fpow((float) Auxiliary::checkNumber($L, $args, 1), (float) Auxiliary::checkNumber($L, $args, 2))],
            'frexp' => self::frexpFunction(...),
            'ldexp' => self::ldexpFunction(...),
            'log10' => static fn (Coroutine $L, array $args): array => [log10((float) Auxiliary::checkNumber($L, $args, 1))],
        ]);
        $library->hash['pi'] = M_PI;
        $library->hash['huge'] = INF;
        $library->hash['maxinteger'] = Lua::LUA_MAXINTEGER;
        $library->hash['mininteger'] = Lua::LUA_MININTEGER;
        return $library;
    }

    // lmathlib.c: math_abs
    private static function abs(Coroutine $L, array $args): array
    {
        $value = $args[0] ?? null;
        if (\is_int($value)) {
            return [$value < 0 ? Vm::negWrap($value) : $value];
        }
        return [abs((float) Auxiliary::checkNumber($L, $args, 1))];
    }

    // lmathlib.c: math_atan
    private static function atan(Coroutine $L, array $args): array
    {
        $y = (float) Auxiliary::checkNumber($L, $args, 1);
        $x = (float) Auxiliary::optNumber($L, $args, 2, 1.0);
        return [atan2($y, $x)];
    }

    // lmathlib.c: math_toint
    private static function tointeger(Coroutine $L, array $args): array
    {
        $integer = Vm::toInteger($args[0] ?? null);
        if ($integer !== null) {
            return [$integer];
        }
        Auxiliary::checkAny($L, $args, 1);
        return [null];  // value is not convertible to integer
    }

    // lmathlib.c: pushnumint
    private static function numberAsInteger(float $number): int|float
    {
        if ($number >= -9.2233720368547758E18 && $number < 9.2233720368547758E18) {  // does 'd' fit in an integer?
            return (int) $number;
        }
        return $number;
    }

    // lmathlib.c: math_floor
    private static function floor(Coroutine $L, array $args): array
    {
        $value = $args[0] ?? null;
        if (\is_int($value)) {
            return [$value];  // integer is its own floor
        }
        return [self::numberAsInteger(floor((float) Auxiliary::checkNumber($L, $args, 1)))];
    }

    // lmathlib.c: math_ceil
    private static function ceil(Coroutine $L, array $args): array
    {
        $value = $args[0] ?? null;
        if (\is_int($value)) {
            return [$value];  // integer is its own ceil
        }
        return [self::numberAsInteger(ceil((float) Auxiliary::checkNumber($L, $args, 1)))];
    }

    // lmathlib.c: math_fmod
    private static function fmod(Coroutine $L, array $args): array
    {
        $a = $args[0] ?? null;
        $d = $args[1] ?? null;
        if (\is_int($a) && \is_int($d)) {
            if ($d === 0 || $d === -1) {  // special cases: -1 or 0
                Auxiliary::argCheck($L, $d !== 0, 2, 'zero');
                return [0];  // avoid overflow with 0x80000... / -1
            }
            return [$a % $d];
        }
        return [fmod((float) Auxiliary::checkNumber($L, $args, 1), (float) Auxiliary::checkNumber($L, $args, 2))];
    }

    // lmathlib.c: math_modf
    private static function modf(Coroutine $L, array $args): array
    {
        $value = $args[0] ?? null;
        if (\is_int($value)) {
            return [$value, 0.0];  // number is its own integer part, no fractional part
        }
        $number = (float) Auxiliary::checkNumber($L, $args, 1);
        // integer part (rounds toward zero)
        $integerPart = $number < 0 ? ceil($number) : floor($number);
        // fractional part (test needed for inf/-inf)
        return [self::numberAsInteger($integerPart), $number == $integerPart ? 0.0 : $number - $integerPart];
    }

    // lmathlib.c: math_ult
    private static function ult(Coroutine $L, array $args): array
    {
        $a = Auxiliary::checkInteger($L, $args, 1);
        $b = Auxiliary::checkInteger($L, $args, 2);
        return [Vm::unsignedLess($a, $b)];
    }

    // lmathlib.c: math_log
    private static function log(Coroutine $L, array $args): array
    {
        $x = (float) Auxiliary::checkNumber($L, $args, 1);
        if (($args[1] ?? null) === null) {
            return [log($x)];
        }
        $base = (float) Auxiliary::checkNumber($L, $args, 2);
        if ($base == 2.0) {
            return [self::log2($x)];
        }
        if ($base == 10.0) {
            return [log10($x)];
        }
        return [log($x) / log($base)];
    }

    /** C log2, exact for powers of two */
    private static function log2(float $x): float
    {
        if (!($x > 0) || is_infinite($x)) {
            return log($x) / M_LN2;  // nan, -inf or inf
        }
        [$mantissa, $exponent] = self::frexp($x);
        if ($mantissa === 0.5) {
            return (float) ($exponent - 1);
        }
        return $exponent + log($mantissa) / M_LN2;
    }

    // lmathlib.c: math_min
    private static function min(Coroutine $L, array $args): array
    {
        $count = \count($args);
        Auxiliary::argCheck($L, $count >= 1, 1, 'value expected');
        $minimum = $args[0];
        for ($i = 1; $i < $count; $i++) {
            if (Vm::lessThan($L, $args[$i], $minimum)) {
                $minimum = $args[$i];
            }
        }
        return [$minimum];
    }

    // lmathlib.c: math_max
    private static function max(Coroutine $L, array $args): array
    {
        $count = \count($args);
        Auxiliary::argCheck($L, $count >= 1, 1, 'value expected');
        $maximum = $args[0];
        for ($i = 1; $i < $count; $i++) {
            if (Vm::lessThan($L, $maximum, $args[$i])) {
                $maximum = $args[$i];
            }
        }
        return [$maximum];
    }

    // lmathlib.c: math_type
    private static function type(Coroutine $L, array $args): array
    {
        $value = $args[0] ?? null;
        if (\is_int($value)) {
            return ['integer'];
        }
        if (\is_float($value)) {
            return ['float'];
        }
        Auxiliary::checkAny($L, $args, 1);
        return [null];
    }

    /**
     * C frexp: [m, e] with x = m * 2^e and 0.5 <= |m| < 1 (x itself and 0
     * for zero, infinities and NaN).
     *
     * @return array{float, int}
     */
    public static function frexp(float $x): array
    {
        if ($x == 0.0 || !is_finite($x)) {
            return [$x, 0];
        }
        $bits = unpack('q', pack('d', $x))[1];
        $exponentField = ($bits >> 52) & 0x7FF;
        $adjustment = 0;
        if ($exponentField === 0) {  // subnormal: scale into the normal range first
            $x *= 18014398509481984.0;  // 2^54
            $bits = unpack('q', pack('d', $x))[1];
            $exponentField = ($bits >> 52) & 0x7FF;
            $adjustment = -54;
        }
        $exponent = $exponentField - 1022 + $adjustment;
        $mantissaBits = ($bits & ~(0x7FF << 52)) | (1022 << 52);
        return [unpack('d', pack('q', $mantissaBits))[1], $exponent];
    }

    /** C ldexp / scalbn: x * 2^n with a single rounding (musl scalbn) */
    public static function ldexp(float $x, int $n): float
    {
        $twoTo1023 = unpack('d', pack('q', 0x7FE << 52))[1];
        $twoToMinus1022TimesTwoTo53 = unpack('d', pack('q', (0x001 + 53) << 52))[1];  // 2^-1022 * 2^53
        $y = $x;
        if ($n > 1023) {
            $y *= $twoTo1023;
            $n -= 1023;
            if ($n > 1023) {
                $y *= $twoTo1023;
                $n -= 1023;
                if ($n > 1023) {
                    $n = 1023;
                }
            }
        } elseif ($n < -1022) {
            // make sure final n < -53 to avoid double rounding in the subnormal range
            $y *= $twoToMinus1022TimesTwoTo53;
            $n += 1022 - 53;
            if ($n < -1022) {
                $y *= $twoToMinus1022TimesTwoTo53;
                $n += 1022 - 53;
                if ($n < -1022) {
                    $n = -1022;
                }
            }
        }
        return $y * unpack('d', pack('q', (0x3FF + $n) << 52))[1];
    }

    // lmathlib.c: math_frexp
    private static function frexpFunction(Coroutine $L, array $args): array
    {
        return self::frexp((float) Auxiliary::checkNumber($L, $args, 1));
    }

    // lmathlib.c: math_ldexp
    private static function ldexpFunction(Coroutine $L, array $args): array
    {
        $x = (float) Auxiliary::checkNumber($L, $args, 1);
        $exponent = Auxiliary::checkInteger($L, $args, 2);
        // C casts to int
        $exponent = (($exponent & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
        return [self::ldexp($x, $exponent)];
    }
}
