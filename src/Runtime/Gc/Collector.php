<?php

declare(strict_types=1);

namespace LuaPhp\Runtime\Gc;

use LuaPhp\Compiler\OpCodes;
use LuaPhp\Compiler\Proto;
use LuaPhp\Lib\BaseLib;
use LuaPhp\Lib\String\StringFormat;
use LuaPhp\Runtime\CallInfo;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\GlobalState;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MetaMethods;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Userdata;

/**
 * Port of lgc.c's observable behaviour on top of PHP's memory management.
 *
 * PHP frees memory itself (reference counting and its cycle collector),
 * so nothing is swept here. What Lua programs can observe of their
 * collector (weak tables, finalizers, "count") comes from a mark phase
 * that runs lgc.c's 'atomic' in one go: everything reachable from the
 * roots is marked, weak tables are cleared, and unreachable objects
 * marked for finalization are separated, resurrected and finalized
 * (GCTM). Weak tables hold their entries strongly until a collection
 * clears them, and the collector holds objects with finalizers
 * (GlobalState::$finobj/$tobefnz) until they are finalized, so PHP frees
 * neither before Lua would.
 *
 * Roots are C's: the registry, the metatables of basic types, the main
 * thread, the running thread and the objects waiting for their
 * finalizers. A thread's stack is its CallInfo chain. Like
 * traversethread, only the live part of a frame is marked: a Lua frame
 * calling a function (OP_CALL, OP_TAILCALL, OP_TFORCALL at its savedpc)
 * owns the registers below the call, and the frame running an allocation
 * instruction owns the registers up to its result (lvm.c: checkGC(L,
 * ra + 1)); dead registers above are not roots, as in C.
 *
 * Every collection is a complete cycle. collectgarbage("step") and the
 * automatic steps after OP_NEWTABLE, OP_CONCAT and OP_CLOSURE (lvm.c:
 * checkGC, when GlobalState::$gcDebt becomes positive) run a whole cycle
 * with all its finalizers. Those instructions charge the debt with what
 * C would allocate; the budget between cycles follows lgc.c's setpause.
 * "count" is the estimate of the last cycle (C sizes of what it found
 * reachable) plus what was allocated since.
 *
 * @internal
 */
final class Collector
{
    // lgc.h: bits in 'gcstp'
    public const GCSTPUSR = 1;  // bit true when GC stopped by user
    public const GCSTPGC = 2;   // bit true when GC stopped by itself
    public const GCSTPCLS = 4;  // bit true when closing Lua state

    // lstate.h: kinds of Garbage Collection
    public const KGC_INC = 0;  // incremental gc
    public const KGC_GEN = 1;  // generational gc

    // sizes of the C objects (64-bit), for the debt and the estimate
    private const TABLE_SIZE = 56;        // sizeof(Table)
    private const TVALUE_SIZE = 16;       // sizeof(TValue): array slots, stack slots
    private const NODE_SIZE = 24;         // sizeof(Node): hash slots
    public const STRING_OVERHEAD = 25;    // lstring.h: sizelstring(0), with the '\0'
    private const LCLOSURE_SIZE = 32;     // lfunc.h: sizeLclosure(0)
    private const CCLOSURE_SIZE = 32;     // lfunc.h: sizeCclosure(0)
    private const UPVALUE_POINTER = 8;
    private const UPVAL_SIZE = 40;        // sizeof(UpVal)
    private const UDATA_SIZE = 40;        // lstate.h: sizeudata(0, 0)
    private const THREAD_SIZE = 208;      // sizeof(LX)
    private const CALLINFO_SIZE = 64;     // sizeof(CallInfo)
    private const PROTO_SIZE = 128;       // sizeof(Proto)

    /**
     * Pacing. Each cycle traces everything reachable at once (about twice
     * the PHP time of allocating it), where C spreads that work over
     * incremental steps or does cheap young collections. So a cycle waits
     * until memory reaches at least MIN_PAUSE percent of the estimate
     * (C's default pause is 200) and at least MIN_BUDGET more bytes.
     */
    private const MIN_PAUSE = 400;
    private const MIN_BUDGET = 1 << 20;

    /** @var array<int, true> objects marked in this cycle, by spl_object_id (C: not white) */
    private array $marked = [];

    /** @var list<object> marked objects still to traverse (C: 'gray') */
    private array $gray = [];

