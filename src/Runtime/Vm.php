<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Port of lvm.c (plus the arithmetic of lobject.c: luaO_rawarith) for the
 * operations emitted code does not finish inline: table access with
 * metamethods, arithmetic corner cases with C-exact 64-bit wraparound,
 * exact int/float comparisons, equality, concatenation, length and
 * numeric for-loop preparation.
 *
 * Integers are PHP ints, which overflow into floats; every integer
 * operation here wraps like C's unsigned arithmetic instead.
 *
 * "Slot" parameters say where an operand came from, for error messages
 * (ldebug.c: varinfo): see DebugInfo::NO_SLOT / DebugInfo::upvalueSlot().
 */
final class Vm
{
    // lvm.h: F2Imod
    public const F2Ieq = 0;
    public const F2Ifloor = 1;
    public const F2Iceil = 2;

    // lvm.c: MAXTAGLOOP
    public const MAXTAGLOOP = 2000;

    // lua.h: arithmetic operators (ORDER TM, ORDER OP)
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

    /*
    ** {==================================================================
    ** Integer arithmetic with C wraparound
    ** ===================================================================
    */

    /** intop(+, x, y) */
    public static function addWrap(int $x, int $y): int
    {
        $sum = $x + $y;
        if (\is_int($sum)) {
            return $sum;
        }
        $low = ($x & 0xFFFFFFFF) + ($y & 0xFFFFFFFF);
        $high = (($x >> 32) + ($y >> 32) + ($low >> 32)) & 0xFFFFFFFF;
        return ($high << 32) | ($low & 0xFFFFFFFF);
    }

    /** intop(-, x, y) */
    public static function subWrap(int $x, int $y): int
    {
        $difference = $x - $y;
        if (\is_int($difference)) {
            return $difference;
        }
        $low = ($x & 0xFFFFFFFF) - ($y & 0xFFFFFFFF);
        $high = (($x >> 32) - ($y >> 32) + ($low >> 32)) & 0xFFFFFFFF;
        return ($high << 32) | ($low & 0xFFFFFFFF);
    }

    /** intop(*, x, y): the low 64 bits of the product */
    public static function mulWrap(int $x, int $y): int
    {
        $product = $x * $y;
        if (\is_int($product)) {
            return $product;
        }
        $xLow = $x & 0xFFFFFFFF;
        $xHigh = ($x >> 32) & 0xFFFFFFFF;
        $yLow = $y & 0xFFFFFFFF;
        $yHigh = ($y >> 32) & 0xFFFFFFFF;
        // xLow * yLow as a full 64-bit value, from 16-bit pieces
        $a0 = $xLow & 0xFFFF;
        $a1 = $xLow >> 16;
        $b0 = $yLow & 0xFFFF;
        $b1 = $yLow >> 16;
        $cross = $a1 * $b0 + $a0 * $b1;                         // < 2^33
        $lowWord = $a0 * $b0 + (($cross & 0xFFFF) << 16);        // < 2^33
        $highWord = $a1 * $b1 + ($cross >> 16) + ($lowWord >> 32);
        // add the cross products of the high halves, modulo 2^32
        $highWord += self::mulLow32($xHigh, $yLow) + self::mulLow32($xLow, $yHigh);
        return (($highWord & 0xFFFFFFFF) << 32) | ($lowWord & 0xFFFFFFFF);
    }

    /** (a * b) mod 2^32 for 0 <= a, b < 2^32 */
    private static function mulLow32(int $a, int $b): int
    {
        $productLow = $a * ($b & 0xFFFF);                        // < 2^48
        $productHigh = (($a * ($b >> 16)) & 0xFFFF) << 16;       // < 2^32
        return ($productLow + $productHigh) & 0xFFFFFFFF;
    }

    /** intop(-, 0, x) */
    public static function negWrap(int $x): int
    {
        return $x === PHP_INT_MIN ? PHP_INT_MIN : -$x;
    }

    // lvm.c: luaV_idiv
    public static function idiv(Coroutine $L, int $m, int $n): int
    {
        if ($n === 0 || $n === -1) {  // special cases: -1 or 0
            if ($n === 0) {
                DebugInfo::runError($L, 'attempt to divide by zero');
            }
            return self::negWrap($m);  // n==-1; avoid overflow with 0x80000...//-1
        }
        $quotient = intdiv($m, $n);  // C division truncates
        if (($m ^ $n) < 0 && $m % $n !== 0) {  // 'm/n' would be negative non-integer?
            $quotient -= 1;  // correct result for different rounding
        }
        return $quotient;
    }

