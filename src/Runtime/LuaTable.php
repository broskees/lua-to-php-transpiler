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
 * - $otherValues/$otherKeys: float (non-integral), boolean and object
 *   keys, by an encoded string key (see otherKey()).
 * Nil values are never stored: assigning nil removes the entry.
 *
 * Emitted code and runtime helpers read and write $arr and $hash directly
 * for the fast paths; everything else goes through the methods here.
 *
 * Traversal (next): PHP arrays keep their order, so the "position" of a key
 * is its place in its part. A cursor remembers the last key returned; its
 * part's PHP internal array pointer stays on that key, so next() during a
 * pairs() loop is amortized O(1). Clearing the current field during the
 * traversal (allowed in Lua) makes PHP advance the internal pointer to the
 * following entry, which the cursor recognizes.
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

    /** @var array<string, mixed> */
    public array $otherValues = [];

    /** @var array<string, mixed> */
    public array $otherKeys = [];

    public ?LuaTable $metatable = null;

    /** border hint (C: alimit); see class comment */
    public int $sizearray = 0;

    /** part (0 = $arr, 1 = $hash, 2 = $otherValues) whose internal pointer is on the last key returned by next() */
    private int $cursorPart = -1;

    /** the last key returned by next() (a Lua value) */
    private mixed $cursorKey = null;

    public function __construct(int $sizearray = 0)
    {
        $this->sizearray = $sizearray;
    }

    /**
     * A table with the given values at keys 1..n (nils skipped), like the
     * table built by '{...}'.
     *
     * @param list<mixed> $values
     */
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
     * Encoded key for $otherValues/$otherKeys: booleans, non-integral finite
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
        return $this->otherValues[self::otherKey($key)] ?? null;
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
        if ($value === null) {
            unset($this->otherValues[$encodedKey], $this->otherKeys[$encodedKey]);
        } else {
            $this->otherValues[$encodedKey] = $value;
            $this->otherKeys[$encodedKey] = $key;
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
        if (\is_float($key)) {
            $integerKey = self::floatToInteger($key);
            if ($integerKey !== null) {
                $key = $integerKey;
            }
        }
        if (\is_int($key)) {
            $part = 0;
            $phpKey = $key;
        } elseif (\is_string($key)) {
            $part = 1;
            $phpKey = $key;
        } else {
            $part = 2;
            $phpKey = self::otherKey($key);
        }

        if ($this->cursorPart === $part) {
            $currentKey = $this->currentPhpKey($part);
            if ($currentKey !== null && self::samePhpKey($part, $currentKey, $phpKey)) {
                $this->advancePart($part);
                return $this->currentOrFollowing($part);
            }
            if ($this->cursorKey === $key && !$this->hasPhpKey($part, $phpKey)) {
                // the entry under the cursor was cleared; PHP already moved the pointer past it
                return $this->currentOrFollowing($part);
            }
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
            default => isset($this->otherValues[$phpKey]),
        };
    }

    private function currentPhpKey(int $part): int|string|null
    {
        return match ($part) {
            0 => key($this->arr),
            1 => key($this->hash),
            default => key($this->otherValues),
        };
    }

    private function advancePart(int $part): void
    {
        match ($part) {
            0 => next($this->arr),
            1 => next($this->hash),
            default => next($this->otherValues),
        };
    }

    private function resetPart(int $part): void
    {
        match ($part) {
            0 => reset($this->arr),
            1 => reset($this->hash),
            default => reset($this->otherValues),
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

    /** @return array{mixed, mixed}|null */
    private function firstFromPart(int $part): ?array
    {
        for (; $part <= 2; $part++) {
            $this->resetPart($part);
            $phpKey = $this->currentPhpKey($part);
            if ($phpKey !== null) {
                return $this->entryAt($part, $phpKey);
            }
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
            $key = $this->otherKeys[$phpKey];
            $value = $this->otherValues[$phpKey];
        }
        $this->cursorPart = $part;
        $this->cursorKey = $key;
        return [$key, $value];
    }

    private function endTraversal(): null
    {
        $this->cursorPart = -1;
        $this->cursorKey = null;
        return null;
    }
}
