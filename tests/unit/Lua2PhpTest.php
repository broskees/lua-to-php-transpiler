<?php

declare(strict_types=1);

namespace Tests\Lua2PhpTest;

/*
 * bin/lua2php: tests/diff is transpiled as one project (so modules/ is
 * precompiled too); `php out.php args`, run from the output directory,
 * must behave like `lua5.4 in.lua args` run from tests/diff (same exit
 * status and stdout; stderr equal up to the program name). The cases that
 * load() chunks run a second time with the load cache warm.
 */

/**
 * The one expected difference: coroutine_yield_across.lua dofile()s a file
 * it writes at run time. lua2php output loads only files transpiled ahead
 * of time, so there dofile raises that error instead (bin/lua still runs
 * the file, see the differential cases). Returns $stdout with that line
 * put back as lua5.4 prints it, after checking it is exactly the error.
 */
function expectedDifference(string $name, string $stdout): string
{
    if ($name !== 'coroutine_yield_across.lua') {
        return $stdout;
    }
    $pattern = "~^dofile\t\tfalse\tcannot load (/\\S+): not transpiled ahead of time \\(no \\1\\.php\\)$~m";
    assertSame(1, preg_match($pattern, $stdout), "$name: dofile of a file written at run time fails");
    return preg_replace($pattern, "dofile\tin dofile\ttrue\t1\tfrom file", $stdout);
}

function test_transpiled_diff_cases_behave_like_lua(): void
{
    $diffDirectory = REPO_ROOT . '/tests/diff';
    $outputDirectory = scratchDirectory() . '/lua2php';
    $cacheDirectory = scratchDirectory() . '/lua2php-cache';
    mkdir($cacheDirectory, 0700);
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $diffDirectory, '-o', $outputDirectory]);
    assertSame(1, $status, 'modules/syntax_error.lua does not compile');
    assertSame(REPO_ROOT . "/bin/lua2php: modules/syntax_error.lua:1: unexpected symbol near '='\n", $errorOutput);
    $files = glob($diffDirectory . '/*.lua');
    sort($files);
    $arguments = ['first', 'second arg'];
    $warmFiles = array_values(array_filter($files, static fn (string $file): bool => str_contains(file_get_contents($file), 'load')));
    foreach (['cold' => $files, 'warm' => $warmFiles] as $cache => $caseFiles) {
        foreach (array_chunk($caseFiles, 8) as $batch) {
            $running = [];
            foreach ($batch as $file) {
                $name = basename($file);
                $outputFile = $outputDirectory . '/' . basename($name, '.lua') . '.php';
                $running[$name] = [
                    startProcess(['lua5.4', $name, ...$arguments], $diffDirectory),
                    startProcess(['env', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', $outputFile, ...$arguments], $outputDirectory),
                    $outputFile,
                ];
            }
            foreach ($running as $name => [$referenceProcess, $ourProcess, $outputFile]) {
                [$referenceStatus, $referenceStdout, $referenceStderr] = finishProcess($referenceProcess);
                [$ourStatus, $ourStdout, $ourStderr] = finishProcess($ourProcess);
                assertSame($referenceStatus, $ourStatus, "$name ($cache cache): exit status");
                assertSame(
                    normalizeDifferentialOutput($referenceStdout, 'lua5.4'),
                    normalizeDifferentialOutput(expectedDifference($name, $ourStdout), 'lua5.4'),
                    "$name ($cache cache): stdout",
                );
                assertSame(
                    normalizeDifferentialOutput($referenceStderr, 'lua5.4'),
                    normalizeDifferentialOutput($ourStderr, $outputFile),
                    "$name ($cache cache): stderr",
                );
            }
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
