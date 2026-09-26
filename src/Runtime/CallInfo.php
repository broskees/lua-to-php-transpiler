<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Information about one active function call (C: CallInfo in lstate.h).
 * The call stack of a thread is the chain $L->ci -> previous -> ... down
 * to the thread's base CallInfo ($func === null).
 *
 * A Lua function's emitted code creates its CallInfo on entry (which
 * pushes it: $L->ci = $ci) and pops it on return ($L->ci = previous).
 * Calls to native functions push one in Calls::callNative. When an error
 * is thrown nobody pops: the protected call that catches it still sees the
 * whole chain (for the message handler and tracebacks) and then resets
 * $L->ci itself (ldo.c: luaD_pcall).
 *
 * @internal
 */
final class CallInfo
{
    public ?CallInfo $previous = null;

    /**
     * Approximate stack top of this frame, in Lua stack slots (C: ci->top),
     * used for Lua's "stack overflow" limit. The frame's function is taken
     * to sit at the caller's top.
     */
    public int $top = 0;

    /**
     * Lua functions: the running function's registers. The emitted prologue
     * binds this to the function's local $R by reference, so debug access
     * (getlocal/setlocal) reads and writes the live registers.
     *
     * @var array<int, mixed>
     */
    public array $R = [];

    /** Lua vararg functions: the extra arguments (C: ci->u.l.nextraargs values) */
    public array $varargs = [];

    /**
     * Lua functions: index of the instruction being executed (C:
     * currentpc(ci), i.e. savedpc - 1). Emitted code stores it before any
     * instruction that can raise an error, call a function or run a
     * metamethod.
     */
    public int $savedpc = 0;

    /** @var array<int, UpVal> open upvalues of this frame, by register (C: L->openupval) */
    public array $openupval = [];

    /** @var list<int> registers holding pending to-be-closed variables, in creation order (C: L->tbclist) */
    public array $tbclist = [];

    /** the running function (null for a thread's base CallInfo) */
    public LuaClosure|NativeFunction|null $func = null;

    /** CIST_* bits (Lua::CIST_TAIL, ...) */
    public int $callstatus = 0;

    /**
     * Estimated PHP memory held by this frame and all frames below it (see
     * Calls::MAX_FRAME_BYTES).
     */
    public int $frameBytes = 0;

    /**
     * Lua call levels at and below this frame, set by code compiled with
     * step counting (GlobalState::$callDepthLimit); 0 in other frames (see
     * Calls::luaDepth for a native frame's).
     */
    public int $depth = 0;

    /**
     * Push a new CallInfo for $func on thread $L, raising Lua's "stack
     * overflow" first if the frame does not fit (ldo.c: luaD_precall ->
     * checkstackGCp -> luaD_growstack; the error is raised while the caller
     * is still the current function, as in C).
     *
     * The prologue of every emitted Lua function does the same inline
     * (FunctionEmitter::emit), to save a PHP call per Lua call.
     */
    public static function push(Coroutine $L, LuaClosure|NativeFunction|null $func, int $callstatus, int $frameSize, int $frameBytes): self
    {
        $ci = new self();
        $ci->func = $func;
        $ci->callstatus = $callstatus;
        $previous = $L->ci;
        if ($previous === null) {  // the thread's base CallInfo
            $ci->previous = null;
            $ci->top = $frameSize;
            $L->ci = $ci;
            return $ci;
        }
        $ci->previous = $previous;
        $ci->top = $previous->top + $frameSize;
        $ci->frameBytes = $previous->frameBytes + $frameBytes;
        if ($ci->top > $L->stackLimit || $ci->frameBytes > $L->frameBytesLimit) {
            Calls::stackOverflow($L);
        }
        $L->ci = $ci;
        return $ci;
    }

    public function isLua(): bool
    {
        return $this->func instanceof LuaClosure;
    }
}
