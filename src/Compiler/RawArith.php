<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Port of lobject.c luaO_rawarith (with intarith/numarith) and the lvm.c
 * helpers it needs, for lcode.c constant folding. Integers are 64-bit and
 * wrap around like C's unsigned arithmetic ('intop'); PHP would overflow
 * into floats, so + - * go through the wrapping helpers below.
 *
 * Operation codes are lua.h's LUA_OPADD..LUA_OPBNOT ("ORDER TM, ORDER OP").
 *
 * @internal
 */
final class RawArith
{
    public const LUA_OPADD = 0;
    public const LUA_OPSUB = 1;
    public const LUA_OPMUL = 2;
    public const LUA_OPMOD = 3;
    public const LUA_OPPOW = 4;
    public const LUA_OPDIV = 5;
    public const LUA_OPIDIV = 6;
    public const LUA_OPBAND = 7;
    public const LUA_OPBOR = 8;
    public const LUA_OPBXOR = 9;
    public const LUA_OPSHL = 10;
    public const LUA_OPSHR = 11;
    public const LUA_OPUNM = 12;
    public const LUA_OPBNOT = 13;

    // lvm.c: NBITS (number of bits in an integer)
    private const NBITS = 64;

    /**
     * lobject.c: luaO_rawarith. Returns the result, or null when the
     * operands do not allow a raw operation (C returns 0: "fail").
     */
    public static function luaO_rawarith(int $op, int|float $p1, int|float $p2): int|float|null
    {
        switch ($op) {
            case self::LUA_OPBAND:
            case self::LUA_OPBOR:
            case self::LUA_OPBXOR:
            case self::LUA_OPSHL:
            case self::LUA_OPSHR:
            case self::LUA_OPBNOT:  // operate only on integers
                $i1 = is_int($p1) ? $p1 : self::luaV_flttointeger($p1);
                $i2 = is_int($p2) ? $p2 : self::luaV_flttointeger($p2);
                if ($i1 === null || $i2 === null) {
                    return null;  // fail
                }
                return self::intarith($op, $i1, $i2);
            case self::LUA_OPDIV:
            case self::LUA_OPPOW:  // operate only on floats
                return self::numarith($op, (float) $p1, (float) $p2);
            default:  // other operations
                if (is_int($p1) && is_int($p2)) {
                    return self::intarith($op, $p1, $p2);
                }
                return self::numarith($op, (float) $p1, (float) $p2);
        }
    }

    // lobject.c: intarith
    private static function intarith(int $op, int $v1, int $v2): int
    {
        return match ($op) {
            self::LUA_OPADD => self::addWrapping($v1, $v2),
            self::LUA_OPSUB => self::subtractWrapping($v1, $v2),
            self::LUA_OPMUL => self::multiplyWrapping($v1, $v2),
            self::LUA_OPMOD => self::luaV_mod($v1, $v2),
            self::LUA_OPIDIV => self::luaV_idiv($v1, $v2),
            self::LUA_OPBAND => $v1 & $v2,
            self::LUA_OPBOR => $v1 | $v2,
            self::LUA_OPBXOR => $v1 ^ $v2,
            self::LUA_OPSHL => self::luaV_shiftl($v1, $v2),
            self::LUA_OPSHR => self::luaV_shiftl($v1, self::subtractWrapping(0, $v2)),  // luaV_shiftr
            self::LUA_OPUNM => self::subtractWrapping(0, $v1),
            self::LUA_OPBNOT => ~$v1,
        };
    }

    // lobject.c: numarith (with llimits.h luai_num* and lvm.c luaV_modf)
    private static function numarith(int $op, float $v1, float $v2): float
    {
        switch ($op) {
            case self::LUA_OPADD:
                return $v1 + $v2;
            case self::LUA_OPSUB:
                return $v1 - $v2;
            case self::LUA_OPMUL:
                return $v1 * $v2;
            case self::LUA_OPDIV:
                return fdiv($v1, $v2);
            case self::LUA_OPPOW:  // luai_numpow
                return $v2 == 2.0 ? $v1 * $v1 : pow($v1, $v2);
            case self::LUA_OPIDIV:  // luai_numidiv
                return floor(fdiv($v1, $v2));
            case self::LUA_OPUNM:
                return -$v1;
            case self::LUA_OPMOD:  // luaV_modf / luai_nummod
                $remainder = fmod($v1, $v2);
                if ($remainder > 0 ? $v2 < 0 : ($remainder < 0 && $v2 > 0)) {
                    $remainder += $v2;
                }
                return $remainder;
        }
        throw new \LogicException("numarith: bad operation $op");
    }

