<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Frees long chains of runtime objects one link at a time.
 *
 * PHP frees what an object refers to when it frees the object, recursively
 * on the C stack: dropping a Lua list of a million tables nests a million
 * levels deep, about 150 bytes of C stack each, far more than a coroutine's
 * fiber has (Coroutine::FIBER_STACK_BYTES), and PHP has no guard there: the
 * process crashes. (C Lua's collector frees objects from a list, never
 * recursively.)
 *
 * So every class whose objects can link to more Lua values (LuaTable,
 * UpVal, NativeFunction, Userdata, Coroutine) has a destructor following
 * this protocol, which PHP calls before it frees the object:
 *
 * - if a cheap test shows nothing could lead to more objects, return (an
 *   UpVal holding a number, a native without upvalues, ...);
 * - if release() is running further up the C stack ($releasing), append
 *   the values the object refers to to $pending: when PHP frees the object
 *   right after the destructor, they survive in $pending, so nothing more
 *   is freed at this depth;
 * - otherwise (the first object of a chain to be freed) set $releasing,
 *   clear those fields, which frees them at this depth (objects they alone
 *   refer to take the branch above), then call release(), which frees what
 *   they queued one value at a time (a table skips the call when nothing
 *   was queued).
 *
 * So the C stack holds at most a couple of destructors at any time, however
 * long the chain. The cost is a destructor call for every object freed
 * (about 60 ns for a table), so the classes freed most often keep these
 * paths short. Objects of other classes are safe links when they always
 * lead to one of these within a few steps (a LuaClosure's upvalues are
 * UpVals; a CallInfo chain is unlinked by its Coroutine's destructor).
 *
 * Destructors run when PHP frees an object (reference counting, its cycle
 * collector, the unwinding of a destroyed suspended fiber, shutdown), never
 * while Lua can still reach it, so emptying its fields is invisible to Lua
 * (Gc\Collector decides what Lua sees; it holds objects with finalizers
 * itself). They must never run Lua code or touch Lua-visible state (see
 * AGENTS.md, "Coroutines").
 *
 * @internal
 */
final class Teardown
{
    /** @var list<mixed> values handed over by destructors while release() runs */
    public static array $pending = [];

    /**
     * true while the first destructor of a chain frees its fields or
     * release() runs (further up the C stack). Nothing resets it if a
     * destructor throws, but a PHP exception is a crash anyway (see
     * Standalone::configurePhp).
     */
    public static bool $releasing = false;

    /**
     * Free $pending one value at a time, from the last one: freeing a value
     * frees the objects only it referred to, whose destructors append what
     * they refer to. Called with $releasing set; clears it.
     */
    public static function release(): void
    {
        while (self::$pending !== []) {
            array_pop(self::$pending);
        }
        self::$pending = [];  // give back the memory of a queue that grew large
        self::$releasing = false;
    }
}
