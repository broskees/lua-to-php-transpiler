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
 * A table that holds the same subtable twice becomes two copies, so a
 * small value can make a huge one (t = {t, t} twenty times: a million
 * arrays). Conversions count what they build (TABLE_BYTES per table,
 * ELEMENT_BYTES per element) in the sandbox's State::$conversionBytes,
 * which goes on from one value and one call to the next, so many small
 * values (a list of results or arguments, values PHP reads one at a time)
 * add up as one big value does; every CHECK_BYTES of it, the sandbox is
 * asked for the memory (State::reserveConversionMemory: past the run's
 * memory limit or what memory_limit leaves, LimitExceeded('memory')).
 * Paths are made only for an error, from the keys on the way (strings
 * are shared, not copied, while converting), and show at most
 * PATH_KEY_BYTES of a key.
 *
 * @internal
 */
final class Convert
{
    public const MAX_DEPTH = 100;

    /** what a converted table or array costs, about (PHP array or LuaTable, with its first slots) */
    private const TABLE_BYTES = 256;

    /** what each element costs, about (a hash bucket, with room to grow) */
    private const ELEMENT_BYTES = 48;

    /** the memory is checked each time a conversion built about this much more */
    private const CHECK_BYTES = 64 * 1024;

    /** the bytes of a string key a path shows (then "...") */
    private const PATH_KEY_BYTES = 40;

    /**
     * $value as a Lua value of the sandbox $state; with no $state, only
     * checks that it can be one (registering it in an Environment), which
     * reads the signatures of its Closures.
     */
    public static function toLua(mixed $value, string $path, ?State $state): mixed
    {
        if (!\is_array($value)) {
            return self::scalarOrObjectToLua($value, $path, $state);
        }
        $keys = [];
        return self::arrayToLua($value, $path, $state, $keys);
    }

    /** the Lua value $value of sandbox $state as a PHP value (tables copied) */
    public static function toPhp(mixed $value, string $path, State $state): mixed
    {
        if (!\is_object($value)) {
            return $value;
        }
        if ($value instanceof RuntimeTable) {
            $tablesOnPath = [];
            $keys = [];
            return self::tableToPhp($value, $path, $state, $tablesOnPath, $keys);
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

    /** $value, not an array, as a Lua value (see toLua) */
    private static function scalarOrObjectToLua(mixed $value, string $path, ?State $state): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
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
                throw new ConversionError(
                    $state === null
                        ? 'cannot register a value of a sandbox in an Environment'
                        : 'cannot pass a value of another sandbox to Lua',
                    $path,
                );
            }
            return $value->luaValue;
        }
        if (\is_object($value)) {
            throw new ConversionError('cannot pass object of class ' . $value::class . ' to Lua', $path);
        }
        throw new ConversionError('cannot pass a ' . get_debug_type($value) . ' to Lua', $path);
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $keys the PHP keys from the root to $array
     */
    private static function arrayToLua(
        array $array,
        string $path,
        ?State $state,
        array &$keys,
    ): RuntimeTable {
        if (\count($keys) >= self::MAX_DEPTH) {
            throw new ConversionError(
                'array nested deeper than ' . self::MAX_DEPTH . ' levels',
                self::phpPath($path, $keys),
            );
        }
        if ($state !== null) {
            self::charge($state, \count($array));
        }
        $isList = array_is_list($array);
        // (a list as a constructor sizes it: lua_createtable)
        $table = $isList ? new RuntimeTable(\count($array)) : new RuntimeTable();
        foreach ($array as $key => $item) {
            if (\is_array($item)) {
                $keys[] = $key;
                $item = self::arrayToLua($item, $path, $state, $keys);
                array_pop($keys);
            } elseif (!\is_scalar($item)) {
                $keys[] = $key;
                $item = self::scalarOrObjectToLua($item, self::phpPath($path, $keys), $state);
                array_pop($keys);
            }
            if ($item === null) {
                continue;
            }
            if ($isList) {
                $table->arr[$key + 1] = $item;
            } elseif (\is_int($key)) {
                $table->arr[$key] = $item;
            } else {
                $table->hash[$key] = $item;
            }
        }
        return $table;
    }

