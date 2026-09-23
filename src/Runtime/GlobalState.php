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

    /** collectgarbage("stop") state (lgc.c: gcstp); real GC semantics are Phase 4 */
    public bool $gcRunning = true;

    /** 'incremental' or 'generational' */
    public string $gcMode = 'incremental';

    /** @var array<string, mixed> free slots for libraries (e.g. the io library's default files) */
    public array $libraryState = [];

    public function __construct()
    {
        $this->registry = new LuaTable();
        $this->globals = new LuaTable();
        $this->registry->arr[2] = $this->globals;  // LUA_RIDX_GLOBALS
        $this->registry->hash[Lua::LUA_LOADED_TABLE] = new LuaTable();
    }
}
