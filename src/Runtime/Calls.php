<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Port of ldo.c: calls, tail calls, protected calls, stack limits.
 *
 * Calling convention (see AGENTS.md "Runtime conventions"): a Lua
 * function is ($closure->code)($L, $closure, $arguments[, $callstatus]) and
 * returns its results as a list, or null after setting
 * $L->tailCallFunction/tailCallArguments when it ends in a tail call to
 * another Lua function; whoever called it finishes that with
 * finishTailCall(), so tail calls do not grow the PHP stack. Native
 * functions are called through callNative(), which gives them a CallInfo.
 *
 * @internal
 */
final class Calls
{
    /**
     * Budget of PHP memory for the frames of one thread. C Lua only limits
     * stack slots (LUAI_MAXSTACK), which allows about a million tiny Lua
     * frames; each of ours is a PHP frame (eval'd code is not optimized, so
     * it grows with the function's code: FunctionEmitter::frameBytes), a
     * CallInfo and a register array. Frames charge an estimate of that
     * against this budget; exceeding it, like exceeding LUAI_MAXSTACK, is
     * Lua's "stack overflow" error. Less memory_limit, less budget: see
     * frameBudget.
     */
    public const MAX_FRAME_BYTES = 256 * 1024 * 1024;

    /** extra budget while handling a stack overflow (C: the 200 slots of ERRORSTACKSIZE) */
    public const ERROR_EXTRA_FRAME_BYTES = 8 * 1024 * 1024;

    /**
     * The frame budget of each thread of a state created now (at most
     * MAX_FRAME_BYTES): running out of PHP memory is a fatal error no
     * pcall sees, and frames really take up to about twice their estimate
     * (uncached or eval'd code, FunctionEmitter::frameBytes), so the
     * budget is half the memory left below memory_limit once a quarter of
     * it (at least 16 MB) is kept for everything else.
     */
    public static function frameBudget(): int
    {
        $available = MemoryLimit::available();
        return max(1 << 20, min(self::MAX_FRAME_BYTES, intdiv($available - max(16 << 20, intdiv($available, 4)), 2)));
    }

    /** extra budget while handling a stack overflow with a frame budget of $frameBudget */
    public static function errorExtraFrameBytes(int $frameBudget): int
    {
        return min(self::ERROR_EXTRA_FRAME_BYTES, max(1 << 20, intdiv($frameBudget, 32)));
    }

    /** estimated PHP memory of a native function's frame */
    public const NATIVE_FRAME_BYTES = 1024;

    /**
     * ldo.c: luaD_growstack failing: "stack overflow", raised with the
     * caller as the running function. A second overflow while the thread
     * already uses the extra space reserved for handling the first one is
     * an error in error handling (C: luaD_errerr).
     */
    public static function stackOverflow(Coroutine $L): never
    {
        if ($L->stackLimit > Lua::LUAI_MAXSTACK) {
            self::errorInErrorHandling();
        }
        // add extra size to be able to handle the error message
        $L->stackLimit = Lua::ERRORSTACKSIZE;
        $L->frameBytesLimit = $L->globalState->frameBudget + self::errorExtraFrameBytes($L->globalState->frameBudget);
        DebugInfo::runError($L, 'stack overflow');
    }

    /**
     * The Lua call levels a thread may add beyond GlobalState::$callDepthLimit
     * while it handles a stack overflow (as C's stack grows by the 200
     * slots of ERRORSTACKSIZE - LUAI_MAXSTACK)
     */
    public const ERROR_EXTRA_DEPTH = 50;

    /**
     * A call to a function compiled with step counting would make the
     * thread $depth Lua levels deep, beyond GlobalState::$callDepthLimit:
     * Lua's "stack overflow", as when the stack is full, with
     * ERROR_EXTRA_DEPTH more levels while the thread handles it (then
     * "error in error handling").
     */
    public static function callDepthOverflow(Coroutine $L, int $depth): void
    {
        if ($L->stackLimit > Lua::LUAI_MAXSTACK) {  // handling a stack overflow already
            if ($depth <= $L->globalState->callDepthLimit + self::ERROR_EXTRA_DEPTH) {
                return;
            }
            self::errorInErrorHandling();
        }
        self::stackOverflow($L);
    }

