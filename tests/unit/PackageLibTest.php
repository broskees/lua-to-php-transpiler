<?php

declare(strict_types=1);

namespace Tests\PackageLibTest;

/*
 * loadlib.c behaviour that tests/diff cases cannot show: package.path and
 * package.cpath from the environment (LUA_PATH_5_4, LUA_PATH, ";;", -E),
 * 'lua -l', and package.loadlib without dynamic libraries.
 */

/**
 * Run bin/lua and lua5.4 with $arguments and exactly the given LUA_*PATH
 * variables; both must produce the same exit status, stdout and stderr.
 *
 * @param list<string> $arguments
 * @param array<string, string> $environment
 */
function assertSameAsReference(array $arguments, array $environment = [], ?string $workingDirectory = null): void
{
    $envCommand = ['env', '-u', 'LUA_PATH', '-u', 'LUA_PATH_5_4', '-u', 'LUA_CPATH', '-u', 'LUA_CPATH_5_4', '-u', 'LUA_INIT', '-u', 'LUA_INIT_5_4'];
    foreach ($environment as $name => $value) {
        $envCommand[] = "$name=$value";
    }
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $ours = runCommand([...$envCommand, 'php', $ourProgram, ...$arguments], '', $workingDirectory);
    $reference = runCommand([...$envCommand, 'lua5.4', ...$arguments], '', $workingDirectory);
    $label = 'lua ' . implode(' ', $arguments) . ' with ' . json_encode($environment);
    assertSame($reference[0], $ours[0], "$label: exit status");
    assertSame($reference[1], $ours[1], "$label: stdout");
    assertSame(str_replace('lua5.4:', 'lua:', $reference[2]), str_replace($ourProgram . ':', 'lua:', $ours[2]), "$label: stderr");
}

function test_paths_come_from_the_environment(): void
{
    $printPaths = ['-e', 'print(package.path) print(package.cpath)'];
    assertSameAsReference($printPaths);
    assertSameAsReference($printPaths, ['LUA_PATH' => 'a/?.lua', 'LUA_CPATH' => 'c/?.so']);
    assertSameAsReference($printPaths, ['LUA_PATH_5_4' => 'versioned/?.lua', 'LUA_PATH' => 'plain/?.lua']);
    assertSameAsReference($printPaths, ['LUA_CPATH_5_4' => 'versioned/?.so', 'LUA_CPATH' => 'plain/?.so']);
    assertSameAsReference($printPaths, ['LUA_PATH' => '']);
    // ";;" is replaced by the default path
    assertSameAsReference($printPaths, ['LUA_PATH' => ';;']);
    assertSameAsReference($printPaths, ['LUA_PATH' => 'first/?.lua;;']);
    assertSameAsReference($printPaths, ['LUA_PATH' => ';;last/?.lua']);
    assertSameAsReference($printPaths, ['LUA_PATH' => 'first/?.lua;;last/?.lua;;x', 'LUA_CPATH_5_4' => 'a;;b']);
    // -E ignores the environment
    assertSameAsReference(['-E', ...$printPaths], ['LUA_PATH' => 'ignored/?.lua', 'LUA_CPATH_5_4' => 'ignored/?.so']);
}

function test_lua_option_l_requires_a_module(): void
{
    $directory = scratchDirectory() . '/package-l';
    @mkdir($directory);
    file_put_contents("$directory/mymod.lua", "print('loading', ...)\nreturn {value = 42}\n");
    file_put_contents("$directory/mymod-v2.lua", "return 'suffixed'\n");
    assertSameAsReference(['-l', 'mymod', '-e', 'print(mymod.value, package.loaded.mymod == mymod)'], [], $directory);
    assertSameAsReference(['-lmymod', '-e', 'print(mymod.value)'], [], $directory);
    assertSameAsReference(['-l', 'alias=mymod', '-e', 'print(alias.value, mymod)'], [], $directory);
    assertSameAsReference(['-l', 'mymod-v2', '-e', 'print(mymod)'], [], $directory);
    assertSameAsReference(['-l', 'nosuchmodule', '-e', 'print(1)'], [], $directory);
}

function test_loadlib_without_dynamic_libraries(): void
{
    $script = 'print(package.loadlib("libdoesnotexist.so", "luaopen_x"))'
        . ' print(package.loadlib("libdoesnotexist.so", "*"))'
        . ' print(pcall(package.loadlib, "x"))';
    $result = runCommand(['php', REPO_ROOT . '/bin/lua', '-e', $script]);
    $dlmsg = 'dynamic libraries not enabled; check your Lua installation';
    assertSame([
        0,
        "nil\t$dlmsg\tabsent\nnil\t$dlmsg\tabsent\nfalse\tbad argument #2 to 'package.loadlib' (string expected, got no value)\n",
        '',
    ], $result);
}

function test_c_searchers_fail_when_they_find_a_library(): void
{
    $directory = scratchDirectory() . '/package-c';
    @mkdir($directory);
    file_put_contents("$directory/clib.so", '');
    $script = 'package.path = "" package.cpath = "./?.so"'
        . ' print(select(2, pcall(require, "clib")))'
        . ' print(select(2, pcall(require, "clib.sub")))';
    $result = runCommand(['php', REPO_ROOT . '/bin/lua', '-e', $script], '', $directory);
    $dlmsg = 'dynamic libraries not enabled; check your Lua installation';
    assertSame([
        0,
        "error loading module 'clib' from file './clib.so':\n\t$dlmsg\n"
            . "error loading module 'clib.sub' from file './clib.so':\n\t$dlmsg\n",
        '',
    ], $result);
}