    /**
     * lvm.c: luaV_flttointeger with mode F2Ieq (the value must be integral
     * and in the range of a lua_Integer); null when it fails.
     */
    public static function luaV_flttointeger(float $n): ?int
    {
        $floor = floor($n);
        if ($n != $floor) {  // not an integral value? (also NaN)
            return null;
        }
        // lua_numbertointeger: -2^63 <= f < 2^63
        if ($floor >= -9223372036854775808.0 && $floor < 9223372036854775808.0) {
            return (int) $floor;
        }
        return null;
    }

    // lvm.c: luaV_idiv (floor division; the caller excludes n == 0)
    private static function luaV_idiv(int $m, int $n): int
    {
        if ($n === -1) {
            return self::subtractWrapping(0, $m);  // avoid overflow with 0x80000...//-1
        }
        $quotient = intdiv($m, $n);  // C division truncates
        if (($m ^ $n) < 0 && $m % $n !== 0) {  // 'm/n' would be negative non-integer?
            $quotient -= 1;  // correct result for different rounding
        }
        return $quotient;
    }

    // lvm.c: luaV_mod (the caller excludes n == 0)
    private static function luaV_mod(int $m, int $n): int
    {
        if ($n === -1) {
            return 0;  // m % -1 == 0; avoid overflow with 0x80000...%-1
        }
        $remainder = $m % $n;
        if ($remainder !== 0 && ($remainder ^ $n) < 0) {  // 'm/n' would be non-integer negative?
            $remainder += $n;  // correct result for different rounding
        }
        return $remainder;
    }

    // lvm.c: luaV_shiftl (negative shifts go right; shifts are logical)
    private static function luaV_shiftl(int $x, int $y): int
    {
        if ($y < 0) {  // shift right?
            if ($y <= -self::NBITS) {
                return 0;
            }
            $count = -$y;
            return ($x >> $count) & (PHP_INT_MAX >> ($count - 1));
        }
        if ($y >= self::NBITS) {  // shift left
            return 0;
        }
        return $x << $y;
    }

    /** C 'intop(+, a, b)': addition modulo 2^64 */
    public static function addWrapping(int $a, int $b): int
    {
        $low = ($a & 0xFFFFFFFF) + ($b & 0xFFFFFFFF);
        $high = ($a >> 32) + ($b >> 32) + ($low >> 32);
        return ($high << 32) | ($low & 0xFFFFFFFF);
    }

    /** C 'intop(-, a, b)': subtraction modulo 2^64 */
    public static function subtractWrapping(int $a, int $b): int
    {
        $low = ($a & 0xFFFFFFFF) - ($b & 0xFFFFFFFF);
        $high = ($a >> 32) - ($b >> 32) + ($low >> 32);
        return ($high << 32) | ($low & 0xFFFFFFFF);
    }

    /** C 'intop(*, a, b)': multiplication modulo 2^64, by 16-bit limbs */
    public static function multiplyWrapping(int $a, int $b): int
    {
        $product = $a * $b;
        if (is_int($product)) {
            return $product;
        }
        $a0 = $a & 0xFFFF;
        $a1 = ($a >> 16) & 0xFFFF;
        $a2 = ($a >> 32) & 0xFFFF;
        $a3 = ($a >> 48) & 0xFFFF;
        $b0 = $b & 0xFFFF;
        $b1 = ($b >> 16) & 0xFFFF;
        $b2 = ($b >> 32) & 0xFFFF;
        $b3 = ($b >> 48) & 0xFFFF;
        $r0 = $a0 * $b0;
        $r1 = $a0 * $b1 + $a1 * $b0 + ($r0 >> 16);
        $r2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0 + ($r1 >> 16);
        $r3 = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0 + ($r2 >> 16);
        return (($r3 & 0xFFFF) << 48) | (($r2 & 0xFFFF) << 32) | (($r1 & 0xFFFF) << 16) | ($r0 & 0xFFFF);
    }
}
