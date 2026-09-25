<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MetaMethods;
use LuaPhp\Runtime\Vm;

/**
 * Port of ltablib.c: the table library.
 */
final class TableLib
{
    // ltablib.c: operations that an object must define to mimic a table
    private const TAB_R = 1;  // read
    private const TAB_W = 2;  // write
    private const TAB_L = 4;  // length
    private const TAB_RW = self::TAB_R | self::TAB_W;

    // ltablib.c: luaopen_table
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'concat' => self::concat(...),
            'insert' => self::insert(...),
            'pack' => self::pack(...),
            'unpack' => self::unpack(...),
            'remove' => self::remove(...),
            'move' => self::move(...),
            'sort' => self::sort(...),
        ]);
        return $library;
    }

    /**
     * ltablib.c: checktab: argument 'arg' is a table or has a metatable
     * with the metamethods needed for $what.
     */
    private static function checkTab(Coroutine $L, array $args, int $arg, int $what): void
    {
        $value = $args[$arg - 1] ?? null;
        if ($value instanceof LuaTable) {
            return;
        }
        $metatable = MetaMethods::metatableOf($L, $value);
        if ($metatable !== null  // must have metatable
            && (!($what & self::TAB_R) || isset($metatable->hash['__index']))
            && (!($what & self::TAB_W) || isset($metatable->hash['__newindex']))
            && (!($what & self::TAB_L) || isset($metatable->hash['__len']))) {
            return;
        }
        Auxiliary::checkType($L, $args, $arg, Lua::LUA_TTABLE);  // force an error
    }

    // ltablib.c: aux_getn
    private static function lengthOf(Coroutine $L, array $args, int $arg, int $what): int
    {
        self::checkTab($L, $args, $arg, $what | self::TAB_L);
        return Auxiliary::len($L, $args[$arg - 1]);
    }

    /** lapi.c: lua_geti */
    private static function getIndex(Coroutine $L, mixed $table, int $index): mixed
    {
        if ($table instanceof LuaTable) {
            $value = $table->arr[$index] ?? null;
            if ($value !== null || $table->metatable === null) {
                return $value;
            }
        }
        return Vm::getTable($L, $table, $index);
    }

    /** lapi.c: lua_seti */
    private static function setIndex(Coroutine $L, mixed $table, int $index, mixed $value): void
    {
        if ($table instanceof LuaTable && $table->metatable === null) {
            if ($value === null) {
                unset($table->arr[$index]);
            } else {
                $table->arr[$index] = $value;
            }
            return;
        }
        Vm::setTable($L, $table, $index, $value);
    }

    // ltablib.c: tinsert
    private static function insert(Coroutine $L, array $args): array
    {
        $table = $args[0] ?? null;
        // Fast path for table.insert(t, v) on a table without a metatable:
        // checktab passes, aux_getn is the raw border (lua_len without
        // '__len'), and lua_seti is a raw set. Same result as below.
        if ($table instanceof LuaTable && $table->metatable === null && \count($args) === 2) {
            $border = $table->length();
            $table->set($border === PHP_INT_MAX ? PHP_INT_MIN : $border + 1, $args[1]);  // intop(+, border, 1)
            return [];
        }
        $firstEmpty = Vm::addWrap(self::lengthOf($L, $args, 1, self::TAB_RW), 1);  // first empty element
        switch (\count($args)) {
            case 2:  // called with only 2 arguments
                $position = $firstEmpty;  // insert new element at the end
                $value = $args[1];
                break;
            case 3:
                $position = Auxiliary::checkInteger($L, $args, 2);  // 2nd argument is the position
                // check whether 'pos' is in [1, e]
                Auxiliary::argCheck($L, Vm::unsignedLess(Vm::subWrap($position, 1), $firstEmpty), 2, 'position out of bounds');
                for ($i = $firstEmpty; $i > $position; $i--) {  // move up elements
                    self::setIndex($L, $table, $i, self::getIndex($L, $table, $i - 1));  // t[i] = t[i - 1]
                }
                $value = $args[2];
                break;
            default:
                Auxiliary::error($L, "wrong number of arguments to 'insert'");
        }
        self::setIndex($L, $table, $position, $value);  // t[pos] = v
        return [];
    }

    // ltablib.c: tremove
    private static function remove(Coroutine $L, array $args): array
    {
        $table = $args[0] ?? null;
        // Fast path for table.remove(t) on a table without a metatable:
        // checktab passes, pos is the raw border, and lua_geti/lua_seti are
        // raw. Same result as below (also for an empty table: t[0]).
        if ($table instanceof LuaTable && $table->metatable === null && \count($args) === 1) {
            $border = $table->length();
            $result = $table->arr[$border] ?? null;
            $table->set($border, null);
            return [$result];
        }
        $size = self::lengthOf($L, $args, 1, self::TAB_RW);
        $position = Auxiliary::optInteger($L, $args, 2, $size);
        if ($position !== $size) {  // validate 'pos' if given
            // check whether 'pos' is in [1, size + 1]
            $offset = Vm::subWrap($position, 1);
            Auxiliary::argCheck($L, Vm::unsignedLess($offset, $size) || $offset === $size, 2, 'position out of bounds');
        }
        $result = self::getIndex($L, $table, $position);  // result = t[pos]
        for (; $position < $size; $position++) {
            self::setIndex($L, $table, $position, self::getIndex($L, $table, $position + 1));  // t[pos] = t[pos + 1]
        }
        self::setIndex($L, $table, $position, null);  // remove entry t[pos]
        return [$result];
    }

    // ltablib.c: tmove
    private static function move(Coroutine $L, array $args): array
    {
        $first = Auxiliary::checkInteger($L, $args, 2);
        $end = Auxiliary::checkInteger($L, $args, 3);
        $target = Auxiliary::checkInteger($L, $args, 4);
        $destinationArgument = ($args[4] ?? null) !== null ? 5 : 1;  // destination table
        self::checkTab($L, $args, 1, self::TAB_R);
        self::checkTab($L, $args, $destinationArgument, self::TAB_W);
        $source = $args[0];
        $destination = $args[$destinationArgument - 1];
        if ($end >= $first) {  // otherwise, nothing to move
            Auxiliary::argCheck($L, $first > 0 || $end < PHP_INT_MAX + $first, 3, 'too many elements to move');
            $count = $end - $first + 1;  // number of elements to move
            Auxiliary::argCheck($L, $target <= PHP_INT_MAX - $count + 1, 4, 'destination wrap around');
            if ($target > $end || $target <= $first || ($destinationArgument !== 1 && !Vm::equalObjects($L, $source, $destination))) {
                for ($i = 0; $i < $count; $i++) {
                    self::setIndex($L, $destination, $target + $i, self::getIndex($L, $source, $first + $i));
                }
            } else {
                for ($i = $count - 1; $i >= 0; $i--) {
                    self::setIndex($L, $destination, $target + $i, self::getIndex($L, $source, $first + $i));
                }
            }
        }
        return [$destination];  // return destination table
    }

    // ltablib.c: addfield
    private static function field(Coroutine $L, mixed $table, int $index): string
    {
        $value = self::getIndex($L, $table, $index);
        $string = LuaObject::toStringCoerced($value);
        if ($string === null) {
            Auxiliary::error($L, 'invalid value (' . LuaObject::typeName($value) . ") at index $index in table for 'concat'");
        }
        return $string;
    }

    // ltablib.c: tconcat
    private static function concat(Coroutine $L, array $args): array
    {
        $table = $args[0] ?? null;
        $separator = $args[1] ?? '';
        // Fast path for table.concat(t[, sep]) with a string separator on a
        // table without a metatable: checktab passes, the range is 1 to the
        // raw border, lua_geti is raw, and strings and integers are added
        // as addfield adds them. Any other element takes the path below.
        if ($table instanceof LuaTable && $table->metatable === null && \count($args) <= 2 && \is_string($separator)) {
            $last = $table->length();
            $pieces = [];
            for ($index = 1; $index <= $last; $index++) {
                $value = $table->arr[$index] ?? null;
                if (\is_string($value)) {
                    $pieces[] = $value;
                } elseif (\is_int($value)) {
                    $pieces[] = (string) $value;
                } else {
                    $pieces = null;
                    break;
                }
            }
            if ($pieces !== null) {
                return [implode($separator, $pieces)];
            }
        }
        $last = self::lengthOf($L, $args, 1, self::TAB_R);
        $separator = Auxiliary::optString($L, $args, 2, '');
        $index = Auxiliary::optInteger($L, $args, 3, 1);
        $last = Auxiliary::optInteger($L, $args, 4, $last);
        $table = $args[0];
        $pieces = [];
        for (; $index < $last; $index++) {
            $pieces[] = self::field($L, $table, $index);
        }
        if ($index === $last) {  // add last value (if interval was not empty)
            $pieces[] = self::field($L, $table, $index);
        }
        return [implode($separator, $pieces)];
    }

    // ltablib.c: tpack
    private static function pack(Coroutine $L, array $args): array
    {
        $table = LuaTable::fromList($args);  // create result table
        $table->hash['n'] = \count($args);  // t.n = number of elements
        return [$table];
    }

    // ltablib.c: tunpack
    private static function unpack(Coroutine $L, array $args): array
    {
        $table = $args[0] ?? null;
        // Fast path for table.unpack(t) on a table without a metatable: i is
        // 1, e is the raw border, and the stack check below passes.
        if ($table instanceof LuaTable && $table->metatable === null && \count($args) === 1) {
            $end = $table->length();
            if ($end < Lua::LUAI_MAXSTACK && $L->ci->top + $end <= $L->stackLimit) {  // Calls::checkStack($L, $end)
                $values = $table->arr;
                $results = [];
                for ($index = 1; $index <= $end; $index++) {
                    $results[] = $values[$index] ?? null;
                }
                return $results;
            }
        }
        $index = Auxiliary::optInteger($L, $args, 2, 1);
        $end = ($args[2] ?? null) === null ? Auxiliary::len($L, $table) : Auxiliary::checkInteger($L, $args, 3);
        if ($index > $end) {
            return [];  // empty range
        }
        $count = Vm::subWrap($end, $index);  // number of elements minus 1 (avoid overflows)
        if (Vm::unsignedLess(2147483646, $count) || !Calls::checkStack($L, $count + 1)) {
            Auxiliary::error($L, 'too many results to unpack');
        }
        $results = [];
        if ($table instanceof LuaTable && $table->metatable === null) {
            $values = $table->arr;
            for (; $index < $end; $index++) {  // push arg[i..e - 1] (to avoid overflows)
                $results[] = $values[$index] ?? null;
            }
            $results[] = $values[$end] ?? null;  // push last element
            return $results;
        }
        for (; $index < $end; $index++) {
            $results[] = self::getIndex($L, $table, $index);
        }
        $results[] = self::getIndex($L, $table, $end);
        return $results;
    }

    /*
    ** {======================================================
    ** Quicksort
    ** (based on 'Algorithms in MODULA-3', Robert Sedgewick;
    **  Addison-Wesley, 1993.)
    ** The C code keeps the values it works on in the Lua stack; here they are
    ** PHP locals. Indices are C's 'IdxT' (unsigned int); they stay below
    ** INT_MAX, so PHP ints hold them exactly.
    ** =======================================================
    */

    // ltablib.c: arrays larger than 'RANLIMIT' may use randomized pivots
    private const RANLIMIT = 100;

    /**
     * ltablib.c: l_randomizePivot: a "random" unsigned int from 'clock' and
     * 'time', summing their values as arrays of unsigned ints.
     */
    private static function randomizePivot(): int
    {
        $usage = getrusage();  // C clock(): processor time in microseconds
        $clock = ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
        $time = time();
        return (($clock & 0xFFFFFFFF) + (($clock >> 32) & 0xFFFFFFFF)
            + ($time & 0xFFFFFFFF) + (($time >> 32) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    }

    /**
     * ltablib.c: sort_comp: is $a less than $b according to the order of
     * the sort ($comparator, or '<' when it is nil)?
     */
    private static function sortLess(Coroutine $L, mixed $comparator, mixed $a, mixed $b): bool
    {
        if ($comparator === null) {  // no function?
            return Vm::lessThan($L, $a, $b);  // a < b
        }
        $result = Calls::callNoYield($L, $comparator, [$a, $b])[0] ?? null;  // call function
        return $result !== null && $result !== false;
    }

    /**
     * ltablib.c: partition. Pivot $pivot is a[up - 1];
     * precondition: a[lo] <= P == a[up-1] <= a[up],
     * so it only needs to do the partition from lo + 1 to up - 2.
     * Pos-condition: a[lo .. i - 1] <= a[i] == P <= a[i + 1 .. up]
     * returns 'i'.
     */
    private static function partition(Coroutine $L, mixed $table, mixed $comparator, int $lo, int $up, mixed $pivot): int
    {
        $i = $lo;  // will be incremented before first use
        $j = $up - 1;  // will be decremented before first use
        // loop invariant: a[lo .. i] <= P <= a[j .. up]
        while (true) {
            // next loop: repeat ++i while a[i] < P
            while (self::sortLess($L, $comparator, $valueI = self::getIndex($L, $table, ++$i), $pivot)) {
                if ($i === $up - 1) {  // a[i] < P  but a[up - 1] == P  ??
                    Auxiliary::error($L, 'invalid order function for sorting');
                }
            }
            // after the loop, a[i] >= P and a[lo .. i - 1] < P
            // next loop: repeat --j while P < a[j]
            while (self::sortLess($L, $comparator, $pivot, $valueJ = self::getIndex($L, $table, --$j))) {
                if ($j < $i) {  // j < i  but  a[j] > P ??
                    Auxiliary::error($L, 'invalid order function for sorting');
                }
            }
            // after the loop, a[j] <= P and a[j + 1 .. up] >= P
            if ($j < $i) {  // no elements out of place?
                // a[lo .. i - 1] <= P <= a[j + 1 .. i .. up]
                // swap pivot (a[up - 1]) with a[i] to satisfy pos-condition
                self::setIndex($L, $table, $up - 1, $valueI);
                self::setIndex($L, $table, $i, $pivot);
                return $i;
            }
            // otherwise, swap a[i] - a[j] to restore invariant and repeat
            self::setIndex($L, $table, $i, $valueJ);
            self::setIndex($L, $table, $j, $valueI);
        }
    }

    /**
     * ltablib.c: choosePivot: choose an element in the middle (2nd-3th
     * quarters) of [lo,up] "randomized" by 'rnd'
     */
    private static function choosePivot(int $lo, int $up, int $rnd): int
    {
        $r4 = intdiv($up - $lo, 4);  // range/4
        return $rnd % ($r4 * 2) + ($lo + $r4);
    }

    // ltablib.c: auxsort: quicksort algorithm (recursive function)
    private static function auxsort(Coroutine $L, mixed $table, mixed $comparator, int $lo, int $up, int $rnd): void
    {
        while ($lo < $up) {  // loop for tail recursion
            // sort elements 'lo', 'p', and 'up'
            $valueLo = self::getIndex($L, $table, $lo);
            $valueUp = self::getIndex($L, $table, $up);
            if (self::sortLess($L, $comparator, $valueUp, $valueLo)) {  // a[up] < a[lo]?
                self::setIndex($L, $table, $lo, $valueUp);  // swap a[lo] - a[up]
                self::setIndex($L, $table, $up, $valueLo);
            }
            if ($up - $lo === 1) {  // only 2 elements?
                return;  // already sorted
            }
            if ($up - $lo < self::RANLIMIT || $rnd === 0) {  // small interval or no randomize?
                $p = intdiv($lo + $up, 2);  // middle element is a good pivot
            } else {  // for larger intervals, it is worth a random pivot
                $p = self::choosePivot($lo, $up, $rnd);
            }
            $valueP = self::getIndex($L, $table, $p);
            $valueLo = self::getIndex($L, $table, $lo);
            if (self::sortLess($L, $comparator, $valueP, $valueLo)) {  // a[p] < a[lo]?
                self::setIndex($L, $table, $p, $valueLo);  // swap a[p] - a[lo]
                self::setIndex($L, $table, $lo, $valueP);
            } else {
                $valueUp = self::getIndex($L, $table, $up);
                if (self::sortLess($L, $comparator, $valueUp, $valueP)) {  // a[up] < a[p]?
                    self::setIndex($L, $table, $p, $valueUp);  // swap a[up] - a[p]
                    self::setIndex($L, $table, $up, $valueP);
                }
            }
            if ($up - $lo === 2) {  // only 3 elements?
                return;  // already sorted
            }
            $pivot = self::getIndex($L, $table, $p);  // get middle element (Pivot)
            $valueUpMinus1 = self::getIndex($L, $table, $up - 1);
            self::setIndex($L, $table, $p, $valueUpMinus1);  // swap Pivot (a[p]) with a[up - 1]
            self::setIndex($L, $table, $up - 1, $pivot);
            $p = self::partition($L, $table, $comparator, $lo, $up, $pivot);
            // a[lo .. p - 1] <= a[p] == P <= a[p + 1 .. up]
            if ($p - $lo < $up - $p) {  // lower interval is smaller?
                self::auxsort($L, $table, $comparator, $lo, $p - 1, $rnd);  // call recursively for lower interval
                $n = $p - $lo;  // size of smaller interval
                $lo = $p + 1;  // tail call for [p + 1 .. up] (upper interval)
            } else {
                self::auxsort($L, $table, $comparator, $p + 1, $up, $rnd);  // call recursively for upper interval
                $n = $up - $p;  // size of smaller interval
                $up = $p - 1;  // tail call for [lo .. p - 1]  (lower interval)
            }
            // (C computes 'up - lo' unsigned; when it would be negative the loop ends anyway)
            if (intdiv($up - $lo, 128) > $n) {  // partition too imbalanced?
                $rnd = self::randomizePivot();  // try a new randomization
            }
        }  // tail call auxsort(L, lo, up, rnd)
    }

    // ltablib.c: sort
    private static function sort(Coroutine $L, array $args): array
    {
        $n = self::lengthOf($L, $args, 1, self::TAB_RW);
        if ($n > 1) {  // non-trivial interval?
            Auxiliary::argCheck($L, $n < 2147483647, 1, 'array too big');  // n < INT_MAX
            if (($args[1] ?? null) !== null) {  // is there a 2nd argument?
                Auxiliary::checkType($L, $args, 2, Lua::LUA_TFUNCTION);  // must be a function
            }
            self::auxsort($L, $args[0], $args[1] ?? null, 1, $n, 0);
        }
        return [];
    }

    /* }====================================================== */
}
