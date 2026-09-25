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
 *
 * lua2php output loads only files transpiled ahead of time, so the official
 * directory is transpiled as one project (a copy: reference/ is read-only).
 * Expected difference: files.lua, the last file all.lua runs, dofile()s a
 * file it writes at run time (files.lua:202); lua2php output refuses that,
 * so its run ends there, after every other official file passed. A
 * lua2php script keeps the memory_limit PHP was started with: it gets the
 * 4G bin/lua sets itself.
 */

const ALL_LUA_VIRTUAL_CAP_KB = 1536 * 1024;

function test_bin_lua_and_a_lua2php_project_run_all_lua_under_the_virtual_memory_cap(): void
{
    $project = scratchDirectory() . '/official-project';
    exec('cp -R ' . escapeshellarg(OFFICIAL_TESTS_DIRECTORY) . ' ' . escapeshellarg($project) . ' && chmod -R u+w ' . escapeshellarg($project), $output, $copyStatus);
    assertSame(0, $copyStatus, 'copy of the official tests');
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $project]);
    assertSame(0, $status, "lua2php: $errorOutput");
    $cacheDirectory = scratchDirectory() . '/official-project-cache';
    $underCap = static fn (array $command): array => ['sh', '-c', 'ulimit -v ' . ALL_LUA_VIRTUAL_CAP_KB . ' && exec "$@"', 'sh', ...$command];
    $running = [
        'bin/lua' => startProcess($underCap(['php', REPO_ROOT . '/bin/lua', '-e_U=true', 'all.lua']), OFFICIAL_TESTS_DIRECTORY),
        // a lua2php script takes no -e: LUA_INIT sets _U before the script runs
        'lua2php' => startProcess($underCap(['env', 'LUA_INIT=_U=true', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', '-d', 'memory_limit=4G', 'all.php']), $project),
    ];
    [$status, $standardOutput, $errorOutput] = finishProcess($running['bin/lua']);
    assertTrue(
        $status === 0 && str_contains($standardOutput, 'final OK !!!'),
        'bin/lua all.lua under ulimit -v ' . ALL_LUA_VIRTUAL_CAP_KB . ": exit status $status: " . substr($errorOutput, 0, 500),
    );
    [$status, $standardOutput, $errorOutput] = finishProcess($running['lua2php']);
    // (the dots are progress output some tests write to stderr, as under lua5.4)
    $expectedError = "~\\A\\.*all\\.php: files\\.lua:202: cannot load (/\\S+): not transpiled ahead of time \\(no \\1\\.php\\)\n~";
    assertTrue(
        $status === 1 && str_contains($standardOutput, "***** FILE 'files.lua'*****") && preg_match($expectedError, $errorOutput) === 1,
        'lua2php all.lua under ulimit -v ' . ALL_LUA_VIRTUAL_CAP_KB . ": exit status $status: " . substr($errorOutput, 0, 500),
    );
}
