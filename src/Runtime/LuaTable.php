<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\OpCodes;
use LuaPhp\Compiler\Proto;

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
 * string keys). What most tables never need (other keys, the traversals in
 * progress) lives in $extra, a LuaTableExtra created on demand.
 *
 * Traversal (next): a traversal goes through the parts $arr, $hash and the
 * other keys, each in PHP's order (insertion order), and every traversal
 * goes on from its own key, as C's do from their key's slot: whatever other
 * next() calls ran in between, and also after its key was deleted (clearing
 * fields during a traversal is legal; adding keys makes next undefined).
 * The state is $extra->traversal (TableTraversal), there while needed.
 * - One traversal at a time holds the PHP internal pointers (the holder:
 *   cursorKey, the key it was given last). The pointer of its part is past
 *   that key, so key() names the next entry even after deletions (PHP does
 *   not move a pointer off a deleted entry; key() looks past it), and next()
 *   moves on: a pairs() loop is amortized O(1), also when it clears every
 *   field. BaseLib::next has this fast path inline.
 * - Traversals are told apart by where they call next from: the caller's
 *   instruction, code, frame and thread (isCaller; the fast path compares
 *   all but the code). So 'for k2 in next, t, k' inside the loop over k,
 *   the same loop one recursion level deeper or in another coroutine, does
 *   not take that loop's place; a loop that runs again is the same
 *   traversal.
 * - next(t) while another traversal holds a pointer gives the first key and
 *   moves nothing (an emptiness check inside a traversal is O(1)); the key
 *   is remembered as a first key given: deleted, the part's first entry
 *   follows it.
 * - A traversal at the part's first key takes the pointer, and so does one
 *   at another key when no traversal holds it (finding its key with the
 *   pointer: O(position)). The holder it replaces is suspended: a
 *   TraversalLog made for it (owned by where it calls from) keeps its place:
 *   the part's keys up to its key (O(position); just the key at the part's
 *   start), and more keys in order as it goes on (in batches as big as the
 *   log: O(1) a step). A log counts the suspended traversals at each of its
 *   keys; continuing moves the count (only the owner takes it away: a peek
 *   from elsewhere does not take a suspended traversal's place), and a log
 *   goes when its count is 0. A traversal at a key still in the table goes
 *   on in a log of its own, else takes the free pointer or gets a new log;
 *   at a deleted key, in any log that has the key.
 * - Logs are bounded (LOGGED_KEYS_PER_ENTRY per entry of the table, at most
 *   MAXIMUM_LOGS, least recently used out), and a log made out of date by
 *   added keys is not used (logIsStale). A traversal at a deleted key that
 *   no log or first key given remembers ends with "invalid key to 'next'",
 *   as a key never in the table does: past those bounds, or a traversal
 *   started by next(t) beside another whose first key is deleted after all
 *   the others ended (first keys given that are still in the table go when
 *   no traversal holds a pointer).
 *
 * Border (#): $sizearray plays the role of C's 'alimit': OP_NEWTABLE and
 * OP_SETLIST set it like the array part of a constructor, and length()
 * updates it as a hint, so '#' matches lua5.4 for tables built by
 * constructors and is O(1) for the usual append/pop patterns.
 *
 * @internal
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

    /** keys that are neither integers nor strings, and the traversals in progress; null until needed */
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

    /*
    ** {==================================================================
    ** Traversal (next): see the class comment
    ** ===================================================================
    */

    /** the parts a traversal goes through, in this order */
    private const INTEGER_KEYS = 0;  // $arr
    private const STRING_KEYS = 1;  // $hash
    private const OTHER_KEYS = 2;  // $extra->otherValues

    /** the most keys the logs of suspended traversals hold: this many per entry of the table, plus LOGGED_KEYS_EXTRA */
    private const LOGGED_KEYS_PER_ENTRY = 4;
    private const LOGGED_KEYS_EXTRA = 256;

    /** the most logs (a traversal looks through all of them for its key) */
    private const MAXIMUM_LOGS = 16;

    /** the most first keys given remembered per part (TableTraversal::$firstKeysGiven) */
    private const MAXIMUM_FIRST_KEYS_GIVEN = 16;

    /** PHP memory of a logged key: a list slot and a map bucket, rounded up */
    private const BYTES_PER_LOGGED_KEY = 64;

    /**
     * ltable.c: luaH_next. Returns [key, value] of the entry after $key
     * (nil: the first entry), null when there are no more entries, or false
     * when $key is not in the table ("invalid key to 'next'"). $L is the
     * thread whose Lua code called next (lbaselib.c: luaB_next): where that
     * code calls from tells traversals at the same key apart. PHP code
     * passes null.
     *
     * @return array{mixed, mixed}|null|false
     */
    public function next(mixed $key, ?Coroutine $L = null): array|null|false
    {
        if ($key === null) {
            return $this->firstEntryFrom(self::INTEGER_KEYS, $L, true);
        }
        // Fast path of a pairs() step: the traversal holding the internal
        // pointers (the one calling from the same place) asks for the entry
        // after its key, which the pointer of the key's part is past (key()
        // skips entries deleted since)
        $traversal = $this->extra?->traversal;
        if (
            $traversal?->holderPc === self::callerPc($L) && $traversal->cursorKey === $key
            && $traversal->holderFrameBytes === self::callerFrameBytes($L) && $traversal->holderThread === $L
        ) {
            if (\is_int($key)) {
                $nextKey = key($this->arr);
                if ($nextKey !== null) {
                    next($this->arr);
                    $traversal->cursorKey = $nextKey;
                    return [$nextKey, $this->arr[$nextKey]];
                }
            } elseif (\is_string($key)) {
                $nextKey = key($this->hash);
                if ($nextKey !== null) {
                    next($this->hash);
                    $nextKey = (string) $nextKey;
                    $traversal->cursorKey = $nextKey;
                    return [$nextKey, $this->hash[$nextKey]];
                }
            }
        }
        return $this->nextAfterKey($key, $L);
    }

    /**
     * next() for a key, in every case but the fast path: the rules are in
     * the class comment, in this order
     *
     * @return array{mixed, mixed}|null|false
     */
    private function nextAfterKey(mixed $key, ?Coroutine $L): array|null|false
    {
        if (\is_int($key)) {
            $part = self::INTEGER_KEYS;
            $phpKey = $key;
        } elseif (\is_string($key)) {
            $part = self::STRING_KEYS;
            $phpKey = self::phpStringKey($key);
        } elseif (\is_float($key) && self::floatToInteger($key) !== null) {
            // ltable.c: findindex does not normalize the key: a float is
            // never equal to a stored key with an integral value
            return false;
        } elseif ($this->extra === null) {
            return false;  // no key of another type is or was in the table
        } else {
            $part = self::OTHER_KEYS;
            $phpKey = self::otherKey($key);
        }
        $traversal = $this->extra?->traversal;

        // the holder's key (deleted or not): the pointer is past it
        if ($traversal !== null && $traversal->cursorKey === $key) {
            if (!self::callsLikeHolder($traversal, $L, true)) {
                $this->suspendHolder($traversal, $L);  // another traversal at that key: the holder stays
            }
            return $this->holderEntryAtPointer($part, $L);
        }
        if (!$this->hasPhpKey($part, $phpKey)) {
            // a deleted key (or one never in the table): only a log or a first key given knows its place
            if ($traversal === null) {
                return false;
            }
            $log = $this->findLog($traversal, $part, $phpKey, $L, false);
            if ($log !== null) {
                return $this->continueFromLog($log, $phpKey, $L);
            }
            if (isset($traversal->firstKeysGiven[$part][$phpKey])) {
                return $this->firstEntryFrom($part, $L);  // every key before it was gone when it was the first
            }
            return false;
        }
        if ($phpKey === $this->firstPhpKey($part)) {
            // a traversal at the part's first key takes the pointer
            $ownLog = $traversal === null ? null : $this->findLog($traversal, $part, $phpKey, $L, true);
            if ($ownLog !== null) {
                $this->leaveLogKey($traversal, $ownLog, $phpKey);  // it is no longer suspended there
            }
            $this->suspendHolder($traversal, $L);
            $this->resetPointer($part);
            $this->advancePointer($part);
            return $this->holderEntryAtPointer($part, $L);
        }
        $ownLog = $traversal === null ? null : $this->findLog($traversal, $part, $phpKey, $L, true);
        if ($ownLog !== null) {
            return $this->continueFromLog($ownLog, $phpKey, $L);
        }
        if ($traversal === null || $traversal->cursorKey === null) {
            // the pointer is free: find the key with it (C finds a key's slot by hashing it)
            $this->resetPointer($part);
            while ($this->pointerPhpKey($part) !== $phpKey) {
                $this->advancePointer($part);
            }
            $this->advancePointer($part);
            $L?->globalState->budget?->chargeSteps($this->partCount($part) >> 6);
            return $this->holderEntryAtPointer($part, $L);
        }
        // the pointer is busy: a log of its own for a traversal from this key
        $log = $this->logUpTo($part, $phpKey, true, $L);
        $log->ownerPc = self::callerPc($L);
        $log->ownerProto = self::callerProto($L);
        $log->ownerFrameBytes = self::callerFrameBytes($L);
        $this->rememberLog($traversal, $log);
        return $this->continueFromLog($log, $phpKey, $L);
    }

    /**
     * The first entry of $part or of a following part, for a traversal that
     * starts there ($isNextOfNil: next(t)) or goes on there. It takes the
     * part's internal pointer when no traversal holds one (or the holder
     * calls: a loop that runs again). Else next(t) remembers the key as a
     * first key given (an emptiness check costs nothing more), and a
     * traversal that goes on there gets a log of its own at the start.
     *
     * @return array{mixed, mixed}|null
     */
    private function firstEntryFrom(int $part, ?Coroutine $L, bool $isNextOfNil = false): ?array
    {
        for (; $part <= self::OTHER_KEYS; $part++) {
            if ($this->partCount($part) === 0) {
                continue;  // (no reset(): it would copy an empty array into a new one, see unreferencePart)
            }
            $traversal = $this->traversal();
            if ($traversal->cursorKey === null || self::callsLikeHolder($traversal, $L, false)) {
                // (the holder's old place is kept: it may be another traversal from the same call,
                // as iterator objects that step through one function are)
                $this->suspendHolder($traversal, $L);
                $this->resetPointer($part);
                return $this->holderEntryAtPointer($part, $L);
            }
            $firstKey = $this->firstPhpKey($part);
            if ($isNextOfNil) {
                self::rememberFirstKeyGiven($traversal, $part, $firstKey);
            } else {
                $this->keepPlace(
                    $traversal,
                    $part,
                    $firstKey,
                    self::callerPc($L),
                    self::callerProto($L),
                    self::callerFrameBytes($L),
                    $L,
                );
            }
            return $this->entry($part, $firstKey);
        }
        $this->dropTraversalIfIdle();
        return null;
    }

    /**
     * The first entry at or after the pointer of $part (key() skips deleted
     * ones), given to the caller as the new holder with the pointer moved
     * past it; or the first entry of a following part.
     *
     * @return array{mixed, mixed}|null
     */
    private function holderEntryAtPointer(int $part, ?Coroutine $L): ?array
    {
        $phpKey = $this->pointerPhpKey($part);
        if ($phpKey === null) {
            return $this->leavePart($part, $L);
        }
        $this->advancePointer($part);
        self::setHolder($this->traversal(), $this->luaKey($part, $phpKey), $L);
        return $this->entry($part, $phpKey);
    }

    /**
     * The holder ran past the last entry of $part: it lets the pointer go
     * and goes on with the following parts.
     *
     * @return array{mixed, mixed}|null
     */
    private function leavePart(int $part, ?Coroutine $L): ?array
    {
        $traversal = $this->extra?->traversal;
        if ($traversal !== null) {  // (none when the pointer happened to be past the caller's key)
            $traversal->cursorKey = null;
        }
        $this->unreferencePart($part);
        return $this->firstEntryFrom($part + 1, $L);
    }

    private static function setHolder(TableTraversal $traversal, mixed $key, ?Coroutine $L): void
    {
        $traversal->cursorKey = $key;
        $traversal->holderPc = self::callerPc($L);
        $traversal->holderProto = self::callerProto($L);
        $traversal->holderFrameBytes = self::callerFrameBytes($L);
        $traversal->holderThread = $L;
    }

    /** The holder gives up the pointer: a log of its own keeps its place, its key (deleted or not). */
    private function suspendHolder(?TableTraversal $traversal, ?Coroutine $L): void
    {
        if ($traversal === null || $traversal->cursorKey === null) {
            return;
        }
        $holderKey = $traversal->cursorKey;
        $traversal->cursorKey = null;
        if (\is_int($holderKey)) {
            $part = self::INTEGER_KEYS;
            $phpKey = $holderKey;
        } elseif (\is_string($holderKey)) {
            $part = self::STRING_KEYS;
            $phpKey = self::phpStringKey($holderKey);
        } else {
            $part = self::OTHER_KEYS;
            $phpKey = self::otherKey($holderKey);
        }
        $this->keepPlace(
            $traversal,
            $part,
            $phpKey,
            $traversal->holderPc,
            $traversal->holderProto,
            $traversal->holderFrameBytes,
            $L,
        );
    }

    /**
     * A suspended traversal is at $phpKey of $part (deleted or not; if
     * deleted, the pointer of the part, past it, says where it was): a log
     * made for that traversal (calling from $pc, $proto, $frameBytes) counts
     * it there: a log it has that has the key, or a new one (just the key
     * when it is at the part's start, else the part's keys up to it).
     */
    private function keepPlace(
        TableTraversal $traversal,
        int $part,
        int|string $phpKey,
        int $pc,
        ?Proto $proto,
        int $frameBytes,
        ?Coroutine $L,
    ): void {
        foreach ($traversal->logs as $log) {
            if (
                $log->part === $part && isset($log->positions[$phpKey]) && $log->ownerPc === $pc
                && $log->ownerProto === $proto && $log->ownerFrameBytes === $frameBytes && !$this->logIsStale($log)
            ) {
                $this->enterLogKey($log, $phpKey);
                return;
            }
        }
        $isPresent = $this->hasPhpKey($part, $phpKey);
        $followingKey = $isPresent ? null : $this->pointerPhpKey($part);
        if ($isPresent ? $phpKey === $this->firstPhpKey($part) : $followingKey === $this->firstPhpKey($part)) {
            $log = new TraversalLog($part);  // at the start: the part's first entry follows it
            $log->append($phpKey);
            $this->readPartState($log);
        } elseif ($isPresent) {
            $log = $this->logUpTo($part, $phpKey, true, $L);
        } elseif ($followingKey === null) {
            $log = new TraversalLog($part);  // deleted after the last entry: nothing follows
            $log->append($phpKey);
            $log->complete = true;
            $this->readPartState($log);
        } else {
            $log = $this->logUpTo($part, $followingKey, false, $L);
            $log->append($phpKey);
        }
        $log->ownerPc = $pc;
        $log->ownerProto = $proto;
        $log->ownerFrameBytes = $frameBytes;
        $this->rememberLog($traversal, $log);
        $this->enterLogKey($log, $phpKey);
    }

    /** a first key of $part that next(t) gave while another traversal held a pointer (TableTraversal::$firstKeysGiven) */
    private static function rememberFirstKeyGiven(TableTraversal $traversal, int $part, int|string $phpKey): void
    {
        if (isset($traversal->firstKeysGiven[$part][$phpKey])) {
            return;
        }
        if (\count($traversal->firstKeysGiven[$part] ?? []) >= self::MAXIMUM_FIRST_KEYS_GIVEN) {
            unset($traversal->firstKeysGiven[$part][array_key_first($traversal->firstKeysGiven[$part])]);
        }
        $traversal->firstKeysGiven[$part][$phpKey] = true;
    }

    /**
     * A log of the keys of $part, in order, up to $lastPhpKey (included or
     * not); the rest is read when needed.
     */
    private function logUpTo(int $part, int|string $lastPhpKey, bool $includeLast, ?Coroutine $L): TraversalLog
    {
        $this->reserveLogMemory($part, $L);
        $log = new TraversalLog($part);
        foreach ($this->partEntries($part) as $phpKey => $_) {
            if ($phpKey === $lastPhpKey) {
                if ($includeLast) {
                    $log->append($phpKey);
                }
                break;
            }
            $log->append($phpKey);
        }
        $L?->globalState->budget?->chargeSteps(\count($log->keys) >> 6);
        $this->readPartState($log);
        return $log;
    }

    /**
     * The traversal at $phpKey of $log goes on with the next key of the log
     * still in the table, or with the following parts, where it is counted.
     * Only the traversal the log was made for takes its count away from
     * $phpKey: a peek, next(t, k) from elsewhere, must not take the place
     * of a traversal suspended there (a count left by one that went on
     * keeps the log until the bounds evict it).
     *
     * @return array{mixed, mixed}|null
     */
    private function continueFromLog(TraversalLog $log, int|string $phpKey, ?Coroutine $L): ?array
    {
        $traversal = $this->extra->traversal;
        $part = $log->part;
        $isOwner = self::isCaller($log->ownerPc, $log->ownerProto, $log->ownerFrameBytes, $L, true);
        if ($isOwner) {
            $this->leaveLogKey($traversal, $log, $phpKey, false);
        }
        $logId = spl_object_id($log);
        if (isset($traversal->logs[$logId])) {
            unset($traversal->logs[$logId]);  // now the most recently used
            $traversal->logs[$logId] = $log;
        }
        $position = $log->positions[$phpKey];
        while (true) {
            $keyCount = \count($log->keys);
            for ($index = $position + 1; $index < $keyCount; $index++) {
                $candidate = $log->keys[$index];
                if (!$this->hasPhpKey($part, $candidate)) {
                    continue;
                }
                // (counted there even when the holder is at that key too: if the two call from
                // one place, the one that calls first moves the pointer, the other finds the count)
                $this->enterLogKey($log, $candidate);
                return $this->entry($part, $candidate);
            }
            if ($log->complete) {
                break;
            }
            $position = $keyCount - 1;
            $this->extendLog($traversal, $log, $L);
        }
        if ($log->traversalCount === 0) {
            $this->forgetLog($traversal, $log);
        }
        return $this->firstEntryFrom($part + 1, $L);  // its traversal leaves the part
    }

    /**
     * Add keys of the part not in the log yet, in order: those after the
     * log's last key, as many as it has (at least 16), so that a traversal
     * through a log costs O(1) a step; the log is complete at the part's end.
     */
    private function extendLog(TableTraversal $traversal, TraversalLog $log, ?Coroutine $L): void
    {
        $this->reserveLogMemory($log->part, $L);
        $keyCount = \count($log->keys);
        $wanted = max(16, $keyCount);
        $added = 0;
        $complete = true;
        foreach ($this->partEntries($log->part) as $phpKey => $_) {
            if (isset($log->positions[$phpKey])) {
                continue;
            }
            if ($added === $wanted) {
                $complete = false;
                break;
            }
            $log->append($phpKey);
            $added++;
        }
        $L?->globalState->budget?->chargeSteps(($keyCount + $added) >> 6);
        $log->complete = $complete;
        $this->readPartState($log);
        $traversal->loggedKeyCount += $added;
        $this->evictLogs($traversal, $log);
    }

    /** one more traversal suspended at $phpKey of $log */
    private function enterLogKey(TraversalLog $log, int|string $phpKey): void
    {
        $log->traversalsAt[$phpKey] = ($log->traversalsAt[$phpKey] ?? 0) + 1;
        $log->traversalCount++;
    }

    /** a traversal suspended at $phpKey of $log goes on; the log goes once none is in it (unless !$forgetWhenEmpty) */
    private function leaveLogKey(
        TableTraversal $traversal,
        TraversalLog $log,
        int|string $phpKey,
        bool $forgetWhenEmpty = true,
    ): void {
        if (!isset($log->traversalsAt[$phpKey])) {
            return;  // (one that came to the key from elsewhere)
        }
        if (--$log->traversalsAt[$phpKey] === 0) {
            unset($log->traversalsAt[$phpKey]);
        }
        if (--$log->traversalCount === 0 && $forgetWhenEmpty) {
            $this->forgetLog($traversal, $log);
        }
    }

    /**
     * The log a traversal at $phpKey of $part goes on in: the most recently
     * used one made for the caller's traversal or, unless $ownLogOnly (a key
     * still in the table: a traversal that is not suspended there does not
     * take a suspended one's place), any other that has the key (each is the
     * part's order: one with a deleted key is as good as another). Out of
     * date logs go.
     */
    private function findLog(
        TableTraversal $traversal,
        int $part,
        int|string $phpKey,
        ?Coroutine $L,
        bool $ownLogOnly,
    ): ?TraversalLog {
        $ownLog = null;
        $otherLog = null;
        foreach ($traversal->logs as $log) {  // least recently used first
            if ($log->part !== $part || !isset($log->positions[$phpKey])) {
                continue;
            }
            if ($this->logIsStale($log)) {
                $this->forgetLog($traversal, $log);
            } elseif (self::isCaller($log->ownerPc, $log->ownerProto, $log->ownerFrameBytes, $L, true)) {
                $ownLog = $log;
            } else {
                $otherLog = $log;
            }
        }
        return $ownLogOnly ? $ownLog : $ownLog ?? $otherLog;
    }

    /** a new log, within the bounds on all logs */
    private function rememberLog(TableTraversal $traversal, TraversalLog $log): void
    {
        $traversal->logs[spl_object_id($log)] = $log;
        $traversal->loggedKeyCount += \count($log->keys);
        $this->evictLogs($traversal, $log);
    }

    /**
     * Logs hold at most LOGGED_KEYS_PER_ENTRY keys per entry of the table,
     * and there are at most MAXIMUM_LOGS: past that, the least recently used
     * ones go (a traversal suspended at a deleted key that nothing remembers
     * then ends with "invalid key to 'next'").
     */
    private function evictLogs(TableTraversal $traversal, TraversalLog $keep): void
    {
        $bound = self::LOGGED_KEYS_PER_ENTRY * (\count($this->arr) + \count($this->hash)
            + \count($this->extra->otherValues)) + self::LOGGED_KEYS_EXTRA;
        while (
            ($traversal->loggedKeyCount > $bound || \count($traversal->logs) > self::MAXIMUM_LOGS)
            && \count($traversal->logs) > 1
        ) {
            $oldest = $traversal->logs[array_key_first($traversal->logs)];
            if ($oldest === $keep) {  // (the one in use is never the least recently used but when just made)
                unset($traversal->logs[spl_object_id($keep)]);
                $traversal->logs[spl_object_id($keep)] = $keep;
                continue;
            }
            $this->forgetLog($traversal, $oldest);
        }
    }

    private function forgetLog(TableTraversal $traversal, TraversalLog $log): void
    {
        $logId = spl_object_id($log);
        if (isset($traversal->logs[$logId])) {
            unset($traversal->logs[$logId]);
            $traversal->loggedKeyCount -= \count($log->keys);
        }
    }

    /**
     * Whether keys were added to the log's part since it read the part: then
     * its order is out of date (adding keys during a traversal makes next
     * undefined). PHP appends every new key, so the part grew or its last
     * key changed; a part that lost as many keys and its last one is not
     * seen.
     */
    private function logIsStale(TraversalLog $log): bool
    {
        $part = $log->part;
        if ($this->partCount($part) > $log->partCountWhenRead) {
            return true;
        }
        $lastKey = $log->partLastKeyWhenRead;
        return $lastKey !== null && $this->hasPhpKey($part, $lastKey) && $this->lastPhpKey($part) !== $lastKey;
    }

    private function readPartState(TraversalLog $log): void
    {
        $log->partCountWhenRead = $this->partCount($log->part);
        $log->partLastKeyWhenRead = $this->lastPhpKey($log->part);
    }

    /** a log may hold every key of the part: check that it fits (see MemoryLimit) */
    private function reserveLogMemory(int $part, ?Coroutine $L): void
    {
        MemoryLimit::reserve($this->partCount($part) * self::BYTES_PER_LOGGED_KEY, $L?->globalState->budget);
    }

    /**
     * No traversal holds a pointer: drop the first keys given that are still
     * in the table (an emptiness check does not go on; a traversal that does
     * goes on from its first key as long as it is there: only if it is
     * deleted after every other traversal ended does it get "invalid key to
     * 'next'"), and the state when no log is left either, and the side
     * object if it holds nothing else.
     */
    private function dropTraversalIfIdle(): void
    {
        $extra = $this->extra;
        $traversal = $extra?->traversal;
        if ($traversal !== null) {
            if ($traversal->cursorKey !== null) {
                return;
            }
            foreach ($traversal->firstKeysGiven as $part => $keys) {
                foreach ($keys as $phpKey => $_) {
                    if ($this->hasPhpKey($part, $phpKey)) {
                        unset($traversal->firstKeysGiven[$part][$phpKey]);
                    }
                }
                if ($traversal->firstKeysGiven[$part] === []) {
                    unset($traversal->firstKeysGiven[$part]);
                }
            }
            if ($traversal->logs !== [] || $traversal->firstKeysGiven !== []) {
                return;
            }
            $extra->traversal = null;
        }
        if ($extra !== null && $extra->otherValues === []) {
            $this->extra = null;
        }
    }

    private function traversal(): TableTraversal
    {
        $extra = $this->extra ??= new LuaTableExtra();
        return $extra->traversal ??= new TableTraversal();
    }

    /** where Lua code calls next from: the savedpc of the caller (the instruction of the call), -1 for PHP code */
    private static function callerPc(?Coroutine $L): int
    {
        return $L === null ? -1 : $L->ci->previous->savedpc;
    }

    /** ... and its code (null: not a Lua function) */
    private static function callerProto(?Coroutine $L): ?Proto
    {
        $function = $L?->ci->previous->func;
        return $function instanceof LuaClosure ? $function->proto : null;
    }

    /** ... and its frame (see TableTraversal::$holderFrameBytes) */
    private static function callerFrameBytes(?Coroutine $L): int
    {
        return $L === null ? -1 : $L->ci->previous->frameBytes;
    }

    /** whether the caller looks like the holder (isCaller, in the holder's thread) */
    private static function callsLikeHolder(TableTraversal $traversal, ?Coroutine $L, bool $sameRegisterCounts): bool
    {
        return $traversal->holderThread === $L && self::isCaller(
            $traversal->holderPc,
            $traversal->holderProto,
            $traversal->holderFrameBytes,
            $L,
            $sameRegisterCounts,
        );
    }

    /**
     * Whether the code calling next now (see callerPc) is the traversal that
     * called it from ($pc, $proto, $frameBytes) before: the same instruction
     * in the same frame (a pairs loop's OP_TFORCALL, a 'k = next(t, k)'
     * loop) or, with $sameRegisterCounts, another call of that frame into
     * the same register ('k = next(t, k)' in two branches; used where both
     * are at one key). Calls from one place cannot be told apart (iterator
     * objects stepping through one function): the counts at logs and start
     * keys keep a place for every traversal there.
     */
    private static function isCaller(
        int $pc,
        ?Proto $proto,
        int $frameBytes,
        ?Coroutine $L,
        bool $sameRegisterCounts,
    ): bool {
        if ($frameBytes !== self::callerFrameBytes($L) || $proto !== self::callerProto($L)) {
            return false;
        }
        $callerPc = self::callerPc($L);
        if ($pc === $callerPc) {
            return true;
        }
        return $sameRegisterCounts && $proto !== null
            && OpCodes::getArgA($proto->code[$pc]) === OpCodes::getArgA($proto->code[$callerPc]);
    }

    /**
     * a Lua string key as PHP stores it in $hash: a decimal integer in range
     * without a leading zero or plus sign ("10", "-5", not "-0") is an integer
     */
    private static function phpStringKey(string $key): int|string
    {
        $integer = (int) $key;
        return (string) $integer === $key ? $integer : $key;
    }

    private function hasPhpKey(int $part, int|string $phpKey): bool
    {
        return match ($part) {
            self::INTEGER_KEYS => isset($this->arr[$phpKey]),
            self::STRING_KEYS => isset($this->hash[$phpKey]),
            default => isset($this->extra->otherValues[$phpKey]),
        };
    }

    private function partCount(int $part): int
    {
        return match ($part) {
            self::INTEGER_KEYS => \count($this->arr),
            self::STRING_KEYS => \count($this->hash),
            default => \count($this->extra->otherValues ?? []),
        };
    }

    /** the entries of $part, to read (moving its internal pointer while it is read would copy it) */
    private function partEntries(int $part): array
    {
        return match ($part) {
            self::INTEGER_KEYS => $this->arr,
            self::STRING_KEYS => $this->hash,
            default => $this->extra->otherValues,
        };
    }

    private function pointerPhpKey(int $part): int|string|null
    {
        return match ($part) {
            self::INTEGER_KEYS => key($this->arr),
            self::STRING_KEYS => key($this->hash),
            default => key($this->extra->otherValues),
        };
    }

    private function firstPhpKey(int $part): int|string|null
    {
        return match ($part) {
            self::INTEGER_KEYS => array_key_first($this->arr),
            self::STRING_KEYS => array_key_first($this->hash),
            default => array_key_first($this->extra->otherValues),
        };
    }

    private function lastPhpKey(int $part): int|string|null
    {
        return match ($part) {
            self::INTEGER_KEYS => array_key_last($this->arr),
            self::STRING_KEYS => array_key_last($this->hash),
            default => array_key_last($this->extra->otherValues),
        };
    }

    private function advancePointer(int $part): void
    {
        match ($part) {
            self::INTEGER_KEYS => next($this->arr),
            self::STRING_KEYS => next($this->hash),
            default => next($this->extra->otherValues),
        };
    }

    private function resetPointer(int $part): void
    {
        match ($part) {
            self::INTEGER_KEYS => reset($this->arr),
            self::STRING_KEYS => reset($this->hash),
            default => reset($this->extra->otherValues),
        };
    }

    private function luaKey(int $part, int|string $phpKey): mixed
    {
        return match ($part) {
            self::INTEGER_KEYS => $phpKey,
            self::STRING_KEYS => (string) $phpKey,
            default => $this->extra->otherKeys[$phpKey],
        };
    }

    /** @return array{mixed, mixed} */
    private function entry(int $part, int|string $phpKey): array
    {
        return match ($part) {
            self::INTEGER_KEYS => [$phpKey, $this->arr[$phpKey]],
            self::STRING_KEYS => [(string) $phpKey, $this->hash[$phpKey]],
            default => [$this->extra->otherKeys[$phpKey], $this->extra->otherValues[$phpKey]],
        };
    }

    /**
     * reset() and next() take the PHP array by reference, which leaves the
     * property holding it a PHP reference (32 bytes more for as long as the
     * table lives): turn it back into a plain array when its traversal is
     * over.
     */
    private function unreferencePart(int $part): void
    {
        if ($part === self::INTEGER_KEYS) {
            $arr = $this->arr;
            unset($this->arr);
            $this->arr = $arr;
        } elseif ($part === self::STRING_KEYS) {
            $hash = $this->hash;
            unset($this->hash);
            $this->hash = $hash;
        } else {
            $extra = $this->extra;
            $otherValues = $extra->otherValues;
            unset($extra->otherValues);
            $extra->otherValues = $otherValues;
        }
    }

    /* }================================================================== */
}