    // lvm.c: luaV_mod
    public static function mod(Coroutine $L, int $m, int $n): int
    {
        if ($n === 0 || $n === -1) {  // special cases: -1 or 0
            if ($n === 0) {
                DebugInfo::runError($L, "attempt to perform 'n%0'");  // C: "'n%%0'" in a format string
            }
            return 0;  // m % -1 == 0; avoid overflow with 0x80000...%-1
        }
        $remainder = $m % $n;
        if ($remainder !== 0 && ($remainder ^ $n) < 0) {  // 'm/n' would be non-integer negative?
            $remainder += $n;  // correct result for different rounding
        }
        return $remainder;
    }

    // lvm.c: luaV_modf (llimits.h: luai_nummod)
    public static function modf(float $m, float $n): float
    {
        $remainder = fmod($m, $n);
        if (($remainder > 0) ? $n < 0 : ($remainder < 0 && $n > 0)) {
            $remainder += $n;
        }
        return $remainder;
    }

    // llimits.h: luai_numidiv
    public static function floatIdiv(float $m, float $n): float
    {
        return floor(fdiv($m, $n));
    }

    // llimits.h: luai_numpow
    public static function floatPow(float $base, float $exponent): float
    {
        return $exponent == 2.0 ? $base * $base : fpow($base, $exponent);
    }

    // lvm.c: luaV_shiftl
    public static function shiftLeft(int $x, int $y): int
    {
        if ($y < 0) {  // shift right?
            if ($y <= -64) {
                return 0;
            }
            return self::logicalShiftRight($x, -$y);
        }
        if ($y >= 64) {
            return 0;
        }
        return $x << $y;
    }

    // lvm.h: luaV_shiftr
    public static function shiftRight(int $x, int $y): int
    {
        return self::shiftLeft($x, self::negWrap($y));
    }

    /** C '>>' on lua_Unsigned, for 0 < $count < 64 */
    private static function logicalShiftRight(int $x, int $count): int
    {
        return ($x >> $count) & (PHP_INT_MAX >> ($count - 1));
    }

    /** l_castS2U(a) < l_castS2U(b) */
    public static function unsignedLess(int $a, int $b): bool
    {
        return ($a ^ PHP_INT_MIN) < ($b ^ PHP_INT_MIN);
    }

    /** l_castS2U(a) / l_castS2U(b), b != 0 */
    public static function unsignedDivide(int $dividend, int $divisor): int
    {
        if ($divisor < 0) {  // divisor >= 2^63: quotient is 0 or 1
            return self::unsignedLess($dividend, $divisor) ? 0 : 1;
        }
        if ($dividend >= 0) {
            return intdiv($dividend, $divisor);
        }
        $quotient = intdiv(($dividend >> 1) & PHP_INT_MAX, $divisor) << 1;
        $remainder = self::subWrap($dividend, self::mulWrap($quotient, $divisor));
        if (!self::unsignedLess($remainder, $divisor)) {
            $quotient++;
        }
        return $quotient;
    }

    /* }================================================================== */

    /*
    ** {==================================================================
    ** Conversions
    ** ===================================================================
    */

    // lvm.c: luaV_flttointeger
    public static function floatToIntegerMode(float $number, int $mode): ?int
    {
        $floor = floor($number);
        if ($number != $floor) {  // not an integral value?
            if ($mode === self::F2Ieq) {
                return null;  // fails if mode demands integral value
            }
            if ($mode === self::F2Iceil) {  // needs ceil?
                $floor += 1;  // convert floor to ceil (remember: n != f)
            }
        }
        // luaconf.h: lua_numbertointeger
        if ($floor >= -9.2233720368547758E18 && $floor < 9.2233720368547758E18) {
            return (int) $floor;
        }
        return null;
    }