    /**
     * The Lua call levels at and below $ci (CallInfo::$depth): its own for
     * a frame of code compiled with step counting, those of the Lua frame
     * below for a native one (a native frame counts no level and stores
     * none: native calls stay as cheap as they were), 0 at a thread's base.
     */
    public static function luaDepth(CallInfo $ci): int
    {
        while (($ci->callstatus & Lua::CIST_C) !== 0) {
            $ci = $ci->previous;
            if ($ci === null) {
                return 0;  // a thread's base
            }
        }
        return $ci->depth;
    }

    /** ldo.c: luaD_errerr */
    public static function errorInErrorHandling(): never
    {
        throw new LuaError('error in error handling', Lua::LUA_ERRERR);
    }

    /**
     * lapi.c: lua_checkstack: can the running function use $n more stack
     * slots?
     */
    public static function checkStack(Coroutine $L, int $n): bool
    {
        return $n >= 0 && $n < Lua::LUAI_MAXSTACK && $L->ci->top + $n <= $L->stackLimit;
    }

    // lstate.c: luaE_checkcstack
    public static function checkCStack(Coroutine $L): void
    {
        if ($L->nCcalls === Lua::LUAI_MAXCCALLS) {  // possible C stack overflow?
            DebugInfo::runError($L, 'C stack overflow');
        }
        if ($L->nCcalls >= intdiv(Lua::LUAI_MAXCCALLS, 10) * 11) {
            self::errorInErrorHandling();  // error while handling stack error
        }
    }

    /**
     * PHP's guard against exhausting the C stack as a Lua error. PHP throws
     * an \Error ("Maximum call stack size of N bytes reached") when PHP
     * code recursing through internal functions (or PHP's compiler) nears
     * the end of the main thread's or a fiber's C stack; C Lua raises "C
     * stack overflow" before its C stack can overflow (lstate.c:
     * luaE_checkcstack, a luaG_runerror with the position of the running
     * Lua function). Protected calls and resume catch the \Error and use
     * this; any other \Error is a bug and is rethrown.
     */
    public static function cStackOverflowError(Coroutine $L, \Error $error): LuaError
    {
        if ($error::class !== \Error::class || !str_starts_with($error->getMessage(), 'Maximum call stack size of ')) {
            throw $error;
        }
        $message = 'C stack overflow';
        $ci = $L->ci;
        if ($ci->func instanceof LuaClosure) {  // if Lua function, add source:line information
            $message = DebugInfo::addInfo($message, $ci->func->proto->source, DebugInfo::currentLine($ci));
        }
        return new LuaError($message);
    }

