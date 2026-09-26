<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Embed\ConversionError;
use LuaPhp\Embed\LuaFunction;
use LuaPhp\Embed\LuaTable;
use LuaPhp\Embed\LuaValue;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable as RuntimeTable;
use LuaPhp\Runtime\NativeFunction;

/**
 * Values between PHP and Lua.
 *
 * PHP -> Lua (toLua): null, booleans, integers, floats and strings as they
 * are (a string is never a function); a list (array_is_list) becomes a
 * sequence 1..n, any other array a table with its keys as they are
 * (PHP already made "10" the integer 10); a Closure a Lua function
 * (HostFunction; the same one each time in a sandbox); a handle of the same sandbox its Lua value. Anything
 * else, a handle of another sandbox and arrays nested deeper than
 * MAX_DEPTH (PHP reference loops included) are a ConversionError. Paths
 * use PHP keys: "args[0]['tags'][2]".
 *
 * Lua -> PHP (toPhp): raw contents, metatables ignored. A table with keys
 * exactly 1..n becomes a list starting at 0, any other table an array with
 * its keys as they are; a table with a key that is neither an integer nor
 * a string, with both 1 and "1" as keys (PHP arrays cannot tell them
 * apart), that contains itself or is nested deeper than MAX_DEPTH is a
 * ConversionError. Functions become LuaFunction handles, threads and
 * userdata LuaValue handles. Paths use Lua keys: "result[1].tags[3]".
 *
 * @internal
 */
final class Convert
{
    public const MAX_DEPTH = 100;

    /**
     * $value as a Lua value of the sandbox $state; with no $state, only
     * checks that it can be one (registering it in an Environment), which
     * reads the signatures of its Closures.
     */
    public static function toLua(mixed $value, string $path, ?State $state, int $depth = 0): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }
        if (\is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                throw new ConversionError('array nested deeper than ' . self::MAX_DEPTH . ' levels', $path);
            }
            if (array_is_list($value)) {
                $table = new RuntimeTable(\count($value));  // as a constructor sizes it (lua_createtable)
                foreach ($value as $index => $item) {
                    $item = self::toLua($item, $path . "[$index]", $state, $depth + 1);
                    if ($item !== null) {
                        $table->arr[$index + 1] = $item;
                    }
                }
                return $table;
            }
            $table = new RuntimeTable();
            foreach ($value as $key => $item) {
                $item = self::toLua($item, $path . '[' . var_export($key, true) . ']', $state, $depth + 1);
                if ($item === null) {
                    continue;
                }
                if (\is_int($key)) {
                    $table->arr[$key] = $item;
                } else {
                    $table->hash[$key] = $item;
                }
            }
            return $table;
        }
        if ($value instanceof \Closure) {
            if ($state === null) {
                HostFunction::of($value, $path);  // reads its signature
                return null;
            }
            return $state->hostFunction($value, $path);
        }
        if ($value instanceof LuaValue) {
            if ($value->sandboxState !== $state) {
                throw new ConversionError($state === null ? 'cannot register a value of a sandbox in an Environment' : 'cannot pass a value of another sandbox to Lua', $path);
            }
            return $value->luaValue;
        }
        if (\is_object($value)) {
            throw new ConversionError('cannot pass object of class ' . $value::class . ' to Lua', $path);
        }
        throw new ConversionError('cannot pass a ' . get_debug_type($value) . ' to Lua', $path);
    }

    /** the Lua value $value of sandbox $state as a PHP value (tables copied) */
    public static function toPhp(mixed $value, string $path, State $state): mixed
    {
        if (!\is_object($value)) {
            return $value;
        }
        if ($value instanceof RuntimeTable) {
            $tablesOnPath = [];
            return self::tableToPhp($value, $path, $state, $tablesOnPath, 0);
        }
        return self::toHandle($value, $state);
    }

    /** the Lua value $value of sandbox $state for PHP without copying: other than nil, booleans, numbers and strings, a handle */
    public static function toHandle(mixed $value, State $state): mixed
    {
        if (!\is_object($value)) {
            return $value;
        }
        if ($value instanceof RuntimeTable) {
            return new LuaTable($state, $value);
        }
        if ($value instanceof LuaClosure || $value instanceof NativeFunction) {
            return new LuaFunction($state, $value);
        }
        return new LuaValue($state, $value);  // a thread or a userdata
    }

    /** @param array<int, true> $tablesOnPath */
    private static function tableToPhp(RuntimeTable $table, string $path, State $state, array &$tablesOnPath, int $depth): array
    {
        $id = spl_object_id($table);
        if (isset($tablesOnPath[$id])) {
            throw new ConversionError('table contains a cycle', $path);
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new ConversionError('table nested deeper than ' . self::MAX_DEPTH . ' levels', $path);
        }
        $otherKeys = $table->extra?->otherKeys ?? [];
        if ($otherKeys !== []) {
            $key = $otherKeys[array_key_first($otherKeys)];
            $name = match (true) {
                \is_float($key) => 'table key ' . LuaObject::numberToString($key),
                \is_bool($key) => 'table key ' . ($key ? 'true' : 'false'),
                default => 'a table key of type ' . LuaObject::typeName($key),
            };
            throw new ConversionError("cannot convert $name to PHP (keys must be integers or strings)", $path);
        }
        $tablesOnPath[$id] = true;
        $arr = $table->arr;
        $hash = $table->hash;
        $count = \count($arr);
        $isList = $hash === [];
        for ($key = 1; $isList && $key <= $count; $key++) {
            $isList = isset($arr[$key]);
        }
        $result = [];
        if ($isList) {
            for ($key = 1; $key <= $count; $key++) {
                $item = $arr[$key];
                $result[] = \is_object($item) ? self::elementToPhp($item, $path . "[$key]", $state, $tablesOnPath, $depth) : $item;
            }
        } else {
            foreach ($arr as $key => $item) {
                $result[$key] = \is_object($item) ? self::elementToPhp($item, $path . "[$key]", $state, $tablesOnPath, $depth) : $item;
            }
            foreach ($hash as $key => $item) {
                $key = (string) $key;  // (PHP keeps "10" as 10: still a Lua string)
                if (\array_key_exists($key, $arr)) {
                    throw new ConversionError("table has both $key and \"$key\" as keys", $path);
                }
                $result[$key] = \is_object($item) ? self::elementToPhp($item, $path . self::stringKey($key), $state, $tablesOnPath, $depth) : $item;
            }
        }
        unset($tablesOnPath[$id]);
        return $result;
    }

    /** @param array<int, true> $tablesOnPath */
    private static function elementToPhp(object $item, string $path, State $state, array &$tablesOnPath, int $depth): mixed
    {
        if ($item instanceof RuntimeTable) {
            return self::tableToPhp($item, $path, $state, $tablesOnPath, $depth + 1);
        }
        return self::toHandle($item, $state);
    }

    /** the path segment of string key $key: ".name", or ["any string"] */
    private static function stringKey(string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) === 1) {
            return ".$key";
        }
        return '["' . addcslashes($key, "\"\\\0..\37\177") . '"]';
    }
}
