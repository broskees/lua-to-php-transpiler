<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\OpCodes;
use LuaPhp\Compiler\Proto;

/**
 * Debug hooks: lua_sethook and luaG_traceexec from ldebug.c, luaD_hook,
 * luaD_hookcall and rethook from ldo.c.
 *
 * A thread's hook ($L->hook, C: lua_Hook) is a PHP closure
 * (Coroutine $L, int $event, int $line, CallInfo $ci, int $top): void,
 * where $top is the hooked frame's stack top (C: L->top - base, see
 * hook()); the debug library installs DebugLib's hookf, which calls the
 * Lua function stored in the registry's hook table.
 *
 * Where the hooks run (see AGENTS.md, "Runtime conventions"):
 * - line and count hooks: emitted code checks $trap before every
 *   instruction C's vmfetch traces. $trap is bound by reference to
 *   $L->trap, which is true while the thread has a line or count hook, so a
 *   hook set while a function runs takes effect at its next instruction
 *   (C: settraps + updatetrap).
 * - call hooks: the prologue of a Lua function (after OP_VARARGPREP for
 *   vararg functions), Calls::callNative for native functions.
 * - return hooks: OP_RETURN* in emitted code, Calls::callNative,
 *   Calls::tailCall for a native function called in tail position.
 */
final class Hooks
{
    /** ldebug.c: lua_sethook */
    public static function setHook(Coroutine $L, ?\Closure $func, int $mask, int $count): void
    {
        if ($func === null || $mask === 0) {  // turn off hooks?
            $mask = 0;
            $func = null;
        }
        $L->hook = $func;
        $L->basehookcount = $count;
        $L->hookcount = $count;  // resethookcount
        $L->hookmask = $mask;
        // settraps: every running Lua function of the thread checks the
        // line/count hooks before its next instruction
        $L->trap = ($mask & (Lua::LUA_MASKLINE | Lua::LUA_MASKCOUNT)) !== 0;
    }

    /**
     * ldo.c: luaD_hook: call the hook for $event, if there is one and hooks
     * are allowed (they are not inside a hook). The hook runs on top of the
     * current CallInfo, which is marked CIST_HOOKED while it runs (and
     * CIST_TRAN when values are being transferred, for getinfo's 'r').
     * $top is the number of slots in use in the current frame (C: L->top -
     * base); the hook runs above them, and above a Lua frame's whole
     * register window.
     */
    public static function hook(Coroutine $L, int $event, int $line, int $ftransfer, int $ntransfer, int $top): void
    {
        $hook = $L->hook;
        if ($hook === null || !$L->allowhook) {  // make sure there is a hook
            return;
        }
        $mask = Lua::CIST_HOOKED;
        $ci = $L->ci;
        if ($ntransfer !== 0) {
            $mask |= Lua::CIST_TRAN;  // 'ci' has transfer information
            $L->ftransfer = $ftransfer;
            $L->ntransfer = $ntransfer;
        }
        if ($ci->func instanceof LuaClosure && $top < $ci->func->proto->maxstacksize) {
            $top = $ci->func->proto->maxstacksize;  // protect entire activation register
        }
        $L->allowhook = false;  // cannot call hooks inside a hook
        $ci->callstatus |= $mask;
        $hook($L, $event, $line, $ci, $top);
        $L->allowhook = true;
        $ci->callstatus &= ~$mask;
    }

    /**
     * ldo.c: luaD_hookcall: the call hook of a Lua function, run before its
     * first instruction ($pc 0), or after OP_VARARGPREP ($pc 1) for a vararg
     * function. During the hook the function is at $pc (C: "hooks assume
     * 'pc' is already incremented").
     */
    public static function hookCall(Coroutine $L, CallInfo $ci, int $pc): void
    {
        $L->oldpc = 0;  // set 'oldpc' for new function
        if ($L->hookmask & Lua::LUA_MASKCALL) {  // is call hook on?
            $event = ($ci->callstatus & Lua::CIST_TAIL) ? Lua::LUA_HOOKTAILCALL : Lua::LUA_HOOKCALL;
            $ci->savedpc = $pc;
            $proto = $ci->func->proto;
            // the stack top: after the arguments (ldo.c: luaD_precall), or
            // after the fixed parameters, moved above the others (ltm.c:
            // luaT_adjustvarargs)
            $top = $proto->is_vararg ? $proto->numparams : \count($ci->R);
            self::hook($L, $event, -1, 1, $proto->numparams, $top);
        }
    }