    /**
     * Call any value from PHP code (library functions, metamethods) and
     * return all its results. Counts as a C call (LUAI_MAXCCALLS).
     *
     * The callee may yield only when the running function is Lua code (the
     * VM calling a metamethod or a 'for' iterator: ltm.c luaT_callTMres,
     * lvm.c OP_TFORCALL). From a native function this is lapi.c's lua_call,
     * which has no continuation, so the callee cannot yield
     * (luaD_callnoyield): e.g. a yield inside a table.sort comparator is
     * "attempt to yield across a C-call boundary". Natives that C gives a
     * continuation (pcall, xpcall, dofile, pairs) use callk().
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    public static function call(Coroutine $L, mixed $function, array $arguments): array
    {
        if (($L->ci->callstatus & (Lua::CIST_C | Lua::CIST_HOOKED)) === 0) {  // isLuacode(L->ci)
            // callk($L, $function, $arguments), inline: a PHP call less per
            // metamethod or 'for' iterator the VM calls
            if (++$L->nCcalls >= Lua::LUAI_MAXCCALLS) {
                self::checkCStack($L);
            }
            if ($function instanceof LuaClosure) {
                $results = ($function->code)($L, $function, $arguments) ?? self::finishTailCall($L);
            } else {
                $results = self::callNonLua($L, $function, $arguments);
            }
            $L->nCcalls--;
            return $results;
        }
        $L->nny++;
        $results = self::callk($L, $function, $arguments);
        $L->nny--;
        return $results;
    }

    /**
     * ldo.c: luaD_call (lapi.c: lua_callk with a continuation): call any
     * value; the callee may yield if the thread is yieldable. Counts as a
     * C call (LUAI_MAXCCALLS).
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    public static function callk(Coroutine $L, mixed $function, array $arguments): array
    {
        if (++$L->nCcalls >= Lua::LUAI_MAXCCALLS) {
            self::checkCStack($L);
        }
        if ($function instanceof LuaClosure) {
            $results = ($function->code)($L, $function, $arguments) ?? self::finishTailCall($L);
        } else {
            $results = self::callNonLua($L, $function, $arguments);
        }
        $L->nCcalls--;
        return $results;
    }

    /**
     * ldo.c: luaD_callnoyield: like call(), but the callee cannot yield.
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    public static function callNoYield(Coroutine $L, mixed $function, array $arguments): array
    {
        $L->nny++;
        $results = self::callk($L, $function, $arguments);
        $L->nny--;
        return $results;
    }

    /**
     * Call a native function or a value with a '__call' metamethod (ldo.c:
     * luaD_precall for non-Lua functions, tryfuncTM).
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    public static function callNonLua(Coroutine $L, mixed $function, array $arguments): array
    {
        if ($function instanceof NativeFunction) {  // callNative($L, $function, $arguments, 0), inline: a PHP call less per native call
            $ci = new CallInfo();
            $ci->func = $function;
            $ci->callstatus = Lua::CIST_C;
            $ci->previous = $previous = $L->ci;
            $ci->top = $previous->top + \count($arguments) + 1 + Lua::LUA_MINSTACK;
            $ci->frameBytes = $previous->frameBytes + self::NATIVE_FRAME_BYTES;
            if ($ci->top > $L->stackLimit || $ci->frameBytes > $L->frameBytesLimit) {
                self::stackOverflow($L);
            }
            $L->ci = $ci;
            $ci->R = $arguments;
            if ($L->hookmask & Lua::LUA_MASKCALL) {
                Hooks::hook($L, Lua::LUA_HOOKCALL, -1, 1, \count($arguments), \count($arguments));
            }
            $results = ($function->function)($L, $arguments);
            if ($L->hookmask !== 0) {  // ldo.c: luaD_poscall
                self::nativeReturnHook($L, $ci, $results);
            }
            $L->ci = $ci->previous;
            return $results;
        }
        while (!($function instanceof NativeFunction)) {
            if ($function instanceof LuaClosure) {
                return ($function->code)($L, $function, $arguments) ?? self::finishTailCall($L);
            }
            // not a function: try '__call' metamethod (ldo.c: tryfuncTM)
            $tm = MetaMethods::getByObject($L, $function, MetaMethods::TM_CALL);
            if ($tm === null) {
                DebugInfo::callError($L, $function);  // nothing to call
            }
            array_unshift($arguments, $function);  // the called object is the first argument
            $function = $tm;  // metamethod is the new function to be called
        }
        return self::callNative($L, $function, $arguments, 0);
    }

    /**
     * ldo.c: precallC: run a native function in a new CallInfo. callNonLua
     * does the same inline for the usual call of a native: keep them alike.
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    public static function callNative(Coroutine $L, NativeFunction $function, array $arguments, int $callstatus): array
    {
        // CallInfo::push, inline (a PHP call less per native call; $L->ci is never null here)
        $ci = new CallInfo();
        $ci->func = $function;
        $ci->callstatus = $callstatus | Lua::CIST_C;
        $ci->previous = $previous = $L->ci;
        $ci->top = $previous->top + \count($arguments) + 1 + Lua::LUA_MINSTACK;
        $ci->frameBytes = $previous->frameBytes + self::NATIVE_FRAME_BYTES;
        if ($ci->top > $L->stackLimit || $ci->frameBytes > $L->frameBytesLimit) {
            self::stackOverflow($L);
        }
        $L->ci = $ci;
        $ci->R = $arguments;
        if ($L->hookmask & Lua::LUA_MASKCALL) {
            Hooks::hook($L, Lua::LUA_HOOKCALL, -1, 1, \count($arguments), \count($arguments));
        }
        $results = ($function->function)($L, $arguments);
        if ($L->hookmask !== 0) {  // ldo.c: luaD_poscall
            self::nativeReturnHook($L, $ci, $results);
        }
        $L->ci = $ci->previous;
        return $results;
    }

    /**
     * ldo.c: rethook for a native function. In C its results are the top
     * slots of its stack; here they are appended to its arguments, so the
     * hook can read them with debug.getlocal (the slot numbers can differ
     * from C's, which depend on what the C function left on its stack).
     *
     * @param list<mixed> $results
     */
    private static function nativeReturnHook(Coroutine $L, CallInfo $ci, array $results): void
    {
        $firstResult = \count($ci->R) + 1;
        foreach ($results as $result) {
            $ci->R[] = $result;
        }
        Hooks::retHook($L, $ci, $firstResult, \count($results));
    }