    /** @var list<LuaTable> tables with weak values (C: 'weak') */
    private array $weak = [];

    /** @var list<LuaTable> all-weak tables and ephemerons with unmarked keys (C: 'allweak') */
    private array $allweak = [];

    /** @var list<LuaTable> ephemerons with unmarked key -> unmarked value entries (C: 'ephemeron') */
    private array $ephemeron = [];

    /** @var list<LuaTable> every weak table marked, for the estimate of what survives their clearing */
    private array $weakTables = [];

    /** C sizes of everything marked */
    private int $estimate = 0;

    private function __construct(
        /** the running thread */
        private readonly Coroutine $L,
        /** live registers of the running thread's top frame (-1: all) */
        private readonly int $runningTop,
    ) {
    }

    /** the bytes charged for OP_NEWTABLE's table (ltable.c: luaH_new, luaH_resize) */
    public static function tableSize(int $arraySize, int $hashSize): int
    {
        return self::TABLE_SIZE + self::TVALUE_SIZE * $arraySize + self::NODE_SIZE * $hashSize;
    }

    /** the bytes charged for OP_CLOSURE's closure (lfunc.c: luaF_newLclosure) */
    public static function closureSize(int $upvalueCount): int
    {
        return self::LCLOSURE_SIZE + self::UPVALUE_POINTER * $upvalueCount;
    }

    /**
     * lapi.c: lua_setmetatable for a table or full userdata: set it and
     * mark the object for finalization if the metatable has '__gc'.
     */
    public static function setMetatable(Coroutine $L, LuaTable|Userdata $object, ?LuaTable $metatable): void
    {
        $object->metatable = $metatable;
        if ($metatable !== null) {
            self::checkFinalizer($L->globalState, $object, $metatable);
        }
    }

    /**
     * lgc.c: luaC_checkfinalizer: an object whose metatable has a '__gc'
     * field (any value) when it gets that metatable is marked for
     * finalization, once.
     */
    public static function checkFinalizer(GlobalState $G, LuaTable|Userdata $object, LuaTable $metatable): void
    {
        $id = spl_object_id($object);
        if (isset($G->finobj[$id]) || isset($G->tobefnz[$id])  // obj. is already marked...
            || ($metatable->hash['__gc'] ?? null) === null      // or has no finalizer...
            || ($G->gcstp & self::GCSTPCLS) !== 0) {            // or closing state?
            return;  // nothing to be done
        }
        $G->finobj[$id] = $object;  // link it in 'finobj' list
    }

    /**
     * lgc.c: luaC_step, as the automatic check after an allocation calls
     * it (lvm.c: checkGC) once the debt is positive. $top is the number of
     * live registers of the running Lua frame.
     */
    public static function step(Coroutine $L, int $top = -1): void
    {
        $G = $L->globalState;
        if ($G->gcstp !== 0) {  // not running?
            self::setDebt($G, -2000);
            return;
        }
        self::cycle($L, $top);
    }

    /** lgc.c: luaC_fullgc (lapi.c: LUA_GCCOLLECT) */
    public static function fullGc(Coroutine $L): void
    {
        self::cycle($L, -1);
        gc_collect_cycles();  // let PHP free cyclic garbage now too
    }

    /**
     * lapi.c: lua_gc(L, LUA_GCSTEP, $data): a basic step, or add $data KB
     * to the debt and step if it is positive. Returns whether a cycle
     * ended, which never happens in generational mode (C's generational
     * collector does not go through the 'pause' state).
     */
    public static function stepCommand(Coroutine $L, int $data): bool
    {
        $G = $L->globalState;
        $debt = 1;  // =1 to signal that it did an actual step
        $oldstp = $G->gcstp;
        $G->gcstp = 0;  // allow GC to run (GCSTPGC must be zero here)
        if ($data === 0) {
            self::setDebt($G, 0);  // do a basic step
            self::step($L);
        } else {  // add 'data' to total debt
            $debt = $data * 1024 + $G->gcDebt;
            self::setDebt($G, $debt);
            if ($G->gcDebt > 0) {  // lgc.h: luaC_checkGC
                self::step($L);
            }
        }
        $G->gcstp = $oldstp;  // restore previous state
        return $debt > 0 && $G->gckind === self::KGC_INC;  // end of cycle? (each of ours is complete)
    }

