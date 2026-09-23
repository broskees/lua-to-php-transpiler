<?php

declare(strict_types=1);

namespace Tests\EmitterTest;

use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\OpCodes;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\Standalone;

/*
 * The emitted PHP is meant to be read: every instruction carries a
 * "// [pc] OPNAME args ; line N" comment, jump targets are labels, and a
 * chunk is one factory expression.
 */

function test_every_instruction_has_a_comment_with_pc_opcode_and_line(): void
{
    $source = "local t = {}\nfor i = 1, 3 do\n  t[i] = i * 2\nend\nif #t > 2 then print(t[3]) else print('no') end\nreturn function (x) return x + 1 end\n";
    $proto = Compiler::compile($source, '=emitter');
    $code = Emitter::emitChunk($proto);
    foreach ([$proto, $proto->p[0]] as $function) {
        foreach ($function->code as $pc => $instruction) {
            $name = OpCodes::OPNAMES[OpCodes::GET_OPCODE($instruction)];
            assertTrue(preg_match('/\/\/ \[' . $pc . '\] ' . $name . ' [^\n]* ; line \d+\n/', $code) === 1, "comment for [$pc] $name");
        }
    }
    assertTrue(preg_match('/\/\/ \[\d+\] FORPREP \d+ \d+ ; line 2\n/', $code) === 1, 'FORPREP comment with its line');
    assertTrue(preg_match('/^    L\d+:$/m', $code) === 1, 'jump targets are labels');
    assertTrue(str_starts_with($code, 'static function (Proto $proto_0): \\Closure {'), 'factory expression');
}

function test_emitted_code_is_shared_by_all_closures_of_a_proto(): void
{
    $L = Standalone::newStateWithLibraries();
    $main = ChunkLoader::load($L, "local fs = {}\nfor i = 1, 3 do fs[i] = function () return i end end\nreturn fs[1], fs[2], fs[3]", '=shared', null);
    [$status, $results] = Calls::protectedCall($L, $main, []);
    assertSame(Lua::LUA_OK, $status);
    assertSame(3, count($results));
    assertTrue($results[0]->code === $results[1]->code && $results[1]->code === $results[2]->code, 'one PHP closure per Proto');
    assertTrue($results[0]->upvals[0] !== $results[1]->upvals[0], 'fresh upvalue per iteration');
}

function test_loading_the_same_chunk_repeatedly_does_not_grow_memory(): void
{
    $L = Standalone::newStateWithLibraries();
    for ($i = 0; $i < 200; $i++) {  // warm up caches
        ChunkLoader::load($L, 'local a = 1; return a + 1', '=repeat', null);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < 5000; $i++) {
        $function = ChunkLoader::load($L, 'local a = 1; return a + 1', '=repeat', null);
        Calls::call($L, $function, []);
    }
    $growth = memory_get_usage() - $before;
    assertTrue($growth < 512 * 1024, "memory grew by $growth bytes over 5000 loads");
}
