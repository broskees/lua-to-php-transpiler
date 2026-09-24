<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * A Lua function value (C: LClosure in lobject.h).
 *
 * $code is the PHP closure the emitter generated for $proto; every Lua
 * closure of the same Proto shares it. Calling convention (see AGENTS.md,
 * "Runtime conventions"):
 *
 *     ($closure->code)(Coroutine $L, LuaClosure $closure, array $arguments, int $callstatus = 0): ?array
 *
 * returns the list of results, or null when the function ended in a tail
 * call that the caller must finish (Calls::finishTailCall).
 *
 * Upvalues: C allocates a closure with exactly as many UpVal pointers as
 * its Proto has upvalues. Here too the object fits the count: a closure is
 * an instance of the subclass for it, which holds the UpVals in properties
 * ($u0, $u1, $u2 in LuaClosure1, LuaClosure2, LuaClosure3) or, with no
 * upvalues or more than three, in the list $upvals of LuaClosureN. (A PHP
 * array costs at least 216 bytes, a property 16; most closures have one
 * upvalue.) create() picks the class. The emitted code of a Proto knows the
 * class of its closures and reads $cl->u0 or $cl->upvals[$i] directly;
 * other code uses getUpval()/setUpval().
 */
abstract class LuaClosure
{
    /** closures with 1 .. MAX_UPVALUE_PROPERTIES upvalues keep them in properties $u0, $u1, ... */
    public const MAX_UPVALUE_PROPERTIES = 3;

    public readonly Proto $proto;

    public readonly \Closure $code;

    /**
     * A closure of $proto whose code is $code, with $upvals (one UpVal per
     * entry of $proto->upvalues).
     *
     * @param list<UpVal> $upvals
     */
    public static function create(Proto $proto, \Closure $code, array $upvals): self
    {
        return match (\count($upvals)) {
            1 => new LuaClosure1($proto, $code, $upvals[0]),
            2 => new LuaClosure2($proto, $code, $upvals[0], $upvals[1]),
            3 => new LuaClosure3($proto, $code, $upvals[0], $upvals[1], $upvals[2]),
            default => new LuaClosureN($proto, $code, $upvals),
        };
    }

    /** upvalue $index (0-based, below the Proto's upvalue count) */
    public function getUpval(int $index): UpVal
    {
        return $this->{'u' . $index};
    }

    /** make upvalue $index (0-based) $upval (lapi.c: lua_upvaluejoin) */
    public function setUpval(int $index, UpVal $upval): void
    {
        $this->{'u' . $index} = $upval;
    }
}