    /**
     * lgc.c: luaC_changemode. Entering generational mode starts with a
     * full collection (entergen).
     */
    public static function changeMode(Coroutine $L, int $newMode): void
    {
        $G = $L->globalState;
        if ($newMode === $G->gckind) {
            return;
        }
        $G->gckind = $newMode;
        if ($newMode === self::KGC_GEN) {  // entering generational mode?
            self::cycle($L, -1);
        }
    }

    /** lapi.c: lua_gc(L, LUA_GCRESTART) */
    public static function restart(GlobalState $G): void
    {
        self::setDebt($G, 0);
        $G->gcstp = 0;  // (GCSTPGC must be already zero here)
    }

    /** lapi.c: LUA_GCCOUNT / LUA_GCCOUNTB: the bytes in use, as Lua counts them */
    public static function totalBytes(GlobalState $G): int
    {
        $charged = $G->gcTotalBytes + $G->gcDebt;  // lstate.h: gettotalbytes
        // what libraries allocated beyond the charged instructions (big strings, ...)
        $measured = $G->gcEstimate + memory_get_usage() - $G->gcRealBase;
        return max($charged, $measured);
    }

    /**
     * lstate.c: close_state + lgc.c: luaC_freeallobjects, for lua_close:
     * close the main thread's pending to-be-closed variables, then call
     * the finalizers of all objects marked for finalization, last marked
     * first. Objects marked from now on are not finalized.
     */
    public static function closeState(Coroutine $L): void
    {
        $G = $L->globalState;
        $L = $G->mainThread;  // only the main thread can be closed
        if ($L->ci !== $L->baseCi) {
            Calls::closeProtected($L, $L->ci, $L->baseCi, Lua::LUA_OK, null);  // close all upvalues
            $L->ci = $L->baseCi;  // unwind CallInfo list
        }
        $L->errfunc = null;  // stack unwind can "throw away" the error function
        $G->gcstp = self::GCSTPCLS;  // no extra finalizers after here
        $G->gckind = self::KGC_INC;
        self::separateToBeFnz($G, null);  // separate all objects with finalizers
        self::callAllPendingFinalizers($L);
    }

    /**
     * Not in C: gives up the state of $G without running more Lua code (an
     * embedded state its host throws away, or whose run LimitReached or a
     * PHP exception stopped): the pending finalizers are dropped, never
     * called, and the collector stops, so no '__gc' runs from here on;
     * pending '__close' variables are never closed either. PHP then frees
     * the state as any garbage (see Teardown).
     */
    public static function abandonState(GlobalState $G): void
    {
        $G->gcstp |= self::GCSTPCLS;
        $G->finobj = [];
        $G->tobefnz = [];
    }

    /** lstate.c: luaE_setdebt: keeps gettotalbytes() */
    private static function setDebt(GlobalState $G, int $debt): void
    {
        $totalBytes = $G->gcTotalBytes + $G->gcDebt;
        $G->gcTotalBytes = $totalBytes - $debt;
        $G->gcDebt = $debt;
    }

    /**
     * lgc.c: setpause: the next cycle starts when memory in use reaches
     * 'estimate' * pause / 100 ('genmajormul' plays that role in
     * generational mode, where all our cycles are major ones).
     */
    private static function setPause(GlobalState $G): void
    {
        $pause = $G->gckind === self::KGC_GEN ? 100 + $G->genmajormul * 4 : $G->gcpause * 4;
        $estimate = intdiv($G->gcEstimate, 100) + 1;  // adjust 'estimate' (PAUSEADJ)
        $threshold = ($pause < intdiv(PHP_INT_MAX, $estimate))  // overflow?
            ? $estimate * $pause  // no overflow
            : PHP_INT_MAX;  // overflow; truncate to maximum
        $threshold = max($threshold, $estimate * self::MIN_PAUSE, $G->gcEstimate + self::MIN_BUDGET);
        $debt = min($G->gcTotalBytes + $G->gcDebt - $threshold, 0);
        self::setDebt($G, $debt);
    }

