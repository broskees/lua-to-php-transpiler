<?php

declare(strict_types=1);

namespace Tests\GcTest;

use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\Standalone;

/*
 * Collector states that Lua code cannot set up deterministically; the
 * behaviour itself is covered against lua5.4 by tests/diff/gc_*.lua.
 */

function test_count_is_a_float_even_for_whole_kilobytes(): void
{
    $L = Standalone::newStateWithLibraries();
    $G = $L->globalState;
    // exactly 4 KB charged, and no PHP memory growth to add
    $G->gcTotalBytes = 4096;
    $G->gcDebt = 0;
    $G->gcEstimate = 0;
    $G->gcRealBase = PHP_INT_MAX >> 2;
    $main = ChunkLoader::load($L, 'return collectgarbage("count"), math.type(collectgarbage("count"))', '=count', null);
    [$status, $results] = Calls::protectedCall($L, $main, []);
    assertSame(Lua::LUA_OK, $status);
    assertSame([4.0, 'float'], $results);
}
