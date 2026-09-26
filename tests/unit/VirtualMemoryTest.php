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
        'lua2php' => startProcess($underCap(['env', 'LUA_INIT=_U=true', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', ...OPCACHE_ON_NEW_FILES, '-d', 'memory_limit=4G', 'all.php']), $project),
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

/**
 * A fiber's C stack that cannot be mapped (the address space is used up)
 * is a failed allocation: Lua's catchable "not enough memory" (C's
 * allocator failing), once PHP has freed the fibers of unreachable
 * coroutines and tried again (lmem.c: tryagain), never a PHP fatal
 * error. A host that disables ini_set gives fibers its own
 * fiber.stack_size (PHP's default 2 MB, here 64 MB), which uses up a
 * capped address space with far fewer coroutines than 256 KB would.
 */
const FIBER_STACKS_SCRIPT = <<<'LUA'
    local function useUpTheAddressSpace()
      local live = {}
      for i = 1, 1000 do
        local co = coroutine.create(function () coroutine.yield() end)
        local ok, message = coroutine.resume(co)
        if not ok then print(i > 1, message, coroutine.status(co)) break end
        live[i] = co
      end
      print(pcall(coroutine.wrap(function () return "wrap" end)))
    end
    useUpTheAddressSpace()
    print(coroutine.resume(coroutine.create(function () return "works again" end)))
    LUA;

function test_a_fiber_stack_that_cannot_be_mapped_is_not_enough_memory(): void
{
    $directory = scratchDirectory() . '/fiber-stacks';
    @mkdir($directory);
    file_put_contents("$directory/fiber_stacks.lua", FIBER_STACKS_SCRIPT);
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', 'fiber_stacks.lua'], '', $directory);
    assertSame(0, $status, $errorOutput);
    $hostSettings = ['-d', 'disable_functions=ini_set', '-d', 'fiber.stack_size=64M'];
    $expected = [0, "true\tnot enough memory\tdead\nfalse\tnot enough memory\ntrue\tworks again\n", ''];
    foreach ([[REPO_ROOT . '/bin/lua', 'fiber_stacks.lua'], ['fiber_stacks.php']] as $arguments) {
        $command = ['sh', '-c', 'ulimit -v ' . (1024 * 1024) . ' && exec "$@"', 'sh', 'php', ...OPCACHE_ON_NEW_FILES, ...$hostSettings, ...$arguments];
        assertSame($expected, runCommand($command, '', $directory), basename($arguments[0]));
    }
}