    /**
     * One complete collection: lgc.c's atomic phase, then the finalizers
     * of the objects it found unreachable (what luaC_fullgc does in both
     * modes, and what incremental steps do over a whole cycle).
     */
    private static function cycle(Coroutine $L, int $runningTop): void
    {
        $G = $L->globalState;
        // Every object the trace touches becomes a candidate root of PHP's
        // cycle collector, which would otherwise run (over the whole heap)
        // every 10000 of them; with it paused it runs once, afterwards.
        $phpCollectorWasEnabled = gc_enabled();
        gc_disable();
        try {
            $collector = new self($L, $runningTop);
            $collector->atomic();
            $G->gcEstimate = $collector->estimate;
            unset($collector);
        } finally {
            if ($phpCollectorWasEnabled) {
                gc_enable();
            }
        }
        self::setDebt($G, 0);
        $G->gcTotalBytes = $G->gcEstimate;
        StringFormat::forgetUnreferencedStrings($G);
        $G->gcRealBase = memory_get_usage();
        self::callAllPendingFinalizers($L);
        self::setPause($G);
    }

    /** lgc.c: restartcollection + atomic */
    private function atomic(): void
    {
        $L = $this->L;
        $G = $L->globalState;
        // restartcollection: mark root set
        $this->markValue($G->mainThread);
        $this->markValue($G->registry);
        $this->markMt($G);
        $this->markBeingFnz($G);  // mark any finalizing object left from previous cycle
        // values libraries keep outside Lua (C keeps them in the registry)
        $this->markValues($G->libraryState);
        $this->markValue($L);  // mark running thread
        $this->propagateAll();
        $this->convergeEphemerons();
        // at this point, all strongly accessible objects are marked.
        // Clear values from weak tables, before checking finalizers
        $this->clearByValues($this->weak, 0);
        $this->clearByValues($this->allweak, 0);
        $originalWeak = \count($this->weak);
        $originalAllweak = \count($this->allweak);
        self::separateToBeFnz($G, $this);  // separate objects to be finalized
        $this->markBeingFnz($G);  // mark objects that will be finalized
        $this->propagateAll();  // remark, to propagate 'resurrection'
        $this->convergeEphemerons();
        // at this point, all resurrected objects are marked.
        // remove dead objects from weak tables
        $this->clearByKeys($this->ephemeron);  // clear keys from all ephemeron tables
        $this->clearByKeys($this->allweak);  // clear keys from all 'allweak' tables
        // clear values from resurrected weak tables
        $this->clearByValues($this->weak, $originalWeak);
        $this->clearByValues($this->allweak, $originalAllweak);
        foreach ($this->weakTables as $table) {
            $this->estimateStrings($table);
        }
    }

    /**
     * Collectable values can be removed from weak tables: tables, Lua
     * closures, C closures (natives with upvalues), threads and full
     * userdata. Strings are values; light C functions (natives without
     * upvalues) and light userdata are not objects.
     */
    private static function isCollectable(mixed $value): bool
    {
        return $value instanceof LuaTable
            || $value instanceof LuaClosure
            || $value instanceof Coroutine
            || $value instanceof Userdata
            || ($value instanceof NativeFunction && $value->upvalues !== []);
    }

    /**
     * lgc.c: iscleared (and valiswhite): a collectable object not marked,
     * which a weak table cannot keep.
     */
    private function isCleared(mixed $value): bool
    {
        return \is_object($value) && self::isCollectable($value) && !isset($this->marked[spl_object_id($value)]);
    }

    /** lgc.c: markvalue / markobject: marked objects are traversed later */
    private function markValue(mixed $value): void
    {
        if (\is_object($value)) {
            $id = spl_object_id($value);
            if (!isset($this->marked[$id])) {
                $this->marked[$id] = true;
                $this->gray[] = $value;
            }
        } elseif (\is_string($value)) {
            $this->estimate += self::STRING_OVERHEAD + \strlen($value);
        }
    }

    /** @param array<mixed> $values */
    private function markValues(array $values): void
    {
        foreach ($values as $value) {
            if (\is_object($value)) {
                $id = spl_object_id($value);
                if (!isset($this->marked[$id])) {
                    $this->marked[$id] = true;
                    $this->gray[] = $value;
                }
            } elseif (\is_string($value)) {
                $this->estimate += self::STRING_OVERHEAD + \strlen($value);
            }
        }
    }

    /** lgc.c: markmt: metatables of basic types */
    private function markMt(GlobalState $G): void
    {
        $this->markValues($G->typeMetatables);
    }

    /** lgc.c: markbeingfnz: objects whose finalizers are pending */
    private function markBeingFnz(GlobalState $G): void
    {
        $this->markValues($G->tobefnz);
    }

