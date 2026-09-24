<?php

declare(strict_types=1);

namespace Tests\ObjectMemoryTest;

use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Standalone;

/*
 * The PHP memory a Lua program pays for each object it keeps (see AGENTS.md,
 * "Tables" and "Emitted function"). Zend MM hands out small blocks in bins
 * (..., 56, 64, 80, 96, 112, 128, 160, 192, ... bytes): an object takes 40
 * bytes plus 16 per declared property, a PHP array 56 bytes plus at least 8
 * slots (160 bytes as a list, 320 with string keys), a PHP reference 32.
 * memory_get_usage() counts exactly those blocks, so the budgets are exact.
 */

/**
 * PHP memory, in bytes per object, that the Lua statement $statement
 * allocates when run for i = 1, $count (it stores its object in keep[i]).
 * Locals available: mt (an empty table), f (a function that yields).
 * The smaller of two runs: in a process that has run other tests, the
 * first may pay for the one-off growth of an internal PHP buffer.
 */
function bytesPerObject(string $statement, int $count = 4000): float
{
    return min(bytesPerObjectOnce($statement, $count), bytesPerObjectOnce($statement, $count));
}

function bytesPerObjectOnce(string $statement, int $count): float
{
    $L = Standalone::newStateWithLibraries();
    $L->globalState->globals->hash['memory'] = new NativeFunction('memory', static fn (Coroutine $L, array $args): array => [memory_get_usage()]);
    $source = <<<LUA
    local N = ...
    local mt = {}
    local function f () coroutine.yield() end
    collectgarbage()
    collectgarbage("stop")
    local keep = {}
    for i = 1, N do keep[i] = false end
    local warm = {}  -- grow PHP's object store (8 bytes per object handle) now, not while measuring
    for i = 1, 8 * N do warm[i] = {} end
    warm = nil
    local before = memory()
    for i = 1, N do $statement end
    return (memory() - before) / N
    LUA;
    $phpCollectorWasEnabled = gc_enabled();
    gc_disable();
    try {
        [$status, $results] = Standalone::docall($L, ChunkLoader::load($L, $source, '=memory', null), [$count]);
    } finally {
        if ($phpCollectorWasEnabled) {
            gc_enable();
        }
    }
    assertSame(Lua::LUA_OK, $status, 'measuring chunk failed');
    return $results[0];
}

function assertAtMost(float $budget, string $statement): void
{
    $bytes = bytesPerObject($statement);
    // (+ 1: a few one-off bytes spread over all objects)
    assertTrue($bytes < $budget + 1, "'$statement' costs $bytes bytes per object, budget $budget");
}

function test_an_empty_table_is_one_object_of_five_properties(): void
{
    assertAtMost(128, 'keep[i] = {}');
    assertAtMost(128, 'keep[i] = setmetatable({}, mt)');
}

function test_a_record_is_the_table_plus_one_hash_array(): void
{
    assertAtMost(128 + 56 + 320, 'keep[i] = {x = i, y = i, name = "n"}');
    assertAtMost(128 + 56 + 320, 'keep[i] = {value = i, next = keep[i - 1] or false}');
}

function test_a_small_array_is_the_table_plus_one_list(): void
{
    assertAtMost(128 + 56 + 160, 'keep[i] = {i, i, i, i}');
}

function test_a_traversed_table_is_as_small_as_before(): void
{
    assertAtMost(128 + 56 + 320, 'local t = {x = i}; for _ in pairs(t) do end; keep[i] = t');
    assertAtMost(128 + 56 + 160, 'local t = {i}; for _ in pairs(t) do end; keep[i] = t');
    assertAtMost(128, 'local t = {}; next(t); keep[i] = t');
}

function test_a_closure_holds_its_upvalues_without_an_array(): void
{
    $closedUpvalue = 56;  // UpVal: one property
    assertAtMost(96 + $closedUpvalue, 'do local a = i; keep[i] = function () return a end end');
    assertAtMost(112 + 2 * $closedUpvalue, 'do local a, b = i, i; keep[i] = function () return a + b end end');
    assertAtMost(128 + 3 * $closedUpvalue, 'do local a, b, c = i, i, i; keep[i] = function () return a + b + c end end');
    assertAtMost(96, 'keep[i] = function () return mt end');  // a shared upvalue
}

/** PHP memory, in bytes per fiber, of $count bare PHP fibers suspended at their first instruction */
function bytesPerBareFiber(int $count = 1000): float
{
    ini_set('fiber.stack_size', (string) Coroutine::FIBER_STACK_BYTES);
    $suspend = static function (): void {
        \Fiber::suspend();
    };
    $keep = array_fill(0, $count, null);
    $before = memory_get_usage();
    for ($i = 0; $i < $count; $i++) {
        $keep[$i] = new \Fiber($suspend);
        $keep[$i]->start();
    }
    return (memory_get_usage() - $before) / $count;
}

function test_a_suspended_coroutine_costs_its_fiber_plus_a_few_objects(): void
{
    $fiber = bytesPerBareFiber();  // a 16 KB VM stack (mostly untouched) and the Fiber object
    // Coroutine (448), its base CallInfo, the CallInfos of f and of the
    // yield (224 each), f's registers (a 216-byte list, bound to its
    // CallInfo by a 32-byte reference): 1368
    $budget = 448 + 3 * 224 + 216 + 32 + 64;
    $coroutine = bytesPerObject('local co = coroutine.create(f); coroutine.resume(co); keep[i] = co', 1000);
    assertTrue($coroutine - $fiber < $budget, "a suspended coroutine costs $fiber bytes of fiber + " . ($coroutine - $fiber));
    // ... plus the C closure of wrap: NativeFunction (112) and its upvalue list (216)
    $wrapped = bytesPerObject('local co = coroutine.wrap(f); co(); keep[i] = co', 1000);
    assertTrue($wrapped - $fiber < $budget + 112 + 216, "a suspended wrapped coroutine costs $fiber bytes of fiber + " . ($wrapped - $fiber));
}