    /**
     * ldo.c: rethook: the return hook of the function running in $ci (still
     * the current CallInfo), whose $nres results are its stack slots
     * $ftransfer .. $ftransfer + $nres - 1 (debug.getlocal numbering).
     * Also sets 'oldpc' for the line hook of a Lua caller, even when return
     * hooks are off.
     */
    public static function retHook(Coroutine $L, CallInfo $ci, int $ftransfer, int $nres): void
    {
        if ($L->hookmask & Lua::LUA_MASKRET) {  // is return hook on?
            self::hook($L, Lua::LUA_HOOKRET, -1, $ftransfer, $nres, $ftransfer + $nres - 1);  // (the results end at the top)
        }
        $previous = $ci->previous;
        if ($previous !== null && $previous->func instanceof LuaClosure) {
            $L->oldpc = $previous->savedpc;  // set 'oldpc'
        }
    }

    /**
     * ldebug.c: luaG_traceexec: the count and line hooks, called before the
     * instruction at $pc of the Lua function running in $ci runs. $top is
     * the emitted code's $top when that instruction takes the values the
     * previous one left up to there (lopcodes.h: isIT).
     */
    public static function traceExec(Coroutine $L, CallInfo $ci, int $pc, int $top = 0): void
    {
        $mask = $L->hookmask;
        if (!($mask & (Lua::LUA_MASKLINE | Lua::LUA_MASKCOUNT))) {  // no hooks?
            return;
        }
        $ci->savedpc = $pc;  // save 'pc'
        $countHook = ($mask & Lua::LUA_MASKCOUNT) && --$L->hookcount === 0;
        if ($countHook) {
            $L->hookcount = $L->basehookcount;  // reset count
        } elseif (!($mask & Lua::LUA_MASKLINE)) {
            return;  // no line hook and count != 0; nothing to be done now
        }
        $p = $ci->func->proto;
        if (!OpCodes::isIT($p->code[$pc])) {  // top not being used?
            $top = 0;  // correct top (hook() raises it to the whole frame)
        }
        if ($countHook) {
            self::hook($L, Lua::LUA_HOOKCOUNT, -1, 0, 0, $top);  // call count hook
        }
        if ($mask & Lua::LUA_MASKLINE) {
            // 'L->oldpc' may be invalid; use zero in this case
            $oldpc = $L->oldpc < \count($p->code) ? $L->oldpc : 0;
            if ($pc <= $oldpc  // call hook when jump back (loop),
                || self::changedLine($p, $oldpc, $pc)) {  // or when enter new line
                self::hook($L, Lua::LUA_HOOKLINE, DebugInfo::getFuncLine($p, $pc), 0, 0, $top);  // call line hook
            }
            $L->oldpc = $pc;  // 'pc' of last call to line hook
        }
    }

    /**
     * ldebug.c: changedline: is instruction $newpc in a different line from
     * instruction $oldpc (which comes before it)?
     */
    private static function changedLine(Proto $p, int $oldpc, int $newpc): bool
    {
        if ($p->lineinfo === []) {  // no debug information?
            return false;
        }
        if ($newpc - $oldpc < intdiv(DebugInfo::MAXIWTHABS, 2)) {  // not too far apart?
            $delta = 0;  // line difference
            $pc = $oldpc;
            while (true) {
                $lineInfo = $p->lineinfo[++$pc];
                if ($lineInfo === DebugInfo::ABSLINEINFO) {
                    break;  // cannot compute delta; fall through
                }
                $delta += $lineInfo;
                if ($pc === $newpc) {
                    return $delta !== 0;  // delta computed successfully
                }
            }
        }
        // either instructions are too far apart or there is an absolute line
        // info in the way; compute line difference explicitly
        return DebugInfo::getFuncLine($p, $oldpc) !== DebugInfo::getFuncLine($p, $newpc);
    }
}
