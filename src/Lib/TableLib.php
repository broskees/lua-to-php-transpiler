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
 *
 * Not ported yet: table.sort (Phase 2).
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
}
