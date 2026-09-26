<?php

declare(strict_types=1);

namespace Tests\SegmentsTest;

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Emitter\FunctionEmitter;

/*
 * opcache's optimizer and the JIT walk a function's control flow graph
 * with a recursive depth-first search (Zend/Optimizer/zend_cfg.c:
 * compute_postnum_recursive), about 40 bytes of C stack per basic block
 * on its deepest path. So big functions are emitted as segments under a
 * dispatcher (FunctionEmitter::planSegments) that no path of the graph
 * crosses: the search never goes deeper than one segment, and PHP compiles
 * and JITs them even on a coroutine's 256 KB fiber stack. Before, a module
 * of 3,000 rows {id = i, fmt = string.format} crashed PHP (segfault) when
 * required in a coroutine, at any stack size, and at a 1 MB stack in the
 * main thread.
 */

/** makes every function segments of one instruction (tiny_segments.php) */
const TINY_SEGMENTS = ['-d', 'auto_prepend_file=' . REPO_ROOT . '/tests/unit/tiny_segments.php'];

/** Lua source of a table of $rows rows {id = i, fmt = string.format}: 7 instructions a row, no constant constructor */
function mixedRows(int $rows): string
{
    $lines = [];
    for ($i = 1; $i <= $rows; $i++) {
        $lines[] = "  {id = $i, fmt = string.format},";
    }
    return "{\n" . implode("\n", $lines) . "\n}";
}

/** [status, stdout, stderr] of $command run with $stackKilobytes of C stack */
function runWithStack(int $stackKilobytes, array $command, string $workingDirectory): array
{
    return runCommand(['sh', '-c', "ulimit -s $stackKilobytes && exec \"\$@\"", 'sh', ...$command], '', $workingDirectory);
}

function test_a_big_module_loads_in_a_coroutine_and_at_a_small_stack(): void
{
    $directory = scratchDirectory() . '/segments-module';
    @mkdir($directory);
    file_put_contents("$directory/mixed.lua", 'return ' . mixedRows(3000) . "\n");
    file_put_contents("$directory/main.lua", "local rows = require 'mixed'\nprint(#rows, rows[1].id, rows[#rows].id, rows[#rows].fmt == string.format)\n");
    file_put_contents("$directory/coroutine.lua", "print(coroutine.wrap(function () local rows = require 'mixed'; return #rows, rows[#rows].id end)())\n");
    assertSame([0, '', ''], runCommand(['php', REPO_ROOT . '/bin/lua2php', $directory, '-o', "$directory/out"]));
    $php = ['php', ...OPCACHE_ON_NEW_FILES];
    assertSame([0, "3000\t1\t3000\ttrue\n", ''], runCommand(['lua5.4', 'main.lua'], '', $directory));
    assertSame([0, "3000\t1\t3000\ttrue\n", ''], runWithStack(1024, [...$php, 'main.php'], "$directory/out"), 'main thread, 1 MB stack');
    assertSame([0, "3000\t3000\n", ''], runCommand(['lua5.4', 'coroutine.lua'], '', $directory));
    assertSame([0, "3000\t3000\n", ''], runWithStack(8192, [...$php, 'coroutine.php'], "$directory/out"), 'in a coroutine');
}

function test_load_in_a_coroutine_from_the_warm_disk_cache(): void
{
    $directory = scratchDirectory() . '/segments-load';
    @mkdir($directory);
    @mkdir("$directory/cache", 0700);
    $source = "local source = [==[return " . mixedRows(3000) . "]==]\n"
        . "print(coroutine.wrap(function () local rows = load(source)(); return #rows, rows[#rows].id end)())\n";
    file_put_contents("$directory/load.lua", $source);
    assertSame([0, "3000\t3000\n", ''], runCommand(['lua5.4', 'load.lua'], '', $directory));
    assertSame([0, '', ''], runCommand(['php', REPO_ROOT . '/bin/lua2php', 'load.lua'], '', $directory));
    // the warm run includes the chunk's PHP from the disk cache, which opcache optimizes, in the coroutine
    foreach (['cold', 'warm'] as $cache) {
        $result = runCommand(['env', "LUAPHP_CACHE_DIR=$directory/cache", 'php', ...OPCACHE_ON_NEW_FILES, 'load.php'], '', $directory);
        assertSame([0, "3000\t3000\n", ''], $result, "$cache cache");
    }
}