    /**
     * @param array<int, true> $tablesOnPath
     * @param list<int|string> $keys the Lua keys from the root to $table
     */
    private static function tableToPhp(
        RuntimeTable $table,
        string $path,
        State $state,
        array &$tablesOnPath,
        array &$keys,
    ): array {
        $id = spl_object_id($table);
        if (isset($tablesOnPath[$id])) {
            throw new ConversionError('table contains a cycle', self::luaPath($path, $keys));
        }
        if (\count($keys) >= self::MAX_DEPTH) {
            throw new ConversionError(
                'table nested deeper than ' . self::MAX_DEPTH . ' levels',
                self::luaPath($path, $keys),
            );
        }
        $otherKeys = $table->extra?->otherKeys ?? [];
        if ($otherKeys !== []) {
            $key = $otherKeys[array_key_first($otherKeys)];
            $name = match (true) {
                \is_float($key) => 'table key ' . LuaObject::numberToString($key),
                \is_bool($key) => 'table key ' . ($key ? 'true' : 'false'),
                default => 'a table key of type ' . LuaObject::typeName($key),
            };
            throw new ConversionError(
                "cannot convert $name to PHP (keys must be integers or strings)",
                self::luaPath($path, $keys),
            );
        }
        $arr = $table->arr;
        $hash = $table->hash;
        $count = \count($arr);
        self::charge($state, $count + \count($hash));
        $tablesOnPath[$id] = true;
        $isList = $hash === [];
        for ($key = 1; $isList && $key <= $count; $key++) {
            $isList = isset($arr[$key]);
        }
        $result = [];
        if ($isList) {
            for ($key = 1; $key <= $count; $key++) {
                $item = $arr[$key];
                $result[] = \is_object($item)
                    ? self::elementToPhp($item, $key, $path, $state, $tablesOnPath, $keys)
                    : $item;
            }
        } else {
            foreach ($arr as $key => $item) {
                $result[$key] = \is_object($item)
                    ? self::elementToPhp($item, $key, $path, $state, $tablesOnPath, $keys)
                    : $item;
            }
            foreach ($hash as $key => $item) {
                $key = (string) $key;  // (PHP keeps "10" as 10: still a Lua string)
                if (\array_key_exists($key, $arr)) {
                    throw new ConversionError("table has both $key and \"$key\" as keys", self::luaPath($path, $keys));
                }
                $result[$key] = \is_object($item)
                    ? self::elementToPhp($item, $key, $path, $state, $tablesOnPath, $keys)
                    : $item;
            }
        }
        unset($tablesOnPath[$id]);
        return $result;
    }

    /**
     * @param array<int, true> $tablesOnPath
     * @param list<int|string> $keys
     */
    private static function elementToPhp(
        object $item,
        int|string $key,
        string $path,
        State $state,
        array &$tablesOnPath,
        array &$keys,
    ): mixed {
        if (!($item instanceof RuntimeTable)) {
            return self::toHandle($item, $state);
        }
        $keys[] = $key;
        $result = self::tableToPhp($item, $path, $state, $tablesOnPath, $keys);
        array_pop($keys);
        return $result;
    }

    /** a table or array of $elements elements is about to be built: every CHECK_BYTES, the sandbox's memory is checked */
    private static function charge(State $state, int $elements): void
    {
        $state->conversionBytes += self::TABLE_BYTES + self::ELEMENT_BYTES * $elements;
        if ($state->conversionBytes >= self::CHECK_BYTES) {
            $state->reserveConversionMemory($state->conversionBytes);
            $state->conversionBytes = 0;
        }
    }

    /** the path of PHP keys $keys under $path: $path['tags'][2] */
    private static function phpPath(string $path, array $keys): string
    {
        foreach ($keys as $key) {
            $path .= '[' . (\is_int($key) ? $key : var_export(self::shortKey($key), true)) . ']';
        }
        return $path;
    }

    /** the path of Lua keys $keys under $path: $path.tags[3] */
    private static function luaPath(string $path, array $keys): string
    {
        foreach ($keys as $key) {
            $path .= \is_int($key) ? "[$key]" : self::stringKey($key);
        }
        return $path;
    }

    /** $key as a path shows it: at most PATH_KEY_BYTES of it */
    private static function shortKey(string $key): string
    {
        return \strlen($key) <= self::PATH_KEY_BYTES ? $key : substr($key, 0, self::PATH_KEY_BYTES) . '...';
    }

    /** the path segment of string key $key: ".name", or ["any string"] (long keys shortened) */
    private static function stringKey(string $key): string
    {
        $key = self::shortKey($key);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) === 1) {
            return ".$key";
        }
        return '["' . addcslashes($key, "\"\\\0..\37\177") . '"]';
    }
}