    /** lgc.c: propagateall / propagatemark: traverse gray objects until none is left */
    private function propagateAll(): void
    {
        while (($object = array_pop($this->gray)) !== null) {
            if ($object instanceof LuaTable) {
                if ($object->metatable !== null || $object->hash !== [] || $object->extra !== null) {
                    $this->traverseTable($object);
                    continue;
                }
                // the most common table, inline: no metatable, only integer keys
                $this->estimate += self::TABLE_SIZE + self::TVALUE_SIZE * \count($object->arr);
                foreach ($object->arr as $value) {
                    if (\is_object($value)) {
                        $id = spl_object_id($value);
                        if (!isset($this->marked[$id])) {
                            $this->marked[$id] = true;
                            $this->gray[] = $value;
                        }
                    } elseif (\is_string($value)) {
                        $this->estimate += self::STRING_OVERHEAD + \strlen($value);
                    }
                }
            } elseif ($object instanceof LuaClosure) {
                $this->traverseLuaClosure($object);
            } elseif ($object instanceof NativeFunction) {  // lgc.c: traverseCclosure
                if ($object->upvalues !== []) {
                    $this->estimate += self::CCLOSURE_SIZE + self::TVALUE_SIZE * \count($object->upvalues);
                    $this->markValues($object->upvalues);
                }
            } elseif ($object instanceof Userdata) {  // lgc.c: traverseudata
                $this->estimate += self::UDATA_SIZE + self::TVALUE_SIZE * \count($object->userValues);
                $this->markValue($object->metatable);
                $this->markValues($object->userValues);
            } elseif ($object instanceof Coroutine) {
                $this->traverseThread($object);
            }
            // anything else (light userdata, PHP objects in library state) has nothing to traverse
        }
    }

    /** lgc.c: traversetable */
    private function traverseTable(LuaTable $table): void
    {
        $this->estimate += self::TABLE_SIZE + self::TVALUE_SIZE * \count($table->arr)
            + self::NODE_SIZE * (\count($table->hash) + \count($table->extra->otherValues ?? []));
        $metatable = $table->metatable;
        if ($metatable !== null) {
            $this->markValue($metatable);
            $mode = $metatable->hash['__mode'] ?? null;
            if (\is_string($mode) && \strlen($mode) <= Lua::LUAI_MAXSHORTLEN) {  // is there a weak mode?
                $zero = strpos($mode, "\0");  // C reads it with strchr
                if ($zero !== false) {
                    $mode = substr($mode, 0, $zero);
                }
                $weakKeys = str_contains($mode, 'k');
                $weakValues = str_contains($mode, 'v');
                if ($weakKeys || $weakValues) {  // is really weak?
                    $this->weakTables[] = $table;
                    if (!$weakKeys) {  // strong keys?
                        $this->traverseWeakValue($table);
                    } elseif (!$weakValues) {  // strong values?
                        $this->traverseEphemeron($table, false);
                    } else {  // all weak
                        $this->allweak[] = $table;  // must clear collected entries
                    }
                    return;
                }
            }
        }
        $this->traverseStrongTable($table);
    }

    /** strings left in a cleared weak table (lgc.c: iscleared marks them: they are values, never removed) */
    private function estimateStrings(LuaTable $table): void
    {
        foreach ($table->hash as $key => $value) {
            $this->estimate += self::STRING_OVERHEAD + (\is_string($key) ? \strlen($key) : 8);
            if (\is_string($value)) {
                $this->estimate += self::STRING_OVERHEAD + \strlen($value);
            }
        }
        foreach ($table->arr as $value) {
            if (\is_string($value)) {
                $this->estimate += self::STRING_OVERHEAD + \strlen($value);
            }
        }
        foreach ($table->extra->otherValues ?? [] as $value) {
            if (\is_string($value)) {
                $this->estimate += self::STRING_OVERHEAD + \strlen($value);
            }
        }
    }

