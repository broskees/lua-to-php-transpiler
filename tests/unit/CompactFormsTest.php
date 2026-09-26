<?php

declare(strict_types=1);

namespace Tests\CompactFormsTest;

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;

/*
 * Compact forms (FunctionEmitter::emitCompact, Runtime\Op): in a chunk too
 * heavy to compile inline (Emitter::INLINE_WEIGHT_MAXIMUM), the code
 * outside loops of its heaviest functions is one Op call per instruction.
 * Each call does exactly what the inline code does; the differential cases
 * run with every instruction that has a compact form in it
 * (compact_forms.php) to show it.
 */

/** makes every instruction that has a compact form compact (compact_forms.php) */
const COMPACT = ['-d', 'auto_prepend_file=' . REPO_ROOT . '/tests/unit/compact_forms.php'];

function test_the_differential_cases_behave_like_lua_in_compact_form(): void
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
                startProcess(['env', '-u', 'LUAPHP_CACHE_DIR', 'php', ...COMPACT, $ourProgram, $name], $diffDirectory),
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

/**
 * Lua source of a function of $lines if-statements, 11 instructions each
 * (3,000 lines: 32,500 instructions, whose inline code PHP needed 146 MB
 * to compile with opcache)
 */
function bigFunction(int $lines): string
{
    $statements = [];
    for ($i = 0; $i < $lines; $i++) {
        $statements[] = sprintf('  if n %% %d == 0 then s = s + %d else s = s - 1 end', $i + 2, $i);
    }
    return "function (n)\n  local s = 0\n" . implode("\n", $statements) . "\n  return s\nend";
}

function test_big_chunks_load_within_phps_default_memory_limit(): void
{
    // before compact forms: hot.php (6.8 MB) needed 146 MB with opcache and
    // failed at 128M, mixed.php (4.1 MB, a table of 3000 rows whose field
    // string.format is no constant) needed 82 MB
    $directory = scratchDirectory() . '/compact-limit';
    @mkdir($directory);
    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $rows[] = "  {id = $i, fmt = string.format},";
    }
    file_put_contents("$directory/hot.lua", 'return ' . bigFunction(3000) . "\n");
    file_put_contents("$directory/mixed.lua", "return {\n" . implode("\n", $rows) . "\n}\n");
    file_put_contents("$directory/script.lua", 'local big = ' . bigFunction(3000) . "\n"
        . "print('script', coroutine.wrap(function () local t = 0 for i = 1, 100 do t = t + big(i) end return t end)())\n");
    $use = "local big, rows = require 'hot', require 'mixed'\n"
        . "local t = 0 for i = 1, 100 do t = t + big(i) end\n"
        . "return t, #rows, rows[#rows].id, rows[1].fmt == string.format\n";
    file_put_contents("$directory/main.lua", "print('main', (function () $use end)())\n");
    file_put_contents("$directory/coroutine.lua", "print('coroutine', coroutine.wrap(function () $use end)())\n");
    assertSame([0, '', ''], runCommand(['php', REPO_ROOT . '/bin/lua2php', $directory, '-o', "$directory/out"]));
    foreach (['script', 'main', 'coroutine'] as $name) {
        $expected = runCommand(['lua5.4', "$name.lua"], '', $directory);
        assertSame(0, $expected[0], "lua5.4 $name.lua");
        $variants = [
            'off' => ['-d', 'opcache.enable_cli=0'],
            'on' => OPCACHE_ON_NEW_FILES,
            'on with the tracing JIT' => [...OPCACHE_ON_NEW_FILES, '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=64M'],
        ];
        foreach ($variants as $opcache => $options) {
            $result = runCommand(['php', ...$options, '-d', 'memory_limit=128M', "$name.php"], '', "$directory/out");
            assertSame($expected, $result, "$name.php, opcache $opcache, memory_limit 128M");
        }
    }
    $size = filesize("$directory/out/hot.php");
    assertTrue($size < 3_000_000, "hot.php: $size bytes");
}

function test_the_code_outside_loops_of_the_heaviest_functions_is_compact(): void
{
    $small = 'local function small(n) local s = 0; if n > 1 then s = n end; return s end';
    $withLoop = "local function withLoop(n)\n  local s = 0\n  for i = 1, n do s = s + i * 2 end\n"
        . str_repeat("  s = s + n\n", 3000) . "  return s\nend";
    $withoutLoop = 'local big = ' . bigFunction(500);
    $chunk = "$small\n$withLoop\n$withoutLoop\nreturn small(1) + withLoop(2) + big(3)\n";
    $code = Emitter::emitChunk(Compiler::compile($chunk, '=chunk'));
    $functions = preg_split('/^    \$function_[\d_]+ = static function/m', $code);
    [, $smallCode, $withLoopCode, $bigCode, $mainCode] = $functions;
    assertTrue(!str_contains($smallCode, 'Op::'), 'a light function stays inline');
    assertTrue(!str_contains($mainCode, 'Op::'), 'so does the main function, once the rest fits');
    assertTrue(substr_count($bigCode, 'Op::') > 1500, 'a heavy function is compact');
    assertTrue(str_contains($bigCode, "if (Op::eqK(\$L, "), 'tests too');
    // the loop of the heaviest stays inline, the code around it is compact
    assertTrue(preg_match('/FORPREP.*?\n(.*?)FORLOOP/s', $withLoopCode, $match) === 1);
    assertTrue(!str_contains($match[1], 'Op::') && str_contains($match[1], '$v = $x * 2;'), 'the loop body is inline');
    assertTrue(substr_count($withLoopCode, 'Op::arith($L, ') === 3000, 'the code outside the loop is compact');
    // PHP's tracing JIT does not trace compact functions (see FunctionEmitter::emit)
    assertSame(2, substr_count($code, 'opcache_jit_blacklist('), 'the compact functions are kept from the JIT');
    assertTrue(str_contains($withLoopCode, 'opcache_jit_blacklist($function_0_1)') && str_contains($bigCode, 'opcache_jit_blacklist($function_0_2)'));
    // a lighter chunk is all inline
    $light = str_replace(str_repeat("  s = s + n\n", 3000), str_repeat("  s = s + n\n", 1000), "$small\n$withLoop\nreturn withLoop(2)");
    assertTrue(!str_contains(Emitter::emitChunk(Compiler::compile($light, '=light')), 'Op::'), 'a light chunk stays inline');
}