    /** lvm.c: luaV_tointegerns (no string coercion) */
    public static function toIntegerNoString(mixed $value, int $mode = self::F2Ieq): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return self::floatToIntegerMode($value, $mode);
        }
        return null;
    }

    /** lvm.c: luaV_tointeger (strings are coerced) */
    public static function toInteger(mixed $value, int $mode = self::F2Ieq): ?int
    {
        if (\is_string($value)) {
            $value = StringToNumber::convert($value);
        }
        return self::toIntegerNoString($value, $mode);
    }

    /**
     * The number a value converts to (lvm.c: l_strton + tonumber, keeping
     * integers as integers): numbers as they are, numeric strings
     * converted (lobject.c: luaO_str2num), anything else null.
     */
    public static function toNumber(mixed $value): int|float|null
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return StringToNumber::convert($value);
        }
        return null;
    }

    /** lvm.h: tonumber / luaV_tonumber_ (result as a float) */
    public static function toFloat(mixed $value): ?float
    {
        $number = self::toNumber($value);
        return $number === null ? null : (float) $number;
    }

    /* }================================================================== */

    /*
    ** {==================================================================
    ** Table access
    ** ===================================================================
    */

    /** lvm.c: luaV_finishget, entered with a non-table or a table whose raw t[key] is nil */
    public static function finishGet(Coroutine $L, mixed $t, mixed $key, int $slot): mixed
    {
        // Fast path for a method lookup: a string key found in the '__index'
        // table of a table's metatable or of the string metatable is what
        // the first iteration below returns.
        if (\is_string($key)) {
            $metatable = $t instanceof LuaTable ? $t->metatable : (\is_string($t) ? ($L->globalState->typeMetatables[Lua::LUA_TSTRING] ?? null) : null);
            $tm = $metatable?->hash['__index'] ?? null;
            if ($tm instanceof LuaTable && isset($tm->hash[$key])) {
                return $tm->hash[$key];
            }
        }
        for ($loop = 0; $loop < self::MAXTAGLOOP; $loop++) {
            if ($t instanceof LuaTable) {
                $metatable = $t->metatable;
                $tm = $metatable === null ? null : ($metatable->hash['__index'] ?? null);
                if ($tm === null) {
                    return null;  // no metamethod: result is nil
                }
            } else {
                $tm = MetaMethods::getByObject($L, $t, MetaMethods::TM_INDEX);
                if ($tm === null) {
                    DebugInfo::typeError($L, $t, 'index', $loop === 0 ? $slot : DebugInfo::NO_SLOT);
                }
            }
            if ($tm instanceof LuaClosure || $tm instanceof NativeFunction) {  // is metamethod a function?
                return MetaMethods::callTMres($L, $tm, $t, $key);
            }
            $t = $tm;  // else try to access 'tm[key]'
            if ($t instanceof LuaTable) {
                $value = $t->get($key);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        DebugInfo::runError($L, "'__index' chain too long; possible loop");
    }

    /** lvm.c: luaV_finishset, entered with a non-table or a table whose raw t[key] is nil */
    public static function finishSet(Coroutine $L, mixed $t, mixed $key, mixed $value, int $slot): void
    {
        for ($loop = 0; $loop < self::MAXTAGLOOP; $loop++) {
            if ($t instanceof LuaTable) {
                $metatable = $t->metatable;
                $tm = $metatable === null ? null : ($metatable->hash['__newindex'] ?? null);
                if ($tm === null) {  // no metamethod?
                    self::rawSet($L, $t, $key, $value);
                    return;
                }
            } else {
                $tm = MetaMethods::getByObject($L, $t, MetaMethods::TM_NEWINDEX);
                if ($tm === null) {
                    DebugInfo::typeError($L, $t, 'index', $loop === 0 ? $slot : DebugInfo::NO_SLOT);
                }
            }
            if ($tm instanceof LuaClosure || $tm instanceof NativeFunction) {
                MetaMethods::callTM($L, $tm, $t, $key, $value);
                return;
            }
            $t = $tm;  // else repeat assignment over 'tm'
            if ($t instanceof LuaTable && $t->get($key) !== null) {
                self::rawSet($L, $t, $key, $value);  // luaV_finishfastset
                return;
            }
        }
        DebugInfo::runError($L, "'__newindex' chain too long; possible loop");
    }

    /** lvm.h: luaV_gettable (lua_gettable/lua_getfield/lua_geti) */
    public static function getTable(Coroutine $L, mixed $t, mixed $key, int $slot = DebugInfo::NO_SLOT): mixed
    {
        if ($t instanceof LuaTable) {
            $value = $t->get($key);
            if ($value !== null || $t->metatable === null) {
                return $value;
            }
        }
        return self::finishGet($L, $t, $key, $slot);
    }

    /** lvm.h: luaV_settable (lua_settable/lua_setfield/lua_seti) */
    public static function setTable(Coroutine $L, mixed $t, mixed $key, mixed $value, int $slot = DebugInfo::NO_SLOT): void
    {
        if ($t instanceof LuaTable && ($t->metatable === null || $t->get($key) !== null)) {
            self::rawSet($L, $t, $key, $value);
            return;
        }
        self::finishSet($L, $t, $key, $value, $slot);
    }

    /** ltable.c: luaH_set with luaH_newkey's key checks (lua_rawset) */
    public static function rawSet(Coroutine $L, LuaTable $t, mixed $key, mixed $value): void
    {
        if ($key === null) {
            DebugInfo::runError($L, 'table index is nil');
        }
        if (\is_float($key) && is_nan($key)) {
            DebugInfo::runError($L, 'table index is NaN');
        }
        $t->set($key, $value);
    }

    /* }================================================================== */

    /*
    ** {==================================================================
    ** Comparison
    ** ===================================================================
    */

    // lvm.c: l_intfitsf (|i| <= 2^53)
    private static function intFitsFloat(int $i): bool
    {
        return $i >= -9007199254740992 && $i <= 9007199254740992;
    }

    // lvm.c: LTintfloat
    private static function lessThanIntFloat(int $i, float $f): bool
    {
        if (self::intFitsFloat($i)) {
            return (float) $i < $f;  // compare them as floats
        }
        $ceiling = self::floatToIntegerMode($f, self::F2Iceil);  // i < f <=> i < ceil(f)
        if ($ceiling !== null) {
            return $i < $ceiling;
        }
        return $f > 0;  // 'f' is either greater or less than all integers
    }

    // lvm.c: LEintfloat
    private static function lessEqualIntFloat(int $i, float $f): bool
    {
        if (self::intFitsFloat($i)) {
            return (float) $i <= $f;
        }
        $floor = self::floatToIntegerMode($f, self::F2Ifloor);  // i <= f <=> i <= floor(f)
        if ($floor !== null) {
            return $i <= $floor;
        }
        return $f > 0;
    }

    // lvm.c: LTfloatint
    private static function lessThanFloatInt(float $f, int $i): bool
    {
        if (self::intFitsFloat($i)) {
            return $f < (float) $i;
        }
        $floor = self::floatToIntegerMode($f, self::F2Ifloor);  // f < i <=> floor(f) < i
        if ($floor !== null) {
            return $floor < $i;
        }
        return $f < 0;
    }

    // lvm.c: LEfloatint
    private static function lessEqualFloatInt(float $f, int $i): bool
    {
        if (self::intFitsFloat($i)) {
            return $f <= (float) $i;
        }
        $ceiling = self::floatToIntegerMode($f, self::F2Iceil);  // f <= i <=> ceil(f) <= i
        if ($ceiling !== null) {
            return $ceiling <= $i;
        }
        return $f < 0;
    }

    // lvm.c: LTnum
    public static function lessThanNumbers(int|float $l, int|float $r): bool
    {
        if (\is_int($l)) {
            return \is_int($r) ? $l < $r : self::lessThanIntFloat($l, $r);
        }
        return \is_float($r) ? $l < $r : self::lessThanFloatInt($l, $r);
    }

    // lvm.c: LEnum
    public static function lessEqualNumbers(int|float $l, int|float $r): bool
    {
        if (\is_int($l)) {
            return \is_int($r) ? $l <= $r : self::lessEqualIntFloat($l, $r);
        }
        return \is_float($r) ? $l <= $r : self::lessEqualFloatInt($l, $r);
    }

    // lvm.c: luaV_lessthan
    public static function lessThan(Coroutine $L, mixed $l, mixed $r): bool
    {
        if ((\is_int($l) || \is_float($l)) && (\is_int($r) || \is_float($r))) {
            return self::lessThanNumbers($l, $r);
        }
        if (\is_string($l) && \is_string($r)) {  // lvm.c: lessthanothers / l_strcmp
            return strcmp($l, $r) < 0;
        }
        return MetaMethods::callOrderTM($L, $l, $r, MetaMethods::TM_LT);
    }

    // lvm.c: luaV_lessequal
    public static function lessEqual(Coroutine $L, mixed $l, mixed $r): bool
    {
        if ((\is_int($l) || \is_float($l)) && (\is_int($r) || \is_float($r))) {
            return self::lessEqualNumbers($l, $r);
        }
        if (\is_string($l) && \is_string($r)) {  // lvm.c: lessequalothers / l_strcmp
            return strcmp($l, $r) <= 0;
        }
        return MetaMethods::callOrderTM($L, $l, $r, MetaMethods::TM_LE);
    }

    /**
     * lvm.c: luaV_equalobj. $L === null means raw equality (no
     * metamethods).
     */
    public static function equalObjects(?Coroutine $L, mixed $t1, mixed $t2): bool
    {
        if ($t1 === $t2) {
            return true;
        }
        if (\is_int($t1)) {  // two numbers with different variants?
            return \is_float($t2) && LuaTable::floatToInteger($t2) === $t1;
        }
        if (\is_float($t1)) {
            return \is_int($t2) && LuaTable::floatToInteger($t1) === $t2;
        }
        if ($L === null) {
            return false;
        }
        if (($t1 instanceof LuaTable && $t2 instanceof LuaTable) || ($t1 instanceof Userdata && $t2 instanceof Userdata)) {
            $tm = $t1->metatable?->hash['__eq'] ?? null;
            if ($tm === null) {
                $tm = $t2->metatable?->hash['__eq'] ?? null;
            }
            if ($tm === null) {  // no TM?
                return false;  // objects are different
            }
            $result = MetaMethods::callTMres($L, $tm, $t1, $t2);
            return $result !== null && $result !== false;
        }
        return false;
    }

    // lvm.h: luaV_rawequalobj
    public static function rawEquals(mixed $t1, mixed $t2): bool
    {
        return self::equalObjects(null, $t1, $t2);
    }

    /* }================================================================== */

    /*
    ** {==================================================================
    ** Concatenation and length
    ** ===================================================================
    */

    /**
     * lvm.c: luaV_concat over $values (the operands, in order; C: the
     * stack from top - total to top - 1). $firstRegister is the register
     * holding $values[0] (OP_CONCAT's A), for error messages, or
     * DebugInfo::NO_SLOT.
     *
     * @param list<mixed> $values
     */
    public static function concat(Coroutine $L, array $values, int $firstRegister = DebugInfo::NO_SLOT): mixed
    {
        $total = \count($values);
        $top = $total;  // values[top - 1] is the last one still to concatenate
        while ($total > 1) {
            $handled = 2;  // number of elements handled in this pass (at least 2)
            $second = $values[$top - 1];
            $first = $values[$top - 2];
            if (!(\is_string($first) || \is_int($first) || \is_float($first))
                || !(\is_string($second) || \is_int($second) || \is_float($second))) {
                // luaT_tryconcatTM
                $slotFirst = $firstRegister === DebugInfo::NO_SLOT ? DebugInfo::NO_SLOT : $firstRegister + $top - 2;
                $values[$top - 2] = MetaMethods::tryConcatTM($L, $first, $second, $slotFirst, $slotFirst === DebugInfo::NO_SLOT ? DebugInfo::NO_SLOT : $slotFirst + 1);
            } else {
                if (!\is_string($second)) {
                    $second = $values[$top - 1] = LuaObject::numberToString($second);
                }
                if ($second === '') {  // second operand is empty?
                    if (!\is_string($first)) {
                        $values[$top - 2] = LuaObject::numberToString($first);  // result is first operand
                    }
                } elseif ($first === '') {  // first operand is empty string?
                    $values[$top - 2] = $second;  // result is second op.
                } else {
                    // at least two non-empty string values; get as many as possible
                    $pieces = [$second];
                    $length = \strlen($second);
                    for ($handled = 1; $handled < $total; $handled++) {
                        $piece = $values[$top - $handled - 1];
                        if (\is_int($piece) || \is_float($piece)) {
                            $piece = $values[$top - $handled - 1] = LuaObject::numberToString($piece);
                        } elseif (!\is_string($piece)) {
                            break;
                        }
                        $pieces[] = $piece;
                        $length += \strlen($piece);
                    }
                    if ($length > MemoryLimit::CHECK_ABOVE) {
                        MemoryLimit::reserve($length);
                    }
                    $values[$top - $handled] = implode('', array_reverse($pieces));
                }
            }
            $total -= $handled - 1;  // got 'n' strings to create one new
            $top -= $handled - 1;
        }
        return $values[0];
    }

    // lvm.c: luaV_objlen
    public static function objectLength(Coroutine $L, mixed $value, int $slot): mixed
    {
        if ($value instanceof LuaTable) {
            $tm = $value->metatable?->hash['__len'] ?? null;
            if ($tm === null) {
                return $value->length();  // primitive len
            }
        } elseif (\is_string($value)) {
            return \strlen($value);
        } else {  // try metamethod
            $tm = MetaMethods::getByObject($L, $value, MetaMethods::TM_LEN);
            if ($tm === null) {  // no metamethod?
                DebugInfo::typeError($L, $value, 'get length of', $slot);
            }
        }
        return MetaMethods::callTMres($L, $tm, $value, $value);
    }

    /* }================================================================== */

    /*
    ** {==================================================================
    ** Numeric for loops
    ** ===================================================================
    */

    /**
     * lvm.c: forlimit. Returns null if the loop must not run; otherwise the
     * integer limit.
     */
    private static function forLimit(Coroutine $L, int $init, mixed $limitValue, int $step): ?int
    {
        $limit = self::toInteger($limitValue, $step < 0 ? self::F2Iceil : self::F2Ifloor);
        if ($limit === null) {  // not coercible to in integer
            $floatLimit = self::toFloat($limitValue);  // try to convert to float
            if ($floatLimit === null) {  // cannot convert to float?
                DebugInfo::forError($L, $limitValue, 'limit');
            }
            // else 'flim' is a float out of integer bounds
            if (0 < $floatLimit) {  // if it is positive, it is too large
                if ($step < 0) {
                    return null;  // initial value must be less than it
                }
                $limit = PHP_INT_MAX;  // truncate
            } else {  // it is less than min integer
                if ($step > 0) {
                    return null;  // initial value must be greater than it
                }
                $limit = PHP_INT_MIN;  // truncate
            }
        }
        return ($step > 0 ? $init > $limit : $init < $limit) ? null : $limit;
    }

    /**
     * lvm.c: forprep. Prepares R[a..a+3] for OP_FORLOOP; returns true to
     * skip the loop.
     *
     * @param array<int, mixed> $R
     */
    public static function forPrep(Coroutine $L, array &$R, int $a): bool
    {
        $init = $R[$a];
        $limit = $R[$a + 1];
        $step = $R[$a + 2];
        if (\is_int($init) && \is_int($step)) {  // integer loop?
            if ($step === 0) {
                DebugInfo::runError($L, "'for' step is zero");
            }
            $R[$a + 3] = $init;  // control variable
            $integerLimit = self::forLimit($L, $init, $limit, $step);
            if ($integerLimit === null) {
                return true;  // skip the loop
            }
            // prepare loop counter (unsigned)
            if ($step > 0) {  // ascending loop?
                $count = self::subWrap($integerLimit, $init);
                if ($step !== 1) {  // avoid division in the too common case
                    $count = self::unsignedDivide($count, $step);
                }
            } else {  // step < 0; descending loop
                $count = self::subWrap($init, $integerLimit);
                // 'step+1' avoids negating 'mininteger'
                $count = self::unsignedDivide($count, self::addWrap(-($step + 1), 1));
            }
            // store the counter in place of the limit (which won't be needed anymore)
            $R[$a + 1] = $count;
            return false;
        }
        // try making all control values floats
        $floatLimit = self::toFloat($limit);
        if ($floatLimit === null) {
            DebugInfo::forError($L, $limit, 'limit');
        }
        $floatStep = self::toFloat($step);
        if ($floatStep === null) {
            DebugInfo::forError($L, $step, 'step');
        }
        $floatInit = self::toFloat($init);
        if ($floatInit === null) {
            DebugInfo::forError($L, $init, 'initial value');
        }
        if ($floatStep == 0) {
            DebugInfo::runError($L, "'for' step is zero");
        }
        if (0 < $floatStep ? $floatLimit < $floatInit : $floatInit < $floatLimit) {
            return true;  // skip the loop
        }
        // make sure internal values are all floats
        $R[$a + 1] = $floatLimit;
        $R[$a + 2] = $floatStep;
        $R[$a] = $floatInit;  // internal index
        $R[$a + 3] = $floatInit;  // control variable
        return false;
    }

    /**
     * lvm.c: floatforloop. Returns true if the loop must continue.
     *
     * @param array<int, mixed> $R
     */
    public static function floatForLoop(array &$R, int $a): bool
    {
        $step = $R[$a + 2];
        $limit = $R[$a + 1];
        $index = $R[$a] + $step;  // increment index
        if (0 < $step ? $index <= $limit : $limit <= $index) {
            $R[$a] = $index;  // update internal index
            $R[$a + 3] = $index;  // and control variable
            return true;  // jump back
        }
        return false;  // finish the loop
    }

    /* }================================================================== */

    /*
    ** {==================================================================
    ** Generic arithmetic (lobject.c: luaO_rawarith, luaO_arith; lapi.c: lua_arith)
    ** ===================================================================
    */

    // lobject.c: intarith
    public static function integerArith(Coroutine $L, int $op, int $v1, int $v2): int
    {
        return match ($op) {
            self::LUA_OPADD => self::addWrap($v1, $v2),
            self::LUA_OPSUB => self::subWrap($v1, $v2),
            self::LUA_OPMUL => self::mulWrap($v1, $v2),
            self::LUA_OPMOD => self::mod($L, $v1, $v2),
            self::LUA_OPIDIV => self::idiv($L, $v1, $v2),
            self::LUA_OPBAND => $v1 & $v2,
            self::LUA_OPBOR => $v1 | $v2,
            self::LUA_OPBXOR => $v1 ^ $v2,
            self::LUA_OPSHL => self::shiftLeft($v1, $v2),
            self::LUA_OPSHR => self::shiftRight($v1, $v2),
            self::LUA_OPUNM => self::negWrap($v1),
            self::LUA_OPBNOT => ~$v1,
        };
    }

    // lobject.c: numarith
    public static function floatArith(int $op, float $v1, float $v2): float
    {
        return match ($op) {
            self::LUA_OPADD => $v1 + $v2,
            self::LUA_OPSUB => $v1 - $v2,
            self::LUA_OPMUL => $v1 * $v2,
            self::LUA_OPDIV => fdiv($v1, $v2),
            self::LUA_OPPOW => self::floatPow($v1, $v2),
            self::LUA_OPIDIV => self::floatIdiv($v1, $v2),
            self::LUA_OPUNM => self::floatNegate($v1),
            self::LUA_OPMOD => self::modf($v1, $v2),
        };
    }

    /**
     * llimits.h: luai_numunm, C's unary minus: flips the sign bit, also of
     * a NaN. PHP compiles -$x as $x * -1, which leaves a NaN as it is, so
     * the emitted OP_UNM calls this for NaNs.
     */
    public static function floatNegate(float $x): float
    {
        if (!is_nan($x)) {
            return -$x;
        }
        return unpack('E', pack('E', $x) ^ "\x80\0\0\0\0\0\0\0")[1];  // big-endian: the sign is the first bit
    }

    /** lobject.c: luaO_rawarith; null when the operands are not suitable numbers */
    public static function rawArith(Coroutine $L, int $op, mixed $p1, mixed $p2): int|float|null
    {
        switch ($op) {
            case self::LUA_OPBAND:
            case self::LUA_OPBOR:
            case self::LUA_OPBXOR:
            case self::LUA_OPSHL:
            case self::LUA_OPSHR:
            case self::LUA_OPBNOT:  // operate only on integers
                $i1 = self::toIntegerNoString($p1);
                $i2 = self::toIntegerNoString($p2);
                if ($i1 !== null && $i2 !== null) {
                    return self::integerArith($L, $op, $i1, $i2);
                }
                return null;
            case self::LUA_OPDIV:
            case self::LUA_OPPOW:  // operate only on floats
                if ((\is_int($p1) || \is_float($p1)) && (\is_int($p2) || \is_float($p2))) {
                    return self::floatArith($op, (float) $p1, (float) $p2);
                }
                return null;
            default:  // other operations
                if (\is_int($p1) && \is_int($p2)) {
                    return self::integerArith($L, $op, $p1, $p2);
                }
                if ((\is_int($p1) || \is_float($p1)) && (\is_int($p2) || \is_float($p2))) {
                    return self::floatArith($op, (float) $p1, (float) $p2);
                }
                return null;
        }
    }

    /** lobject.c: luaO_arith (lapi.c: lua_arith): raw arithmetic or metamethod */
    public static function arith(Coroutine $L, int $op, mixed $p1, mixed $p2): mixed
    {
        $result = self::rawArith($L, $op, $p1, $p2);
        if ($result !== null) {
            return $result;
        }
        return MetaMethods::tryBinTM($L, $p1, $p2, $op + MetaMethods::TM_ADD, DebugInfo::NO_SLOT, DebugInfo::NO_SLOT);
    }

    /* }================================================================== */
}