    /**
     * lvm.c: OP_TAILCALL (with ldo.c: luaD_pretailcall). $ci is the frame
     * of the Lua function doing 'return f(args)'. A Lua callee replaces that
     * frame: $ci is popped and the call is left pending for our caller
     * (returns null). A native callee runs on top of $ci, as in C, and its
     * results are the frame's results. $functionRegister is the called
     * function's register (where C moves a native callee's results).
     *
     * @param list<mixed> $arguments
     * @return list<mixed>|null
     */
    public static function tailCall(Coroutine $L, CallInfo $ci, mixed $function, array $arguments, int $functionRegister = 0): ?array
    {
        while (!($function instanceof LuaClosure)) {
            if ($function instanceof NativeFunction) {
                $results = self::callNative($L, $function, $arguments, 0);
                if ($L->hookmask !== 0) {  // luaD_poscall of the caller: its return hook
                    foreach ($results as $i => $result) {
                        $ci->R[$functionRegister + $i] = $result;
                    }
                    Hooks::retHook($L, $ci, $functionRegister + 1, \count($results));
                }
                $L->ci = $ci->previous;  // caller returns after the tail call
                return $results;
            }
            $tm = MetaMethods::getByObject($L, $function, MetaMethods::TM_CALL);
            if ($tm === null) {
                DebugInfo::callError($L, $function);
            }
            array_unshift($arguments, $function);
            $function = $tm;
        }
        $L->ci = $ci->previous;
        $L->tailCallFunction = $function;
        $L->tailCallArguments = $arguments;
        return null;
    }

    /**
     * Run the pending tail call(s) left by a Lua function that returned
     * null, and return the final results.
     *
     * @return list<mixed>
     */
    public static function finishTailCall(Coroutine $L): array
    {
        do {
            $function = $L->tailCallFunction;
            $arguments = $L->tailCallArguments;
            $L->tailCallFunction = null;
            $L->tailCallArguments = [];
            $results = ($function->code)($L, $function, $arguments, Lua::CIST_TAIL);
        } while ($results === null);
        return $results;
    }

    /**
     * ltm.c: luaT_adjustvarargs (OP_VARARGPREP): the arguments after the
     * fixed parameters become the frame's varargs; missing parameters are
     * nil.
     *
     * @param array<int, mixed> $R
     */
    public static function adjustVarargs(CallInfo $ci, array &$R, int $numparams): void
    {
        $argumentCount = \count($R);
        if ($argumentCount > $numparams) {
            $ci->varargs = \array_slice($R, $numparams);
        } else {
            for ($i = $argumentCount; $i < $numparams; $i++) {
                $R[$i] = null;
            }
        }
        // the function and its fixed parameters are copied above the varargs
        $ci->top += $argumentCount + 1;
    }