    /** lgc.c: traversestrongtable */
    private function traverseStrongTable(LuaTable $table): void
    {
        $this->markValues($table->arr);
        foreach ($table->hash as $key => $value) {
            $this->estimate += self::STRING_OVERHEAD + (\is_string($key) ? \strlen($key) : 8);
            if (\is_object($value)) {
                $id = spl_object_id($value);
                if (!isset($this->marked[$id])) {
                    $this->marked[$id] = true;
                    $this->gray[] = $value;
                }
            } elseif (\is_string($value)) {
                $this->estimate += self::STRING_OVERHEAD + \strlen($value);
            }
        }
        $extra = $table->extra;
        if ($extra !== null) {
            $this->markValues($extra->otherKeys);
            $this->markValues($extra->otherValues);
        }
    }

    /**
     * lgc.c: traverseweakvalue (atomic phase): keys are strong, values are
     * cleared later if not marked.
     */
    private function traverseWeakValue(LuaTable $table): void
    {
        $this->markValues($table->extra->otherKeys ?? []);  // markkey (integer and string keys are not objects)
        $this->weak[] = $table;  // has to be cleared later
    }

    /**
     * lgc.c: traverseephemeron: the value of an entry is marked only when
     * its key is. Returns whether it marked something. The table goes to
     * 'ephemeron' if it has entries with both key and value unmarked (it
     * must be visited again when more keys are marked), else to 'allweak'
     * if it has unmarked keys.
     */
    private function traverseEphemeron(LuaTable $table, bool $inverse): bool
    {
        $marked = false;  // true if an object is marked in this traversal
        $hasClears = false;  // true if table has white keys
        $hasWhiteWhite = false;  // true if table has entry "white-key -> white-value"
        // integer and string keys are never cleared: their values are strong
        foreach ($table->arr as $value) {
            if ($this->isCleared($value)) {
                $marked = true;
                $this->markValue($value);
            }
        }
        foreach ($table->hash as $value) {
            if ($this->isCleared($value)) {
                $marked = true;
                $this->markValue($value);
            }
        }
        // if 'inverse', traverse descending (see 'convergeEphemerons')
        $otherKeys = $table->extra->otherKeys ?? [];
        $otherValues = $table->extra->otherValues ?? [];
        $entries = $inverse ? array_reverse($otherValues, true) : $otherValues;
        foreach ($entries as $encodedKey => $value) {
            if ($this->isCleared($otherKeys[$encodedKey])) {  // key is not marked (yet)?
                $hasClears = true;  // table must be cleared
                if ($this->isCleared($value)) {  // value not marked yet?
                    $hasWhiteWhite = true;  // white-white entry
                }
            } elseif ($this->isCleared($value)) {  // value not marked yet?
                $marked = true;
                $this->markValue($value);  // mark it now
            } else {
                $this->markValue($otherKeys[$encodedKey]);  // a non-collectable key (boolean, float, light function)
            }
        }
        if ($hasWhiteWhite) {  // table has white->white entries?
            $this->ephemeron[] = $table;  // have to propagate again
        } elseif ($hasClears) {  // table has white keys?
            $this->allweak[] = $table;  // may have to clean white keys
        }
        return $marked;
    }

    /**
     * lgc.c: convergeephemerons: traverse all ephemeron tables, propagating
     * marks from keys to values, until nothing new is marked.
     */
    private function convergeEphemerons(): void
    {
        $inverse = false;
        do {
            $tables = $this->ephemeron;  // get ephemeron list
            $this->ephemeron = [];  // tables may return to this list when traversed
            $changed = false;
            foreach ($tables as $table) {  // for each ephemeron table
                if ($this->traverseEphemeron($table, $inverse)) {  // marked some value?
                    $this->propagateAll();  // propagate changes
                    $changed = true;  // will have to revisit all ephemeron tables
                }
            }
            $inverse = !$inverse;  // invert direction next time
        } while ($changed);  // repeat until no more changes
    }

    /** lgc.c: traverseLclosure (with its prototype and upvalues) */
    private function traverseLuaClosure(LuaClosure $closure): void
    {
        $upvalueCount = \count($closure->proto->upvalues);
        $this->estimate += self::LCLOSURE_SIZE + self::UPVALUE_POINTER * $upvalueCount;
        $this->markProto($closure->proto);
        for ($index = 0; $index < $upvalueCount; $index++) {
            $upvalue = $closure->getUpval($index);
            $id = spl_object_id($upvalue);
            if (!isset($this->marked[$id])) {  // lgc.c: reallymarkobject for an upvalue marks its value
                $this->marked[$id] = true;
                $this->estimate += self::UPVAL_SIZE;
                $this->markValue($upvalue->v);
            }
        }
    }

