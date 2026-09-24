<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A Lua thread (C: lua_State in lstate.h). Every running function receives
 * the thread it runs on as $L; the main thread is a Coroutine too. Each
 * thread has its own call stack ($ci chain).
 *
 * A coroutine runs on its own PHP Fiber (see resume/yield). Unlike C, whose
 * lua_yield longjmps out of the C functions in between and resumes them
 * through continuations, the fiber keeps the whole PHP stack of the
 * coroutine, so everything between the resume and the yield (pcall,
 * metamethods, iterators) simply continues. Which yields are allowed is
 * decided as in C, by counting non-yieldable calls ($nny).
 */
final class Coroutine
{
    /**
     * C stack of every coroutine's fiber: 256 KB of address space each
     * (only touched pages cost memory, but address space is what `ulimit
     * -v` limits). Lua code does not use it as it goes deeper: Lua calls,
     * metamethods and library calls are PHP calls, which live on PHP's VM
     * stack (heap), and deep data is freed one link at a time (Teardown).
     * Measured high-water marks inside coroutines: 36 KB for the whole
     * official test suite (also with every file run inside a coroutine),
     * 40 KB when the first load() autoloads and compiles the compiler's
     * classes, 60 KB compiling a 190-operand concatenation (PHP's compiler
     * recurses per operand; about 80 KB for the 255 a binary chunk
     * allows), 84 KB with opcache's JIT compiling traces. PHP keeps the
     * last 48 KB in reserve: its guard throws "Maximum call stack size"
     * (a Lua "C stack overflow", see Calls::cStackOverflowError) or, while
     * compiling, fails fatally, when less remains. 256 KB leaves 208 KB,
     * 2.5 times the worst measured case. See AGENTS.md "Coroutines".
     */
    public const FIBER_STACK_BYTES = 256 * 1024;

    /** what every coroutine's fiber runs (see start) */
    private static ?\Closure $fiberFunction = null;

    /** current CallInfo (top of this thread's call stack) */
    public ?CallInfo $ci = null;

    /** the bottom CallInfo (C: base_ci); lua_getstack stops there */
    public CallInfo $baseCi;

    /** number of nested C calls (C: getCcalls(L)) */
    public int $nCcalls = 0;

    /**
     * the value $nCcalls got at the last resume (C: getCcalls(from) + 1).
     * C restarts the count there; here the PHP frames of a suspended
     * coroutine survive the yield and undo their own increments when they
     * return, so a resume shifts $nCcalls by the change of this base, and
     * counts saved before a yield are kept relative to it (see
     * Calls::protectedRun). Always 0 for the main thread.
     */
    public int $nCcallsBase = 0;

    /**
     * number of non-yieldable calls in the stack (C: upper half of
     * nCcalls); yield is allowed only at 0. The main thread starts at 1.
     */
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

    // ldebug.c / ldo.c hook state (see Hooks; set with Hooks::setHook)
    /** C: lua_Hook, a closure (Coroutine $L, int $event, int $line, CallInfo $ci): void */
    public ?\Closure $hook = null;
    public int $hookmask = 0;
    public int $basehookcount = 0;
    public int $hookcount = 0;
    public bool $allowhook = true;
    public int $oldpc = 0;

    /**
     * While a call or return hook runs on a CallInfo of this thread with
     * CIST_TRAN set: the values transferred are that frame's slots
     * ftransfer .. ftransfer + ntransfer - 1, as numbered by debug.getlocal
     * (C: ci->u2.transferinfo; kept here, as hooks do not nest, so that
     * every CallInfo is two properties smaller)
     */
    public int $ftransfer = 0;
    public int $ntransfer = 0;

    /**
     * true while a line or count hook is set (C: the 'trap' of every Lua
     * frame); emitted functions bind it by reference and test it before
     * each instruction
     */
    public bool $trap = false;

    /**
     * the PHP Fiber of a coroutine that has started and not finished (null
     * for the main thread, before the first resume and once it is dead)
     */
    public ?\Fiber $fiber = null;

    /**
     * the function a new coroutine will run (C: the function moved onto its
     * stack by coroutine.create); null once it starts (its fiber owns it
     * then) and for the main thread
     */
    public mixed $body = null;

    /**
     * the error value of a coroutine that died with an error (C: the copy
     * of the error object lua_resume leaves on its stack), until
     * closeThread() uses it
     */
    public mixed $errorValue = null;

    /**
     * @var list<array{CallInfo, CallInfo}> [top frame, protected call's
     * frame] of each chain of frames abandoned by an error whose '__close'
     * methods a yieldable pcall is running (Calls::closeProtected). C still
     * has those frames on the stack, between the protected call and the
     * running '__close' method, so closeThread puts them back there.
     */
    public array $pendingCloses = [];