    /**
     * ldo.c: luaD_pcall around luaD_call (lapi.c: lua_pcall). Calls
     * $function in protected mode; see protectedRun().
     *
     * Returns [Lua::LUA_OK, results] or [error status, error value].
     *
     * @param list<mixed> $arguments
     * @return array{int, mixed}
     */
    public static function protectedCall(Coroutine $L, mixed $function, array $arguments, mixed $messageHandler = null): array
    {
        return self::protectedRun($L, static fn (): array => self::call($L, $function, $arguments), $messageHandler);
    }

    /**
     * lapi.c: lua_pcallk with a continuation (pcall, xpcall): like
     * protectedCall(), but the callee may yield (see callk()).
     *
     * @param list<mixed> $arguments
     * @return array{int, mixed}
     */
    public static function protectedCallk(Coroutine $L, mixed $function, array $arguments, mixed $messageHandler = null): array
    {
        $yieldable = $L->nny === 0;  // lapi.c: k != NULL && yieldable(L)
        return self::protectedRun($L, static fn (): array => self::callk($L, $function, $arguments), $messageHandler, $yieldable);
    }

    /**
     * ldo.c: luaD_pcall: run $body (PHP code, in the current frame) in
     * protected mode with $messageHandler as the current message handler
     * (C: L->errfunc). On error, the handler (if any) runs first, on top of
     * the stack as it was when the error was raised (C: luaG_errormsg);
     * then the call stack is unwound to the current frame, closing
     * upvalues and to-be-closed variables of the abandoned frames.
     *
     * $yieldable: a pcall with a continuation in a yieldable thread. C
     * does not protect such a call itself: lua_resume catches the error
     * and finishes the pcall (ldo.c: precover -> finishpcallk), where the
     * '__close' methods may yield.
     *
     * Returns [Lua::LUA_OK, $body's result] or [error status, error value].
     *
     * @return array{int, mixed}
     */
    public static function protectedRun(Coroutine $L, \Closure $body, mixed $messageHandler = null, bool $yieldable = false): array
    {
        $oldCi = $L->ci;
        $oldnCcalls = $L->nCcalls - $L->nCcallsBase;  // relative: a coroutine may yield and be resumed from elsewhere in between
        $oldnny = $L->nny;
        $oldAllowhook = $L->allowhook;
        $oldErrfunc = $L->errfunc;
        $L->errfunc = $messageHandler;
        try {
            $result = $body();
            $L->errfunc = $oldErrfunc;
            return [Lua::LUA_OK, $result];
        } catch (LuaError|\Error $error) {
            if ($error instanceof \Error) {
                $error = self::cStackOverflowError($L, $error);
            }
            $status = $error->status;
            $value = $error->value;
            if ($messageHandler !== null && $status === Lua::LUA_ERRRUN) {
                [$status, $value] = self::callMessageHandler($L, $messageHandler, $value);
            }
            $errorCi = $L->ci;
            // ldo.c: luaD_rawrunprotected / luaD_pcall
            $L->nCcalls = $L->nCcallsBase + $oldnCcalls;
            $L->nny = $oldnny;
            $L->ci = $oldCi;
            $L->allowhook = $oldAllowhook;
            [$status, $value] = self::closeProtected($L, $errorCi, $oldCi, $status, $value, $messageHandler, $yieldable);
            self::unlinkAbandonedFrames($errorCi, $oldCi);
            self::shrinkStack($L);  // restore stack size in case of overflow
            $L->errfunc = $oldErrfunc;
            return [$status, $value];
        }
    }

    /**
     * ldebug.c: luaG_errormsg: call the message handler with the error
     * value. An error inside the handler calls the handler again for the
     * new error; the C-call count keeps growing until "C stack overflow"
     * and finally "error in error handling" (LUA_ERRERR) ends it.
     *
     * @return array{int, mixed}
     */
    private static function callMessageHandler(Coroutine $L, mixed $handler, mixed $value): array
    {
        while (true) {
            try {
                $results = self::callNoYield($L, $handler, [$value]);
                return [Lua::LUA_ERRRUN, $results[0] ?? null];
            } catch (LuaError|\Error $error) {
                if ($error instanceof \Error) {
                    $error = self::cStackOverflowError($L, $error);
                }
                if ($error->status !== Lua::LUA_ERRRUN) {
                    return [$error->status, $error->value];
                }
                $value = $error->value;
            }
        }
    }