    /** lgc.c: traverseproto: prototypes only hold constants, so only their size matters */
    private function markProto(Proto $proto): void
    {
        $id = spl_object_id($proto);
        if (isset($this->marked[$id])) {
            return;
        }
        $this->marked[$id] = true;
        $this->estimate += self::PROTO_SIZE + 4 * \count($proto->code) + self::TVALUE_SIZE * \count($proto->k)
            + 8 * \count($proto->p) + 16 * \count($proto->upvalues) + 16 * \count($proto->locvars)
            + \count($proto->lineinfo) + 8 * \count($proto->abslineinfo);
        $this->markValues($proto->k);
        foreach ($proto->p as $child) {
            $this->markProto($child);
        }
    }

    /**
     * lgc.c: traversethread: the live part of every frame of the thread's
     * CallInfo chain, and the values it keeps outside its stack.
     */
    private function traverseThread(Coroutine $thread): void
    {
        $this->estimate += self::THREAD_SIZE;
        // the top frame: the running thread's is limited by the instruction
        // that triggered the collection; a suspended or dead thread's top
        // frame is kept whole (C: its L->top)
        $limit = $thread === $this->L ? $this->runningTop : -1;
        for ($ci = $thread->ci; $ci !== null; $ci = $ci->previous) {
            $this->estimate += self::CALLINFO_SIZE + self::TVALUE_SIZE * \count($ci->R);
            $this->markValue($ci->func);
            if ($limit < 0) {
                $this->markValues($ci->R);
            } else {
                foreach ($ci->R as $register => $value) {
                    if ($register < $limit) {
                        $this->markValue($value);
                    }
                }
            }
            $this->markValues($ci->varargs);
            $limit = $ci->previous === null ? -1 : self::liveRegisters($ci->previous);
        }
        // frames abandoned by an error whose '__close' variables a pcall is
        // still closing (C keeps them on the stack until precover ends)
        foreach ($thread->pendingCloses as [$abandonedTop, $protectedCi]) {
            for ($ci = $abandonedTop; $ci !== null && $ci !== $protectedCi; $ci = $ci->previous) {
                $this->markValue($ci->func);
                $this->markValues($ci->R);
                $this->markValues($ci->varargs);
            }
        }
        $this->markValue($thread->tailCallFunction);
        $this->markValues($thread->tailCallArguments);
        $this->markValue($thread->errfunc);
        $this->markValue($thread->body);  // a coroutine not started yet
        $this->markValue($thread->errorValue);  // a coroutine dead by an error
    }

    /**
     * How many registers of a Lua frame below the top of its stack are
     * live (-1: all of them). A frame calling a function owns the
     * registers below the called function, which starts the callee's
     * frame in C (lvm.c: OP_CALL, OP_TAILCALL, OP_TFORCALL); a frame
     * running a metamethod or a hook owns all its registers (C sets
     * L->top = ci->top for those).
     */
    private static function liveRegisters(CallInfo $ci): int
    {
        $function = $ci->func;
        if (!($function instanceof LuaClosure) || ($ci->callstatus & Lua::CIST_HOOKED) !== 0) {
            return -1;
        }
        $instruction = $function->proto->code[$ci->savedpc] ?? null;
        if ($instruction === null) {
            return -1;
        }
        return match (OpCodes::GET_OPCODE($instruction)) {
            OpCodes::OP_CALL, OpCodes::OP_TAILCALL => OpCodes::GETARG_A($instruction),
            OpCodes::OP_TFORCALL => OpCodes::GETARG_A($instruction) + 4,
            default => -1,
        };
    }

    /**
     * lgc.c: clearbyvalues: remove the entries whose values were not
     * marked from the tables in $tables, starting at index $from.
     *
     * @param list<LuaTable> $tables
     */
    private function clearByValues(array $tables, int $from): void
    {
        $count = \count($tables);
        for ($i = $from; $i < $count; $i++) {
            $table = $tables[$i];
            $deadKeys = [];
            foreach ($table->arr as $key => $value) {
                if ($this->isCleared($value)) {
                    $deadKeys[] = $key;
                }
            }
            foreach ($deadKeys as $key) {
                unset($table->arr[$key]);  // remove entry
            }
            $deadKeys = [];
            foreach ($table->hash as $key => $value) {
                if ($this->isCleared($value)) {
                    $deadKeys[] = $key;
                }
            }
            foreach ($deadKeys as $key) {
                unset($table->hash[$key]);
            }
            $extra = $table->extra;
            if ($extra === null) {
                continue;
            }
            $deadKeys = [];
            foreach ($extra->otherValues as $key => $value) {
                if ($this->isCleared($value)) {
                    $deadKeys[] = $key;
                }
            }
            foreach ($deadKeys as $key) {
                unset($extra->otherValues[$key], $extra->otherKeys[$key]);
            }
        }
    }

