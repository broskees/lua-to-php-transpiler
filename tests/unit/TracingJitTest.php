<?php

declare(strict_types=1);

namespace Tests\TracingJitTest;

use Tests\TracingJit\Canaries\CanaryWeights;

use function Tests\TracingJit\detectorOptions;
use function Tests\TracingJit\jitOptions;
use function Tests\TracingJit\loopTraceVerdicts;

use const Tests\TracingJit\JIT_DEBUG;

/*
 * PHP's tracing JIT bug php-src#24186: under opcache.jit=tracing (1254), a
 * VM interrupt that ends a loop trace through the interrupt stub makes the
 * loop run again from stale values (see tests/jit/detector.php). The
 * detector must flag the canary loops that go wrong and pass the ones that
 * do not; the interrupt harness (tests/jit/interrupts.php, a regression
 * test harness for the bug: SIGUSR1 at least 1,000 times a second) shows
 * which ones really go wrong. tests/jit/inventory.php runs the detector
 * over our own code (tests/jit/tracing-jit-inventory.md). tests/run.php
 * loads the detector.
 */

const CANARIES = REPO_ROOT . '/tests/jit/canaries';
const INTERRUPTS = REPO_ROOT . '/tests/jit/interrupts.php';

/**
 * The detector's verdicts on the loop traces of a canary's loop (its
 * closure, or the function $loopFunction in canaries/$loopFile), from a
 * run without interrupts under the detector's options with
 * opcache.jit=$mode
 *
 * @return list<array{flagged: bool, interruptCheck: bool, snapshot: bool, registers: list<string>,
 *     operations: list<string>}>
 */
function canaryLoopVerdicts(string $canary, string $mode, ?string $loopFile, string $loopFunction): array
{
    $file = realpath(CANARIES . "/$canary");
    [$status, $stdout, $dump] = runCommand(['php', ...detectorOptions($mode), $file]);
    assertSame([0, "0\n"], [$status, $stdout], "$canary under opcache.jit=$mode without interrupts");
    return loopVerdictsOf($loopFile === null ? $file : realpath(CANARIES . "/$loopFile"), $dump, $loopFunction);
}

/**
 * the verdicts on the loop traces of the function $function (or the
 * closures) in $file, from the jit_debug report $dump
 */
function loopVerdictsOf(string $file, string $dump, string $function = '{closure'): array
{
    $dumpFile = scratchDirectory() . '/tracing-jit.dump';
    file_put_contents($dumpFile, $dump);
    $verdicts = [];
    foreach (loopTraceVerdicts($dumpFile) as ['trace' => $trace, 'verdict' => $verdict]) {
        if ($trace['file'] === $file && str_starts_with($trace['function'], $function)) {
            $verdicts[] = $verdict;
        }
    }
    return $verdicts;
}

function test_the_detector_tells_the_loops_that_go_wrong_from_those_that_do_not(): void
{
    // canary, opcache.jit, flagged, the operation that causes it (the first use of the IP the trace keeps),
    // and where the loop is if not in the canary's closure
    $cases = [
        'a' => ['coalesce.php', '1254', true, 'COALESCE at \\d{4} \\(VM handler call\\)'],
        'b' => ['jmpset.php', '1254', true, 'JMP_SET at \\d{4} \\(VM handler call\\)'],
        'c' => ['plain_fetch.php', '1254', false, null],
        'd' => ['index.php', '1254', false, null],
        'e' => ['coalesce.php', '1054', false, null],
        // php-src#24186 without ?? or ?:
        'f' => ['foreach_counter.php', '1254', true, 'FE_FETCH_R at \\d{4} \\(EX\\(opline\\) from the IP\\)'],
        // the operation in a function the trace inlines, at an address no check places (search mode)
        'g' => [
            'inlined_class_constant.php', '1254', false,
            preg_quote('FETCH_CLASS_CONSTANT at 0001 in ' . CanaryWeights::class . '::weight (VM handler call)', '/'),
            'CanaryWeights.php', CanaryWeights::class . '::total',
        ],
    ];
    foreach ($cases as $letter => [$canary, $mode, $flagged, $operation]) {
        $label = "($letter) $canary under opcache.jit=$mode";
        $verdicts = canaryLoopVerdicts($canary, $mode, $cases[$letter][4] ?? null, $cases[$letter][5] ?? '{closure');
        // examined: compiled as a loop trace with its interrupt check found
        assertTrue($verdicts !== [], "$label: no loop trace of its loop was compiled");
        foreach ($verdicts as $verdict) {
            assertTrue($verdict['interruptCheck'], "$label: the loop trace's interrupt check was not found");
            assertSame($flagged, $verdict['flagged'], "$label: flagged");
            if ($operation !== null) {
                assertTrue(
                    preg_match("/^$operation$/", $verdict['operations'][0] ?? '') === 1,
                    "$label: the operation: " . implode('; ', $verdict['operations']),
                );
            }
        }
    }
}

/**
 * The calls of 1,000,000 whose array was wrong, in a run of $canary under
 * the interrupt harness with opcache.jit=$mode; fails unless the harness
 * sent at least 1,000 signals a second and the JIT compiled the canary's
 * loop as a loop trace (so a run without wrong calls proves something).
 */
function interruptedRun(string $canary, string $mode): int
{
    $file = realpath(CANARIES . "/$canary");
    $report = scratchDirectory() . '/tracing-jit-interrupts';
    @unlink($report);
    [$status, $stdout, $dump] = runCommand([
        'env', "JIT_INTERRUPTS_REPORT=$report",
        'php', ...jitOptions($mode), '-d', 'opcache.jit_debug=' . JIT_DEBUG, '-d', 'auto_prepend_file=' . INTERRUPTS,
        $file,
    ]);
    $label = "$canary under opcache.jit=$mode with interrupts";
    assertTrue($status === 0 && preg_match('/^\d+\n$/', $stdout) === 1, "$label: status $status, stdout $stdout");
    assertTrue(is_file($report), "$label: the harness wrote no report");
    [$signals, $seconds] = sscanf(file_get_contents($report), '%d %f');
    assertTrue($signals >= 1000 * $seconds, "$label: $signals signals in $seconds seconds");
    assertTrue(loopVerdictsOf($file, $dump) !== [], "$label: no loop trace of its loop was compiled");
    return (int) $stdout;
}

function test_under_interrupts_the_flagged_canaries_return_wrong_arrays(): void
{
    // php-src#24186: canaries (a) and (f) under tracing with register allocation
    foreach (['coalesce.php', 'foreach_counter.php'] as $canary) {
        $runs = [];
        do {
            $runs[] = interruptedRun($canary, '1254');
        } while (max($runs) === 0 && \count($runs) < 10);
        assertTrue(max($runs) > 0, "$canary under 1254 with interrupts: wrong calls per run " . implode(', ', $runs));
    }
}

function test_under_interrupts_the_unflagged_canaries_stay_right(): void
{
    // canary (a) without register allocation, canary (c) with it
    foreach ([['coalesce.php', '1054'], ['plain_fetch.php', '1254']] as [$canary, $mode]) {
        for ($run = 1; $run <= 10; $run++) {
            assertSame(0, interruptedRun($canary, $mode), "$canary under opcache.jit=$mode, run $run: wrong calls");
        }
    }
}
