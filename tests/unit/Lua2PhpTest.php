<?php

declare(strict_types=1);

namespace Tests\Lua2PhpTest;

/*
 * bin/lua2php: tests/diff is transpiled as one project (so modules/ is
 * precompiled too, and its other files are copied along); `php out.php
 * args`, run from the output directory, must behave like `lua5.4 in.lua
 * args` run from tests/diff (same exit status and stdout; stderr equal up
 * to the program name). The cases that load() chunks run a second time
 * with the load cache warm. A lua2php script keeps the memory_limit PHP
 * was started with; the cases get the 4G bin/lua sets itself. opcache
 * (on in this machine's php.ini) compiles and optimizes every generated
 * and cached file: file_update_protection=0, as the files are younger
 * than its 2 seconds (it would skip them): OPCACHE_ON_NEW_FILES.
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
    // the project's other files (data the cases read) go along, as a deployment would copy them
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($diffDirectory, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!str_ends_with($file->getFilename(), '.lua')) {
            $copy = $outputDirectory . substr($file->getPathname(), \strlen($diffDirectory));
            @mkdir(\dirname($copy), 0777, true);
            assertTrue(copy($file->getPathname(), $copy), "copy $file");
        }
    }
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
                    startProcess(['env', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', ...OPCACHE_ON_NEW_FILES, '-d', 'memory_limit=4G', $outputFile, ...$arguments], $outputDirectory),
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
    $result = runCommand(['env', 'LUAPHP_AUTOLOAD=' . REPO_ROOT . '/src/autoload.php', 'php', ...OPCACHE_ON_NEW_FILES, "$directory/hello.php", 'x'], '', $directory);
    assertSame([0, "hello from\thello.lua\tx\n", ''], $result);
    $result = runCommand(['env', 'LUAPHP_AUTOLOAD=/nonexistent/autoload.php', 'php', ...OPCACHE_ON_NEW_FILES, "$directory/hello.php"], '', $directory);
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

/**
 * A data module of $rows rows ({zip = "00000", city = ..., state = ...}, 52
 * bytes each, every zip a constant of its own: past 131071 constants they
 * need LOADKX) and a main.lua that requires it, in $directory.
 */
function writeDataModule(string $directory, int $rows): void
{
    $cities = ['Fairview', 'Midway', 'Oak Grove', 'Franklin', 'Riverside', 'Centerville', 'Mount Pleasant'];
    $states = ['NY', 'CA', 'TX', 'FL', 'OH', 'PA', 'IL', 'GA'];
    $lines = ["return {\n"];
    for ($i = 0; $i < $rows; $i++) {
        $lines[] = sprintf("  {zip = \"%05d\", city = \"%s\", state = \"%s\"},\n", $i, $cities[$i % 7], $states[$i % 8]);
    }
    $lines[] = "}\n";
    @mkdir($directory, 0777, true);
    file_put_contents("$directory/zips.lua", implode('', $lines));
    file_put_contents("$directory/main.lua", <<<'LUA'
        local zips = require("zips")
        local sum = 0
        for i, row in ipairs(zips) do sum = sum + #row.zip * i + #row.city + #row.state end
        print(#zips, sum, zips[1].zip, zips[#zips].zip, zips[#zips].city, zips[#zips].state)
        LUA);
}

function test_data_modules_transpile_small_and_load_with_opcache_at_small_stacks(): void
{
    // before constructor runs: 227 MB of PHP for 131100 rows, which PHP could
    // not compile, and opcache's optimizer overflowed an 8 MB stack at 15000
    foreach ([30000, 131100] as $rows) {
        $directory = scratchDirectory() . "/data-$rows";
        writeDataModule($directory, $rows);
        [$status, $expected] = runCommand(['lua5.4', 'main.lua'], '', $directory);
        assertSame(0, $status, "lua5.4 runs the $rows-row module");
        // folder mode within PHP's default memory_limit (the cap stops lua2php raising it)
        $result = runCommand(['php', '-d', 'memory_limit=128M', '-d', 'max_memory_limit=128M', REPO_ROOT . '/bin/lua2php', $directory, '-o', "$directory/out"]);
        assertSame([0, '', ''], $result, "lua2php $rows rows");
        // (the guard: nothing is compiled at run time)
        $php = ['php', ...OPCACHE_ON_NEW_FILES, '-d', 'memory_limit=1G', '-d', 'auto_prepend_file=' . REPO_ROOT . '/tests/aot/guard.php', 'main.php'];
        foreach ([8192, 1024] as $stackKilobytes) {
            $result = runCommand(['sh', '-c', "ulimit -s $stackKilobytes && exec \"\$@\"", 'sh', ...$php], '', "$directory/out");
            assertSame([0, $expected, ''], $result, "$rows rows with opcache, stack $stackKilobytes KB");
        }
        $size = filesize("$directory/out/zips.php");
        assertTrue($size < 60 * $rows, "zips.php for $rows rows: $size bytes");
    }
}

function test_directory_mode_transpiles_the_other_files_when_one_fails(): void
{
    $directory = scratchDirectory() . '/lua2php-failures';
    @mkdir("$directory/in/sub", 0777, true);
    @mkdir("$directory/out");
    file_put_contents("$directory/in/a.lua", "print('a')\n");
    file_put_contents("$directory/in/b.lua", "x = = 1\n");
    file_put_contents("$directory/in/sub/c.lua", "print('c')\n");
    file_put_contents("$directory/in/z.lua", "print('z')\n");
    file_put_contents("$directory/out/sub", 'a file where the directory for sub/c.php should go');
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', "$directory/in", '-o', "$directory/out"]);
    assertSame(1, $status, 'lua2php reports the failures');
    assertTrue(str_contains($errorOutput, "b.lua:1: unexpected symbol near '='"), $errorOutput);
    assertTrue(str_contains($errorOutput, "sub/c.lua: cannot create directory $directory/out/sub"), $errorOutput);
    assertSame([0, "a\n", ''], runCommand(['php', ...OPCACHE_ON_NEW_FILES, 'a.php'], '', "$directory/out"));
    assertSame([0, "z\n", ''], runCommand(['php', ...OPCACHE_ON_NEW_FILES, 'z.php'], '', "$directory/out"), 'the file after the failures');
}