    /**
     * lgc.c: clearbykeys: remove the entries whose keys were not marked.
     * Only keys that are objects can be; they live in 'extra->otherKeys'.
     *
     * @param list<LuaTable> $tables
     */
    private function clearByKeys(array $tables): void
    {
        foreach ($tables as $table) {
            $extra = $table->extra;
            if ($extra === null) {
                continue;
            }
            $deadKeys = [];
            foreach ($extra->otherKeys as $encodedKey => $key) {
                if ($this->isCleared($key)) {  // unmarked key?
                    $deadKeys[] = $encodedKey;
                }
            }
            foreach ($deadKeys as $encodedKey) {
                unset($extra->otherValues[$encodedKey], $extra->otherKeys[$encodedKey]);  // remove entry
            }
        }
    }

    /**
     * lgc.c: separatetobefnz: move the unreachable objects of 'finobj'
     * ($collector null: all of them) to 'tobefnz', to be finalized in
     * reverse order of marking after the ones already pending.
     */
    private static function separateToBeFnz(GlobalState $G, ?self $collector): void
    {
        $separated = [];  // in finalization order
        foreach (array_reverse($G->finobj, true) as $id => $object) {  // last marked first
            if ($collector === null || !isset($collector->marked[$id])) {  // being collected?
                $separated[$id] = $object;
                unset($G->finobj[$id]);  // remove it from 'finobj' list
            }
        }
        if ($separated !== []) {
            // 'tobefnz' pops from its end: new ones go before the pending ones
            $G->tobefnz = array_reverse($separated, true) + $G->tobefnz;
        }
    }

    /** lgc.c: callallpendingfinalizers */
    private static function callAllPendingFinalizers(Coroutine $L): void
    {
        $G = $L->globalState;
        while ($G->tobefnz !== []) {
            self::callOneFinalizer($L);
        }
    }

    /**
     * lgc.c: GCTM (with udata2finalize): call the '__gc' metamethod of the
     * next object to be finalized, in protected mode, without hooks and
     * with the collector stopped. The object is "normal" again: setting a
     * metatable with '__gc' marks it anew. Errors become warnings.
     */
    private static function callOneFinalizer(Coroutine $L): void
    {
        $G = $L->globalState;
        $object = array_pop($G->tobefnz);  // remove it from 'tobefnz' list
        $tm = MetaMethods::getByObject($L, $object, MetaMethods::TM_GC);
        if ($tm === null) {  // is there a finalizer?
            return;
        }
        $oldAllowhook = $L->allowhook;
        $oldgcstp = $G->gcstp;
        $G->gcstp |= self::GCSTPGC;  // avoid GC steps
        $L->allowhook = false;  // stop debug hooks during GC metamethod
        $ci = $L->ci;
        $ci->callstatus |= Lua::CIST_FIN;  // will run a finalizer
        [$status, $error] = Calls::protectedRun($L, static fn (): array => Calls::callNoYield($L, $tm, [$object]));
        $ci->callstatus &= ~Lua::CIST_FIN;  // not running a finalizer anymore
        $L->allowhook = $oldAllowhook;  // restore hooks
        $G->gcstp = $oldgcstp;  // restore state
        if ($status !== Lua::LUA_OK) {  // error while running __gc?
            self::warnError($L, '__gc', $error);
        }
    }

    /** lstate.c: luaE_warnerror: warning "error in <where> (<message>)" */
    private static function warnError(Coroutine $L, string $where, mixed $error): void
    {
        $message = \is_string($error) ? $error : 'error object is not a string';
        BaseLib::warning($L, 'error in ', true);
        BaseLib::warning($L, $where, true);
        BaseLib::warning($L, ' (', true);
        BaseLib::warning($L, $message, true);
        BaseLib::warning($L, ')', false);
    }
}