    public function __construct(
        public readonly GlobalState $globalState,
    ) {
        $this->baseCi = CallInfo::push($this, null, Lua::CIST_C, 1 + Lua::LUA_MINSTACK, 0);
    }

    /**
     * Freeing a dropped coroutine must not recurse (see Teardown): its
     * CallInfo chain (kept by a coroutine dead by an error, or suspended
     * and collected with its fiber) is as deep as its Lua stack was, so it
     * is unlinked one frame at a time; the values it keeps outside its
     * stack go to Teardown.
     */
    public function __destruct()
    {
        if ($this->ci !== $this->baseCi) {
            Calls::unlinkAbandonedFrames($this->ci, $this->baseCi);
        }
        foreach ($this->pendingCloses as [$abandonedTop, $protectedCi]) {
            Calls::unlinkAbandonedFrames($abandonedTop, $protectedCi);
        }
        if ($this->body === null && $this->errorValue === null && $this->errfunc === null
            && $this->tailCallFunction === null && $this->tailCallArguments === []) {
            return;
        }
        if (Teardown::$releasing) {
            Teardown::$pending[] = $this->body;
            Teardown::$pending[] = $this->errorValue;
            Teardown::$pending[] = $this->errfunc;
            Teardown::$pending[] = $this->tailCallFunction;
            Teardown::$pending[] = $this->tailCallArguments;
            return;
        }
        Teardown::$releasing = true;
        $this->body = $this->errorValue = $this->errfunc = $this->tailCallFunction = null;
        $this->tailCallArguments = [];
        Teardown::release();
    }

    /** lstate.c: lua_newstate: a new global state and its main thread */
    public static function newState(): self
    {
        $globalState = new GlobalState();
        $mainThread = new self($globalState);
        $mainThread->nny++;  // incnny: main thread is always non yieldable
        $globalState->mainThread = $mainThread;
        return $mainThread;
    }

    /**
     * lstate.c: lua_newthread (with the creator's hook), plus the function
     * the new coroutine will run.
     */
    public static function newThread(Coroutine $L, mixed $body): self
    {
        $L1 = new self($L->globalState);
        Hooks::setHook($L1, $L->hook, $L->hookmask, $L->basehookcount);
        $L1->body = $body;
        return $L1;
    }

    /**
     * ldo.c: lua_resume: start or continue this coroutine with $arguments,
     * called from thread $from. Returns [Lua::LUA_YIELD, the yielded
     * values], [Lua::LUA_OK, the body's results] or [error status, error
     * value]. An error in the call to resume itself ("cannot resume dead
     * coroutine", ...) leaves the coroutine as it was.
     *
     * @param list<mixed> $arguments
     * @return array{int, mixed}
     */
    public function resume(Coroutine $from, array $arguments): array
    {
        if ($this->status === Lua::LUA_OK) {  // may be starting a coroutine
            if ($this->ci !== $this->baseCi) {  // not in base level?
                return [Lua::LUA_ERRRUN, 'cannot resume non-suspended coroutine'];  // resume_error
            }
            if ($this->body === null) {  // no function?
                return [Lua::LUA_ERRRUN, 'cannot resume dead coroutine'];
            }
        } elseif ($this->status !== Lua::LUA_YIELD) {  // ended with errors?
            return [Lua::LUA_ERRRUN, 'cannot resume dead coroutine'];
        }
        $base = $from->nCcalls;  // getCcalls(from): C calls only, so this thread is yieldable
        if ($base >= Lua::LUAI_MAXCCALLS) {
            return [Lua::LUA_ERRRUN, 'C stack overflow'];
        }
        $base++;
        $this->nCcalls += $base - $this->nCcallsBase;
        $this->nCcallsBase = $base;
        $this->status = Lua::LUA_OK;  // mark that it is running (again)
        try {
            if ($this->fiber === null) {  // starting a coroutine?
                $values = $this->start($arguments);
            } else {  // resuming from previous yield: yield() returns the arguments
                $values = $this->fiber->resume($arguments);
            }
        } catch (LuaError|\Error $error) {  // unrecoverable error
            if ($error instanceof \Error) {
                $error = Calls::cStackOverflowError($this, $error);
            }
            // the thread is dead; like C, its CallInfo chain stays as it was
            // when the error was raised (for debug.traceback) until closeThread()
            $this->status = $error->status;
            $this->errorValue = $error->value;
            $this->fiber = null;
            $this->nCcalls = $this->nCcallsBase;  // luaD_rawrunprotected restores it
            $this->nny = 0;
            return [$error->status, $error->value];
        }
        if ($this->fiber->isTerminated()) {  // normal end: the body returned
            $values = $this->fiber->getReturn();
            $this->fiber = null;
            return [Lua::LUA_OK, $values];
        }
        return [Lua::LUA_YIELD, $values];
    }

