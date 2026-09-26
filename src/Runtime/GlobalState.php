<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * State shared by all threads (C: global_State in lstate.h).
 *
 * @internal
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

    /**
     * Lua files were transpiled ahead of time (scripts bin/lua2php
     * generates): loading a file includes its precompiled PHP and never
     * compiles (ChunkLoader::loadFile)
     */
    public bool $filesArePrecompiled = false;

    /** @var array<string, mixed> free slots for libraries (e.g. the io library's default files) */
    public array $libraryState = [];

    /** frame budget of each thread (Calls::frameBudget, for the memory left when the state was created) */
    public int $frameBudget = Calls::MAX_FRAME_BYTES;

    /** @var list<string> long strings whose address '%p' gave out (see StringFormat::stringAddress) */
    public array $addressedLongStrings = [];

    // Embedding (see AGENTS.md "Embedding conventions")

    /** Lua's standard output (C: stdout): print, io.write, io.stdout */
    public OutputSink $output;

    /** Lua's standard error (C: stderr): warn, io.stderr, debug.debug */
    public OutputSink $errorOutput;

    /** the limits of the state (null: none, and nothing is charged); set with setBudget */
    public private(set) ?Budget $budget = null;

    /**
     * The steps code compiled with step counting may still run before it
     * calls Budget::stepsUsedUp (its functions bind it by reference): the
     * budget's allowance (Budget::$stepsLeft is a reference to it), or
     * without a budget a number no run reaches. Untyped: a reference to a
     * typed property costs a type check at every decrement.
     *
     * @var int
     */
    public $stepsLeft = PHP_INT_MAX;

    /** Lua call levels a thread may have in code compiled with step counting (Budget::$callDepth) */
    public int $callDepthLimit = PHP_INT_MAX;

    /** load() and the like compile with step counting (a state for embedded code) */
    public bool $countSteps = false;

    public function __construct()
    {
        $this->registry = new LuaTable();
        $this->globals = new LuaTable();
        $this->registry->arr[2] = $this->globals;  // LUA_RIDX_GLOBALS
        $this->registry->hash[Lua::LUA_LOADED_TABLE] = new LuaTable();
        $this->gcRealBase = memory_get_usage();
        $this->frameBudget = Calls::frameBudget();
        $this->output = new StandardOutput();
        $this->errorOutput = new StandardError();
    }

    /**
     * Sets the limits of the state (null: none) and starts a run of
     * $budget (Budget::start). A budget belongs to one state. It may be
     * replaced between runs: code counts steps with the state's counter,
     * which the budget in place charges.
     */
    public function setBudget(?Budget $budget): void
    {
        $this->budget = $budget;
        $this->callDepthLimit = $budget?->callDepth ?? PHP_INT_MAX;
        if ($budget === null) {
            $this->stepsLeft = PHP_INT_MAX;
            return;
        }
        $budget->stepsLeft = &$this->stepsLeft;  // (a reference: frames running now keep charging the same counter)
        $budget->start();
    }

    /** lauxlib.h: lua_writestring: $bytes to standard output, charged to the budget first */
    public function writeOutput(string $bytes): void
    {
        $this->budget?->chargeOutput(\strlen($bytes));
        $this->output->write($bytes);
    }

    /** lauxlib.h: lua_writestringerror: $bytes to standard error, charged to the budget first */
    public function writeErrorOutput(string $bytes): void
    {
        $this->budget?->chargeOutput(\strlen($bytes));
        $this->errorOutput->write($bytes);
    }

    /** fflush(stdout): only the process's standard output has a buffer to flush */
    public function flushOutput(): void
    {
        if ($this->output instanceof StandardOutput) {
            $this->output->flush();
        }
    }
}