    /**
     * ldo.c: luaD_closeprotected + lfunc.c: luaF_close for all frames from
     * $fromCi down to (not including) $downTo: close their upvalues, then
     * call pending '__close' methods (innermost first) with the error
     * value. An error in a '__close' method goes through $messageHandler,
     * still the current handler (ldebug.c: luaG_errormsg), and becomes the
     * new error value for the ones still to be called; the frames it
     * abandons (the method's own, above the current one) are closed
     * first. With $yieldable (see protectedRun) the methods may yield
     * (lfunc.c: luaF_close with yy = 1).
     *
     * @return array{int, mixed}
     */
    public static function closeProtected(Coroutine $L, CallInfo $fromCi, CallInfo $downTo, int $status, mixed $value, mixed $messageHandler = null, bool $yieldable = false): array
    {
        $oldCi = $L->ci;
        if ($yieldable) {  // a '__close' may yield: closeThread must find these frames
            $L->pendingCloses[] = [$fromCi, $oldCi];
        }
        for ($ci = $fromCi; $ci !== null && $ci !== $downTo; $ci = $ci->previous) {
            if ($ci->openupval !== []) {
                Upvalues::closeUpvalues($ci, 0);
            }
        }
        for ($ci = $fromCi; $ci !== null && $ci !== $downTo; $ci = $ci->previous) {
            while ($ci->tbclist !== []) {
                $register = array_pop($ci->tbclist);
                $oldnCcalls = $L->nCcalls - $L->nCcallsBase;  // relative, as in protectedRun
                $oldnny = $L->nny;
                $oldAllowhook = $L->allowhook;
                try {
                    Upvalues::callCloseMethod($L, $ci->R[$register], $value, $yieldable);
                } catch (LuaError|\Error $error) {  // an error occurred; restore saved state and repeat
                    if ($error instanceof \Error) {
                        $error = self::cStackOverflowError($L, $error);
                    }
                    $status = $error->status;
                    $value = $error->value;
                    if ($messageHandler !== null && $status === Lua::LUA_ERRRUN) {
                        [$status, $value] = self::callMessageHandler($L, $messageHandler, $value);
                    }
                    $errorCi = $L->ci;
                    $L->ci = $oldCi;
                    $L->nCcalls = $L->nCcallsBase + $oldnCcalls;
                    $L->nny = $oldnny;
                    $L->allowhook = $oldAllowhook;
                    [$status, $value] = self::closeProtected($L, $errorCi, $oldCi, $status, $value, $messageHandler, $yieldable);
                    self::unlinkAbandonedFrames($errorCi, $oldCi);
                }
            }
        }
        if ($yieldable) {
            array_pop($L->pendingCloses);
        }
        return [$status, $value];
    }

    /**
     * Break the 'previous' links of the frames abandoned by an error, one
     * by one: freeing a long chain at once would recurse in PHP's C code
     * (one level per frame) and overflow the C stack.
     */
    public static function unlinkAbandonedFrames(CallInfo $fromCi, CallInfo $downTo): void
    {
        $ci = $fromCi;
        while ($ci !== null && $ci !== $downTo) {
            $previous = $ci->previous;
            $ci->previous = null;
            $ci = $previous;
        }
    }

    /** ldo.c: luaD_shrinkstack: leave the extra space used to handle a stack overflow */
    public static function shrinkStack(Coroutine $L): void
    {
        $frameBudget = $L->globalState->frameBudget;
        if ($L->stackLimit > Lua::LUAI_MAXSTACK && $L->ci->top <= Lua::LUAI_MAXSTACK && $L->ci->frameBytes <= $frameBudget
            && self::luaDepth($L->ci) <= $L->globalState->callDepthLimit) {
            $L->stackLimit = Lua::LUAI_MAXSTACK;
            $L->frameBytesLimit = $frameBudget;
        }
    }
}