    /**
     * ldo.c: resume for a coroutine's first run: call its body on a new
     * fiber, as ccall(L, func, LUA_MULTRET, 0) (not a C call of its own).
     * Returns what the fiber suspends with (the yielded values).
     *
     * @param list<mixed> $arguments
     */
    private function start(array $arguments): mixed
    {
        $body = $this->body;
        $this->body = null;
        // one PHP closure for all fibers: PHP makes a new closure object
        // (about 400 bytes, kept by the fiber) each time it evaluates one
        $this->fiber = new \Fiber(self::$fiberFunction ??= static function (Coroutine $L, mixed $body, array $arguments): array {
            if ($L->nCcalls >= Lua::LUAI_MAXCCALLS) {  // ccall checks even when it adds nothing
                Calls::checkCStack($L);
            }
            if ($body instanceof LuaClosure) {
                return ($body->code)($L, $body, $arguments) ?? Calls::finishTailCall($L);
            }
            return Calls::callNonLua($L, $body, $arguments);
        });
        // read when a fiber starts; every fiber the runtime starts sets its own size
        ini_set('fiber.stack_size', (string) self::FIBER_STACK_BYTES);
        return $this->fiber->start($this, $body, $arguments);
    }

    /**
     * ldo.c: lua_yieldk without a continuation: suspend this thread (the
     * running coroutine) until the next resume, whose arguments are
     * returned; $values are what that resume returned to its caller.
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public function yield(array $values): array
    {
        if ($this->nny > 0) {  // !yieldable(L)
            if ($this !== $this->globalState->mainThread) {
                DebugInfo::runError($this, 'attempt to yield across a C-call boundary');
            }
            DebugInfo::runError($this, 'attempt to yield from outside a coroutine');
        }
        if ($this->fiber === null || \Fiber::getCurrent() !== $this->fiber) {
            // a yieldable thread only runs on its own fiber (closeThread's
            // '__close' calls run elsewhere, but are not yieldable)
            throw new \LogicException('yield outside the fiber of the yielding coroutine');
        }
        $this->status = Lua::LUA_YIELD;
        // the values move to the resumer (lcorolib.c: auxresume's lua_xmove),
        // leaving the yielding frame without slots (debug.getlocal(co, 0, n))
        $this->ci->R = [];
        return \Fiber::suspend($values);
    }

    /**
     * lstate.c: lua_closethread + luaE_resetthread, called from thread
     * $from on this suspended or dead coroutine: empty its stack and close
     * its pending to-be-closed variables (with the error it died with, if
     * any). Returns [Lua::LUA_OK, null] or [error status, error value]: the
     * original error or one raised by a '__close' method.
     *
     * The PHP frames of a suspended coroutine are dropped with its fiber:
     * PHP unwinds a destroyed suspended fiber without running any catch
     * block, so no Lua code sees it (see AGENTS.md "Coroutines").
     *
     * @return array{int, mixed}
     */
    public function closeThread(Coroutine $from): array
    {
        $this->nCcalls = $from->nCcalls;  // getCcalls(from)
        $this->nCcallsBase = $this->nCcalls;
        $this->nny = 0;
        $abandonedCi = $this->ci;
        for ($i = \count($this->pendingCloses) - 1; $i >= 0; $i--) {  // innermost first
            [$abandonedTop, $protectedCi] = $this->pendingCloses[$i];
            $ci = $abandonedCi;
            while ($ci !== null && $ci->previous !== $protectedCi) {  // the running '__close' method's bottom frame
                $ci = $ci->previous;
            }
            if ($ci !== null) {
                $ci->previous = $abandonedTop;
            }
        }
        $this->pendingCloses = [];
        $this->ci = $this->baseCi;  // unwind CallInfo list
        $status = $this->status === Lua::LUA_YIELD ? Lua::LUA_OK : $this->status;
        $errorValue = $status === Lua::LUA_OK ? null : $this->errorValue;
        $this->status = Lua::LUA_OK;  // so it can run __close metamethods
        $this->errfunc = null;  // stack unwind can "throw away" the error function
        $this->body = null;
        $this->errorValue = null;
        $this->fiber = null;
        [$status, $errorValue] = Calls::closeProtected($this, $abandonedCi, $this->baseCi, $status, $errorValue);
        Calls::unlinkAbandonedFrames($abandonedCi, $this->baseCi);
        Calls::shrinkStack($this);
        return [$status, $status === Lua::LUA_OK ? null : $errorValue];
    }
}
