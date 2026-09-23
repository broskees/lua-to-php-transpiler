<?php

declare(strict_types=1);

namespace Tests\Lua2PhpTest;

/*
 * bin/lua2php: every differential case (tests/diff/*.lua) is transpiled to
 * a PHP script; `php out.php args` must behave like `lua5.4 in.lua args`
 * (same exit status and stdout; stderr equal up to the program name).
 */

function test_transpiled_diff_cases_behave_like_lua(): void
{
    $diffDirectory = REPO_ROOT . '/tests/diff';
    $outputDirectory = scratchDirectory() . '/lua2php';
    @mkdir($outputDirectory);
    $files = glob($diffDirectory . '/*.lua');
    sort($files);
    $arguments = ['first', 'second arg'];
    foreach (array_chunk($files, 8) as $batch) {
        $running = [];
        foreach ($batch as $file) {
            $name = basename($file);
            $outputFile = $outputDirectory . '/' . basename($name, '.lua') . '.php';
            [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $name, '-o', $outputFile], '', $diffDirectory);
            assertSame(0, $status, "lua2php $name: $errorOutput");
            $running[$name] = [
                startProcess(['lua5.4', $name, ...$arguments], $diffDirectory),
                startProcess(['php', $outputFile, ...$arguments], $diffDirectory),
                $outputFile,
            ];
        }
        foreach ($running as $name => [$referenceProcess, $ourProcess, $outputFile]) {
            [$referenceStatus, $referenceStdout, $referenceStderr] = finishProcess($referenceProcess);
            [$ourStatus, $ourStdout, $ourStderr] = finishProcess($ourProcess);
            assertSame($referenceStatus, $ourStatus, "$name: exit status");
            assertSame(
                normalizeDifferentialOutput($referenceStdout, 'lua5.4'),
                normalizeDifferentialOutput($ourStdout, 'lua5.4'),
                "$name: stdout",
            );
            assertSame(
                normalizeDifferentialOutput($referenceStderr, 'lua5.4'),
                normalizeDifferentialOutput($ourStderr, $outputFile),
                "$name: stderr",
            );
        }
    }
}

function test_transpiled_script_finds_its_runtime(): void
{
    $directory = scratchDirectory() . '/lua2php-runtime';
    @mkdir($directory);
    file_put_contents("$directory/hello.lua", "print('hello from', arg[0], ...)\n");
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', 'hello.lua'], '', $directory);
    assertSame(0, $status, $errorOutput);
    assertTrue(is_file("$directory/hello.php"), 'default output name is hello.php');
    // LUAPHP_AUTOLOAD overrides the recorded runtime path
    $result = runCommand(['env', 'LUAPHP_AUTOLOAD=' . REPO_ROOT . '/src/autoload.php', 'php', "$directory/hello.php", 'x'], '', $directory);
    assertSame([0, "hello from\thello.lua\tx\n", ''], $result);
    $result = runCommand(['env', 'LUAPHP_AUTOLOAD=/nonexistent/autoload.php', 'php', "$directory/hello.php"], '', $directory);
    assertSame(1, $result[0]);
    assertTrue(str_contains($result[2], 'cannot find the LuaPhp runtime'), $result[2]);
}

function test_syntax_errors_are_reported(): void
{
    $directory = scratchDirectory() . '/lua2php-errors';
    @mkdir($directory);
    file_put_contents("$directory/broken.lua", "x = = 1\n");
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', 'broken.lua'], '', $directory);
    assertSame(1, $status);
    assertTrue(str_contains($errorOutput, "broken.lua:1: unexpected symbol near '='"), $errorOutput);
}