function test_a_hot_big_function_in_a_coroutine_with_the_tracing_jit(): void
{
    // the tracing JIT builds the control flow graph of a function it compiles
    // (ext/opcache/jit/zend_jit.c: zend_jit_build_cfg), here on the fiber's
    // stack: 3,500 instructions of inline code (in a heavier chunk they would
    // be compact, and the JIT kept from the function: see CompactFormsTest)
    $directory = scratchDirectory() . '/segments-jit';
    @mkdir($directory);
    $source = 'local function make() return ' . mixedRows(500) . " end\n"
        . "print(coroutine.wrap(function () local n = 0; for i = 1, 300 do n = n + #make() end; return n end)())\n";
    file_put_contents("$directory/jit.lua", $source);
    assertSame([0, "150000\n", ''], runCommand(['lua5.4', 'jit.lua'], '', $directory));
    assertSame([0, '', ''], runCommand(['php', REPO_ROOT . '/bin/lua2php', 'jit.lua'], '', $directory));
    assertTrue(!str_contains(file_get_contents("$directory/jit.php"), 'Op::'), 'inline code');
    $jit = ['-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=64M'];
    assertSame([0, "150000\n", ''], runCommand(['php', ...OPCACHE_ON_NEW_FILES, ...$jit, 'jit.php'], '', $directory));
}

/**
 * The segments of the emitted function $functionCode (FunctionEmitter::emit):
 * the code between the lines that pass on to the next segment.
 *
 * @return list<string>
 */
function segmentsOf(string $functionCode): array
{
    return preg_split("/^        \\\$entry = 'L\\d+'; continue 2;\n/m", $functionCode);
}

function test_big_functions_are_segments_that_no_goto_leaves(): void
{
    $small = Compiler::compile("local t = {}\nfor i = 1, 10 do t[i] = i * 2 end\nreturn t\n", '=small');
    $code = (new FunctionEmitter($small, '0'))->emit();
    assertTrue(!str_contains($code, 'switch ($entry)'), 'a small function is one piece of code');

    $statements = [];
    for ($i = 0; $i < 400; $i++) {
        $statements[] = "  if x > $i then x = x - $i else x = x + g(x, $i) end";
    }
    $source = "local x, g = ...\nfor i = 1, 3 do\n" . implode("\n", $statements) . "\nend\nreturn x\n";
    $big = Compiler::compile($source, '=big');
    $code = (new FunctionEmitter($big, '0'))->emit();
    assertTrue(str_contains($code, 'while (true) { switch ($entry) {'), 'a big function is segments');
    $segments = segmentsOf($code);
    foreach ($segments as $index => $segment) {
        $instructions = preg_match_all('/^        \/\/ \[\d+\] /m', $segment);
        assertTrue($instructions <= FunctionEmitter::$maximumSegmentWeight, "segment $index: $instructions instructions");
        preg_match_all('/goto ([LT]\d+);/', $segment, $gotos);
        foreach ($gotos[1] as $label) {
            assertTrue(preg_match("/^    $label:\$/m", $segment) === 1, "segment $index: goto $label stays in it");
        }
    }
    // every jump to another segment has its entry
    preg_match_all("/\\\$entry = '([LT]\\d+)'; continue 2;/", $code, $entries);
    foreach (array_unique($entries[1]) as $label) {
        assertSame(1, substr_count($code, "case '$label':\n"), "entry $label");
    }
    // and the code runs
    $result = runCommand(['php', REPO_ROOT . '/bin/lua', '-e', 'local f = load(io.read("a")); print(f(5, function (a, b) return a % 7 + b end))'], $source);
    $expected = runCommand(['lua5.4', '-e', 'local f = load(io.read("a")); print(f(5, function (a, b) return a % 7 + b end))'], $source);
    assertSame($expected, $result);
}

function test_the_differential_cases_behave_like_lua_in_tiny_segments(): void
{
    $diffDirectory = REPO_ROOT . '/tests/diff';
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $files = glob("$diffDirectory/*.lua");
    sort($files);
    $failures = [];
    foreach (array_chunk($files, 8) as $batch) {
        $running = [];
        foreach ($batch as $file) {
            $name = basename($file);
            $running[$name] = [
                startProcess(['lua5.4', $name], $diffDirectory),
                startProcess(['env', '-u', 'LUAPHP_CACHE_DIR', 'php', ...TINY_SEGMENTS, $ourProgram, $name], $diffDirectory),
            ];
        }
        foreach ($running as $name => [$referenceProcess, $ourProcess]) {
            [$referenceStatus, $referenceStdout, $referenceStderr] = finishProcess($referenceProcess);
            [$ourStatus, $ourStdout, $ourStderr] = finishProcess($ourProcess);
            if ($referenceStatus !== $ourStatus) {
                $failures[] = "$name: exit status $ourStatus, expected $referenceStatus";
            } elseif (normalizeDifferentialOutput($referenceStdout, 'lua5.4') !== normalizeDifferentialOutput($ourStdout, 'lua5.4')) {
                $failures[] = "$name: stdout" . firstDifference(normalizeDifferentialOutput($referenceStdout, 'lua5.4'), normalizeDifferentialOutput($ourStdout, 'lua5.4'));
            } elseif (normalizeDifferentialOutput($referenceStderr, 'lua5.4') !== normalizeDifferentialOutput($ourStderr, $ourProgram)) {
                $failures[] = "$name: stderr" . firstDifference(normalizeDifferentialOutput($referenceStderr, 'lua5.4'), normalizeDifferentialOutput($ourStderr, $ourProgram));
            }
        }
    }
    assertSame([], $failures);
}
