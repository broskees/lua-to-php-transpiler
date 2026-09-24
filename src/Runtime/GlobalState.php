<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * State shared by all threads (C: global_State in lstate.h).
 */
final class GlobalState
{
    /** the registry (C: l_registry); LUA_RIDX_GLOBALS is $globals */
    public LuaTable $registry;

    /** the global table (_G, the default _ENV) */
    public LuaTable $globals;

    /** metatables for basic types, by Lua::LUA_T* type (C: mt[LUA_NUMTYPES]); tables and userdata have their own */
    public array $typeMetatables = [];

    public Coroutine $mainThread;

    /** warnings (lauxlib.c warnf*): false = off, true = on */
    public bool $warningsOn = false;

    /** a warning message is being continued (lauxlib.c: warnfcont) */
    public bool $warningContinues = false;

    // Garbage collector state (lstate.h; see Gc\Collector)

    /** why the collector is stopped (C: gcstp; Collector::GCSTP* bits), 0 = running */
    public int $gcstp = 0;

    /** Collector::KGC_INC or KGC_GEN (C: gckind) */
    public int $gckind = Gc\Collector::KGC_INC;

    /**
     * bytes charged by allocations beyond the current budget (C: GCdebt);
     * emitted code runs a collection step when it becomes positive
     */
    public int $gcDebt = 0;

    /** C: totalbytes; the charged memory in use is gcTotalBytes + gcDebt */
    public int $gcTotalBytes = 0;

    /** estimated bytes in use after the last collection (C: GCestimate) */
    public int $gcEstimate = 0;

    /** memory_get_usage() when the last collection ended */
    public int $gcRealBase = 0;

    // collector parameters, stored as C stores them (lgc.h: setgcparam keeps value / 4 in a byte)
    public int $gcpause = 50;      // LUAI_GCPAUSE 200
    public int $gcstepmul = 25;    // LUAI_GCMUL 100
    public int $gcstepsize = 13;   // LUAI_GCSTEPSIZE: log2 of 8 KB
    public int $genminormul = 20;  // LUAI_GENMINORMUL
    public int $genmajormul = 25;  // LUAI_GENMAJORMUL 100

    /**
     * objects marked for finalization (C: 'finobj'), by spl_object_id, in
     * marking order; the collector holds them until they are finalized
     *
     * @var array<int, LuaTable|Userdata>
     */
    public array $finobj = [];

    /**
     * unreachable objects whose finalizers are pending (C: 'tobefnz'), by
     * spl_object_id; the next one to finalize is the last
     *
     * @var array<int, LuaTable|Userdata>
     */
    public array $tobefnz = [];

    /** @var array<string, mixed> free slots for libraries (e.g. the io library's default files) */
    public array $libraryState = [];

    public function __construct()
    {
        $this->registry = new LuaTable();
        $this->globals = new LuaTable();
        $this->registry->arr[2] = $this->globals;  // LUA_RIDX_GLOBALS
        $this->registry->hash[Lua::LUA_LOADED_TABLE] = new LuaTable();
        $this->gcRealBase = memory_get_usage();
    }
}
