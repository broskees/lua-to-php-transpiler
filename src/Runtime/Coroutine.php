<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A Lua thread (C: lua_State in lstate.h). Every running function receives
 * the thread it runs on as $L; the main thread is a Coroutine too. Each
 * thread has its own call stack ($ci chain). The coroutine library (Phase
 * 2) runs non-main threads on a PHP Fiber.
 */
final class Coroutine
{
    /** current CallInfo (top of this thread's call stack) */
    public ?CallInfo $ci = null;

    /** the bottom CallInfo (C: base_ci); lua_getstack stops there */
    public CallInfo $baseCi;

    /** number of nested C calls (C: getCcalls(L)) */
    public int $nCcalls = 0;

    /** number of non-yieldable calls in the stack (C: upper half of nCcalls) */
    public int $nny = 0;

    /** stack size limit: LUAI_MAXSTACK, or ERRORSTACKSIZE while handling a stack overflow */
    public int $stackLimit = Lua::LUAI_MAXSTACK;

    /** frame memory limit: Calls::MAX_FRAME_BYTES, plus some extra while handling a stack overflow */
    public int $frameBytesLimit = Calls::MAX_FRAME_BYTES;

    /**
     * the current message handler (C: L->errfunc): set by a protected call
     * with a handler, cleared by one without; load's reader runs under it
     */
    public mixed $errfunc = null;

    /** pending tail call (see Calls::tailCall / Calls::finishTailCall) */
    public ?LuaClosure $tailCallFunction = null;

    /** @var list<mixed> */
    public array $tailCallArguments = [];

    /** Lua::LUA_OK, LUA_YIELD or an error status (C: L->status) */
    public int $status = Lua::LUA_OK;

    // ldebug.c / ldo.c hook state (debug.sethook, Phase 4)
    public mixed $hook = null;
    public int $hookmask = 0;
    public int $basehookcount = 0;
    public int $hookcount = 0;
    public bool $allowhook = true;
    public int $oldpc = 0;

    /** the PHP Fiber running this coroutine (null for the main thread) */
    public ?\Fiber $fiber = null;

    public function __construct(
        public readonly GlobalState $globalState,
    ) {
        $this->baseCi = CallInfo::push($this, null, Lua::CIST_C, 1 + Lua::LUA_MINSTACK, 0);
    }

    /** lstate.c: lua_newstate: a new global state and its main thread */
    public static function newState(): self
    {
        $globalState = new GlobalState();
        $mainThread = new self($globalState);
        $globalState->mainThread = $mainThread;
        return $mainThread;
    }
}
