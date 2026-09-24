<?php

declare(strict_types=1);

namespace Tests\VirtualMemoryTest;

/*
 * Shared hosting often limits a process's address space (ulimit -v). The
 * runtime reserves little of it: the session runs on the process's own
 * stack and each coroutine's fiber gets 256 KB (Coroutine::FIBER_STACK_BYTES).
 * bin/lua and a bin/lua2php script must run all.lua under
 * ALL_LUA_VIRTUAL_CAP_KB, the floor measured in 128 MB steps (1408 MB
 * fails): its peak is about 1.5 GB with this machine's php.ini (PHP itself
 * maps 223 MB, opcache's shared memory included), set by the heap cstack.lua's
 * deep recursions need. Before, all.lua failed under any cap up to 64 GB.
 */

const ALL_LUA_VIRTUAL_CAP_KB = 1536 * 1024;

function test_bin_lua_and_a_lua2php_script_run_all_lua_under_the_virtual_memory_cap(): void
{
    $allPhp = scratchDirectory() . '/all.php';
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', 'all.lua', '-o', $allPhp], '', OFFICIAL_TESTS_DIRECTORY);
    assertSame(0, $status, "lua2php all.lua: $errorOutput");
    $underCap = static fn (array $command): array => ['sh', '-c', 'ulimit -v ' . ALL_LUA_VIRTUAL_CAP_KB . ' && exec "$@"', 'sh', ...$command];
    $running = [
        'bin/lua' => startProcess($underCap(['php', REPO_ROOT . '/bin/lua', '-e_U=true', 'all.lua']), OFFICIAL_TESTS_DIRECTORY),
        // a lua2php script takes no -e: LUA_INIT sets _U before the script runs
        'lua2php' => startProcess($underCap(['env', 'LUA_INIT=_U=true', 'php', $allPhp]), OFFICIAL_TESTS_DIRECTORY),
    ];
    foreach ($running as $name => $process) {
        [$status, $standardOutput, $errorOutput] = finishProcess($process);
        assertTrue(
            $status === 0 && str_contains($standardOutput, 'final OK !!!'),
            "$name all.lua under ulimit -v " . ALL_LUA_VIRTUAL_CAP_KB . ": exit status $status: " . substr($errorOutput, 0, 500),
        );
    }
}
