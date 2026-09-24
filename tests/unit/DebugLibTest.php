<?php

declare(strict_types=1);

namespace Tests\DebugLibTest;

use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\Standalone;
use LuaPhp\Runtime\Userdata;

/*
 * debug.getuservalue/setuservalue on full userdata with user values. Lua
 * code cannot create such userdata without the C test library (api.lua uses
 * T.newuserdata), so these tests create them in PHP and mirror api.lua's
 * assertions; tests/diff/debug_metatables.lua covers the rest against lua5.4.
 */

/** Run $source with global 'b' = a full userdata with $userValueCount user values; returns its results. */
function runWithUserdata(string $source, int $userValueCount): array
{
    $L = Standalone::newStateWithLibraries();
    $L->globalState->globals->hash['b'] = new Userdata(null, array_fill(0, $userValueCount, null));
    $main = ChunkLoader::load($L, $source, '=uservalues', null);
    [$status, $results] = Calls::protectedCall($L, $main, []);
    assertSame(Lua::LUA_OK, $status, 'chunk failed: ' . var_export($results[0] ?? null, true));
    return $results;
}

function test_user_values_start_nil_and_indices_out_of_range_fail(): void
{
    runWithUserdata(<<<'LUA'
        for i = 1, 10 do
          local v, p = debug.getuservalue(b, i)
          assert(v == nil and p == true)
        end
        for _, i in ipairs{-2, 0, 11, 2^32 + 11} do
          local v, p = debug.getuservalue(b, i)
          assert(v == nil and p == nil and select('#', debug.getuservalue(b, i)) == 1)
        end
        assert(select('#', debug.getuservalue(4)) == 1 and not debug.getuservalue(4))
        LUA, 10);
}

function test_set_user_values_are_returned_by_index(): void
{
    $results = runWithUserdata(<<<'LUA'
        local t = {true, false, 4.56, print, {}, b, "XYZ"}
        for k, v in ipairs(t) do
          assert(debug.setuservalue(b, v, k) == b)
        end
        for k, v in ipairs(t) do
          local v1, p = debug.getuservalue(b, k)
          assert(v1 == v and p)
        end
        debug.setuservalue(b, function () return 10 end, 10)
        assert(debug.getuservalue(b, 10)() == 10)
        debug.setuservalue(b, 134)
        assert(debug.getuservalue(b) == 134)
        assert(debug.setuservalue(b, 1, 11) == nil)
        assert(debug.setuservalue(b, 1, 0) == nil)
        assert(debug.setuservalue(b, 1, 2^32 + 1) == b)  -- C casts the index to int
        return debug.getuservalue(b, 1)
        LUA, 10);
    assertSame([1, true], $results);
}

function test_userdata_without_user_values(): void
{
    $results = runWithUserdata(<<<'LUA'
        assert(debug.setuservalue(b, 10) == nil)
        local v, p = debug.getuservalue(b)
        assert(v == nil and p == nil)
        return select('#', debug.getuservalue(b, 1)), type(b)
        LUA, 0);
    assertSame([1, 'userdata'], $results);
}
