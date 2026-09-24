<?php

declare(strict_types=1);

namespace Tests\CoroutineMemoryTest;

/*
 * Memory budget of coroutines. Each coroutine runs on a PHP Fiber, which
 * reserves a C stack (Coroutine::FIBER_STACK_BYTES of address space; only
 * touched pages count) and a VM stack page. Finished coroutines must give
 * that back at once, and suspended coroutines that become garbage must be
 * freed by PHP's cycle collector, so many coroutines fit in a sane budget.
 * The Lua script measures its own process in /proc/self/status.
 */

const MEMORY_SCRIPT = <<<'LUA'
local function status(field)
  local file = io.open("/proc/self/status")
  local text = file:read("a")
  file:close()
  return tonumber(text:match(field .. ":%s*(%d+)"))
end
local mode, n = arg[1], tonumber(arg[2])
local startRss = status("VmRSS")
if mode == "short" then
  for i = 1, n do
    local co = coroutine.create(function (x) coroutine.yield(x); return x end)
    assert(coroutine.resume(co, i)); assert(coroutine.resume(co))
  end
elseif mode == "dropped" then
  for i = 1, n do
    local co = coroutine.wrap(function () local t = {i}; pcall(coroutine.yield, t) end)
    co()
  end
elseif mode == "alive" then
  local keep = {}
  for i = 1, n do
    keep[i] = coroutine.wrap(function () coroutine.yield(i) end)
    keep[i]()
  end
end
print(status("VmHWM") - startRss)
LUA;

/** peak RSS growth in KB while running MEMORY_SCRIPT in $mode with $count coroutines */
function peakGrowthKilobytes(string $mode, int $count): int
{
    $script = scratchDirectory() . '/coroutine_memory.lua';
    file_put_contents($script, MEMORY_SCRIPT);
    [$status, $stdout, $stderr] = runCommand(['php', REPO_ROOT . '/bin/lua', $script, $mode, (string) $count]);
    assertSame(0, $status, "bin/lua failed: $stderr");
    return (int) trim($stdout);
}

function test_finished_coroutines_give_their_memory_back(): void
{
    $growth = peakGrowthKilobytes('short', 50000);
    assertTrue($growth < 16 * 1024, "50000 finished coroutines grew the peak RSS by $growth KB");
}

function test_suspended_coroutines_that_become_garbage_are_freed(): void
{
    $growth = peakGrowthKilobytes('dropped', 50000);
    assertTrue($growth < 64 * 1024, "50000 dropped suspended coroutines grew the peak RSS by $growth KB");
}

function test_live_suspended_coroutines_cost_less_than_25_kilobytes_each(): void
{
    $growth = peakGrowthKilobytes('alive', 10000);
    assertTrue($growth < 10000 * 25, "10000 live suspended coroutines grew the peak RSS by $growth KB");
}
