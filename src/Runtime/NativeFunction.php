<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A function implemented in PHP (C: lua_CFunction / CClosure); for Lua it
 * is a function whose debug.getinfo 'what' is "C".
 *
 *     ($native->function)(Coroutine $L, array $arguments): array
 *
 * receives the argument list (0-based PHP list, Lua argument #n is
 * $arguments[n - 1]) and returns the list of results. $upvalues mirrors a
 * C closure's upvalues for debug.getupvalue/setupvalue (names are "").
 *
 * @internal
 */
final class NativeFunction
{
    /**
     * @param list<mixed> $upvalues
     */
    public function __construct(
        /** for PHP-side debugging only; Lua finds names from the calling code */
        public readonly string $name,
        public readonly \Closure $function,
        public array $upvalues = [],
    ) {
    }

    /** freeing a long chain of upvalues must not recurse: see Teardown */
    public function __destruct()
    {
        if ($this->upvalues === []) {
            return;
        }
        if (Teardown::$releasing) {
            Teardown::$pending[] = $this->upvalues;
            return;
        }
        Teardown::$releasing = true;
        $this->upvalues = [];
        Teardown::release();
    }

    /** @var array<int, \stdClass> */
    private array $upvalueSlotIdentities = [];

    /** an object standing for the address of upvalue slot $n (lua_upvalueid) */
    public function upvalueSlotIdentity(int $n): object
    {
        return $this->upvalueSlotIdentities[$n] ??= new \stdClass();
    }
}
