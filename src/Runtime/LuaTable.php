<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A Lua table (C: Table in lobject.h, operations from ltable.c).
 *
 * Storage is split by key type so Lua string keys and integer keys never
 * collide (PHP turns "10" into the int key 10):
 * - $arr: integer keys (floats with an integral value are normalized to
 *   int, as luaH_get/luaH_newkey do);
 * - $hash: string keys. PHP stores canonical decimal strings ("10") as int
 *   keys here, but everything in $hash is a Lua string; next() converts
 *   such keys back to strings;
 * - $extra->otherValues/otherKeys: float (non-integral), boolean and
 *   object keys, by an encoded string key (see otherKey()).
 * Nil values are never stored: assigning nil removes the entry.
 *
 * Emitted code and runtime helpers read and write $arr and $hash directly
 * for the fast paths; everything else goes through the methods here.
 *
 * Memory: a table is one PHP object of 5 properties (40 + 5 * 16 bytes, a
 * 128-byte block), plus a PHP array for each of $arr and $hash that is not
 * empty (56 bytes, plus at least 8 slots: 160 bytes for a list, 320 with
 * string keys). What most tables never need (other keys, the traversal
 * cursor) lives in $extra, a LuaTableExtra created on demand.
 *
 * Traversal (next): PHP arrays keep their order, so the "position" of a key
 * is its place in its part. next() leaves the PHP internal array pointer of
 * the part of the key it returns on that key, so the following call finds
 * its place at once: a pairs() loop is amortized O(1). Clearing the current
 * field during the traversal (allowed in Lua) makes PHP advance the
 * internal pointer to the following entry; the cursor ($extra->cursorKey,
 * the last key returned) recognizes that case.
 *
 * Border (#): $sizearray plays the role of C's 'alimit': OP_NEWTABLE and
 * OP_SETLIST set it like the array part of a constructor, and length()
 * updates it as a hint, so '#' matches lua5.4 for tables built by
 * constructors and is O(1) for the usual append/pop patterns.
 */
final class LuaTable
{
    /** @var array<int, mixed> */
    public array $arr = [];

    /** @var array<int|string, mixed> */
    public array $hash = [];

    public ?LuaTable $metatable = null;

    /** border hint (C: alimit); see class comment */
    public int $sizearray = 0;

    /** keys that are neither integers nor strings, and the traversal cursor; null until needed */
    public ?LuaTableExtra $extra = null;

    public function __construct(int $sizearray = 0)
    {
        $this->sizearray = $sizearray;
    }

    /** freeing a long chain of tables must not recurse: see Teardown */
    public function __destruct()
    {
        if (Teardown::$releasing) {
            if ($this->arr !== []) {
                Teardown::$pending[] = $this->arr;
            }
            if ($this->hash !== []) {
                Teardown::$pending[] = $this->hash;
            }
            if ($this->metatable !== null) {
                Teardown::$pending[] = $this->metatable;
            }
            if ($this->extra !== null) {
                Teardown::$pending[] = $this->extra;
            }
            return;
        }
        Teardown::$releasing = true;
        $this->arr = $this->hash = [];
        $this->metatable = $this->extra = null;
        if (Teardown::$pending === []) {  // nothing queued, the usual case (inline: tables are freed all the time)
            Teardown::$releasing = false;
            return;
        }
        Teardown::release();
    }

    public static function fromList(array $values): self
    {
        $table = new self(\count($values));
        $key = 1;
        foreach ($values as $value) {
            if ($value !== null) {
                $table->arr[$key] = $value;
            }
            $key++;
        }
        return $table;
    }

    /**
     * Encoded key for $extra->otherValues/otherKeys: booleans, non-integral finite
     * or infinite floats, and objects (tables, functions, threads, userdata).
     */
    public static function otherKey(mixed $key): string
    {
        if ($key === true) {
            return 'T';
        }
        if ($key === false) {
            return 'F';
        }
        if (\is_float($key)) {
            return 'f' . pack('E', $key);
        }
        return 'o' . spl_object_id($key);
    }

    /**
     * lvm.c: luaV_flttointeger with mode F2Ieq: the integer with exactly
     * the value of $number, or null if there is none.
     */
    public static function floatToInteger(float $number): ?int
    {
        // lua_numbertointeger: -2^63 <= n < 2^63 (NaN fails both tests)
        if ($number >= -9.2233720368547758E18 && $number < 9.2233720368547758E18) {
            $integer = (int) $number;
            if ((float) $integer === $number) {
                return $integer;
            }
        }
        return null;
    }

    // ltable.c: luaH_get
    public function get(mixed $key): mixed
    {
        if (\is_int($key)) {
            return $this->arr[$key] ?? null;
        }
        if (\is_string($key)) {
            return $this->hash[$key] ?? null;
        }
        if (\is_float($key)) {
            $integerKey = self::floatToInteger($key);
            if ($integerKey !== null) {
                return $this->arr[$integerKey] ?? null;
            }
            if (is_nan($key)) {
                return null;
            }
        } elseif ($key === null) {
            return null;
        }
        return $this->extra?->otherValues[self::otherKey($key)] ?? null;
    }

    /**
     * ltable.c: luaH_set / luaH_finishset without the "index is nil/NaN"
     * checks, which need the running thread for the error position (see
     * Vm::rawSet). A nil $key or NaN $key is ignored here.
     */
    public function set(mixed $key, mixed $value): void
    {
        if (\is_int($key)) {
            if ($value === null) {
                unset($this->arr[$key]);
            } else {
                $this->arr[$key] = $value;
            }
            return;
        }
        if (\is_string($key)) {
            if ($value === null) {
                unset($this->hash[$key]);
            } else {
                $this->hash[$key] = $value;
            }
            return;
        }
        if (\is_float($key)) {
            $integerKey = self::floatToInteger($key);
            if ($integerKey !== null) {
                $this->set($integerKey, $value);
                return;
            }
            if (is_nan($key)) {
                return;
            }
        } elseif ($key === null) {
            return;
        }
        $encodedKey = self::otherKey($key);
        if ($value !== null) {
            $extra = $this->extra ??= new LuaTableExtra();
            $extra->otherValues[$encodedKey] = $value;
            $extra->otherKeys[$encodedKey] = $key;
        } elseif ($this->extra !== null) {
            unset($this->extra->otherValues[$encodedKey], $this->extra->otherKeys[$encodedKey]);
        }
    }

    /**
     * ltable.c: luaH_getn. Returns a border: an index b with t[b] present
     * and t[b+1] absent, or 0 if t[1] is absent.
     */
    public function length(): int
    {
        $limit = $this->sizearray;
        if ($limit > 0 && !isset($this->arr[$limit])) {
            // there must be a border before 'limit'
            if ($limit >= 2 && isset($this->arr[$limit - 1])) {
                $this->sizearray = $limit - 1;
                return $limit - 1;
            }
            // ltable.c: binsearch in [0, limit]
            $present = 0;
            $absent = $limit;
            while ($absent - $present > 1) {
                $middle = $present + intdiv($absent - $present, 2);
                if (isset($this->arr[$middle])) {
                    $present = $middle;
                } else {
                    $absent = $middle;
                }
            }
            $this->sizearray = $present;
            return $present;
        }
        // 'limit' is zero or present in table
        if ($limit === PHP_INT_MAX || !isset($this->arr[$limit + 1])) {
            return $limit;
        }
        if ($limit + 1 === PHP_INT_MAX || !isset($this->arr[$limit + 2])) {
            $this->sizearray = $limit + 1;
            return $limit + 1;
        }
        $border = $this->hashSearch($limit + 2);
        $this->sizearray = $border;
        return $border;
    }

    /** ltable.c: hash_search; t[$present] is present */
    private function hashSearch(int $present): int
    {
        $absent = $present;
        do {
            $present = $absent;
            if ($absent <= intdiv(PHP_INT_MAX, 2)) {
                $absent *= 2;
            } else {
                $absent = PHP_INT_MAX;
                if (!isset($this->arr[$absent])) {
                    break;
                }
                return $absent;  // max integer is a border
            }
        } while (isset($this->arr[$absent]));
        while ($absent - $present > 1) {
            $middle = $present + intdiv($absent - $present, 2);
            if (isset($this->arr[$middle])) {
                $present = $middle;
            } else {
                $absent = $middle;
            }
        }
        return $present;
    }

    /**
     * ltable.c: luaH_next. Returns [key, value] of the entry after $key
     * (nil: the first entry), null when there are no more entries, or false
     * when $key is not in the table ("invalid key to 'next'").
     *
     * @return array{mixed, mixed}|null|false
     */
    public function next(mixed $key): array|null|false
    {
        if ($key === null) {
            return $this->firstFromPart(0);
        }
        if (\is_float($key) && self::floatToInteger($key) !== null) {
            // ltable.c: findindex does not normalize the key: a float is
            // never equal to a stored key with an integral value
            return false;
        }
        $extra = $this->extra;
        if (\is_int($key)) {
            $part = 0;
            $phpKey = $key;
        } elseif (\is_string($key)) {
            $part = 1;
            $phpKey = $key;
        } elseif ($extra === null) {
            return false;  // no key of another type was ever returned or is present
        } else {
            $part = 2;
            $phpKey = self::otherKey($key);
        }

        // the part's internal pointer is on the key, where the next() that returned it left it
        $currentKey = $this->currentPhpKey($part);
        if ($currentKey !== null && self::samePhpKey($part, $currentKey, $phpKey)) {
            $this->advancePart($part);
            return $this->currentOrFollowing($part);
        }
        if ($extra !== null && $extra->cursorKey === $key && !$this->hasPhpKey($part, $phpKey)) {
            // the entry under the cursor was cleared; PHP already moved the pointer past it
            return $this->currentOrFollowing($part);
        }

        // slow path: find the key's position
        if (!$this->hasPhpKey($part, $phpKey)) {
            return false;
        }
        $this->resetPart($part);
        while (true) {
            $currentKey = $this->currentPhpKey($part);
            if ($currentKey === null) {
                return false;  // unreachable: the key is present
            }
            if (self::samePhpKey($part, $currentKey, $phpKey)) {
                break;
            }
            $this->advancePart($part);
        }
        $this->advancePart($part);
        return $this->currentOrFollowing($part);
    }

    private static function samePhpKey(int $part, int|string $currentKey, int|string $phpKey): bool
    {
        if ($part === 1) {
            return (string) $currentKey === (string) $phpKey;
        }
        return $currentKey === $phpKey;
    }

    private function hasPhpKey(int $part, int|string $phpKey): bool
    {
        return match ($part) {
            0 => isset($this->arr[$phpKey]),
            1 => isset($this->hash[$phpKey]),
            default => isset($this->extra->otherValues[$phpKey]),
        };
    }

    private function currentPhpKey(int $part): int|string|null
    {
        return match ($part) {
            0 => key($this->arr),
            1 => key($this->hash),
            default => key($this->extra->otherValues),
        };
    }

    private function advancePart(int $part): void
    {
        match ($part) {
            0 => next($this->arr),
            1 => next($this->hash),
            default => next($this->extra->otherValues),
        };
    }

    private function resetPart(int $part): void
    {
        match ($part) {
            0 => reset($this->arr),
            1 => reset($this->hash),
            default => reset($this->extra->otherValues),
        };
    }


    /**
     * The entry under $part's internal pointer, or the first entry of a
     * following part; moves the cursor there.
     *
     * @return array{mixed, mixed}|null
     */
    private function currentOrFollowing(int $part): ?array
    {
        $phpKey = $this->currentPhpKey($part);
        if ($phpKey === null) {
            return $part < 2 ? $this->firstFromPart($part + 1) : $this->endTraversal();
        }
        return $this->entryAt($part, $phpKey);
    }

    /**
     * The first entry of $part or of a following part. (An empty part is
     * skipped without reset(), which would copy an empty array into a new
     * one: see endTraversal.)
     *
     * @return array{mixed, mixed}|null
     */
    private function firstFromPart(int $part): ?array
    {
        if ($part === 0 && $this->arr !== []) {
            reset($this->arr);
            return $this->entryAt(0, key($this->arr));
        }
        if ($part <= 1 && $this->hash !== []) {
            reset($this->hash);
            return $this->entryAt(1, key($this->hash));
        }
        $extra = $this->extra;
        if ($extra !== null && $extra->otherValues !== []) {
            reset($extra->otherValues);
            return $this->entryAt(2, key($extra->otherValues));
        }
        return $this->endTraversal();
    }

    /** @return array{mixed, mixed} */
    private function entryAt(int $part, int|string $phpKey): array
    {
        if ($part === 0) {
            $key = $phpKey;
            $value = $this->arr[$phpKey];
        } elseif ($part === 1) {
            $key = (string) $phpKey;
            $value = $this->hash[$phpKey];
        } else {
            $key = $this->extra->otherKeys[$phpKey];
            $value = $this->extra->otherValues[$phpKey];
        }
        $extra = $this->extra ??= new LuaTableExtra();
        $extra->cursorKey = $key;
        return [$key, $value];
    }

    /**
     * No more entries: forget the cursor. reset() and next() take the PHP
     * array by reference, which leaves the property holding it a PHP
     * reference (32 bytes more for as long as the table lives), so turn the
     * parts back into plain arrays too.
     */
    private function endTraversal(): null
    {
        $arr = $this->arr;
        unset($this->arr);
        $this->arr = $arr;
        $hash = $this->hash;
        unset($this->hash);
        $this->hash = $hash;
        $extra = $this->extra;
        if ($extra === null) {
            return null;
        }
        if ($extra->otherValues === []) {
            $this->extra = null;  // it only held the cursor
            return null;
        }
        $otherValues = $extra->otherValues;
        unset($extra->otherValues);
        $extra->otherValues = $otherValues;
        $extra->cursorKey = null;
        return null;
    }
}
