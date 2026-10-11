<?php

declare(strict_types=1);

namespace Tests\TracingJitImmunityTest;

use function Tests\TracingJit\detectorOptions;
use function Tests\TracingJit\examineInputs;
use function Tests\TracingJit\inventoryInputs;
use function Tests\TracingJit\jitOptions;
use function Tests\TracingJit\loopTraceVerdicts;
use function Tests\TracingJit\withDefinitions;

use const Tests\TracingJit\HOT_COUNTER_PROFILES;

/*
 * Our PHP is immune to PHP's tracing JIT bug php-src#24186 (see AGENTS.md,
 * Loops and the tracing JIT): no loop trace the JIT compiles in it can
 * leave the loop at a VM interrupt without writing back the values it holds
 * in registers ("flagged" by tests/jit/detector.php), and the workloads
 * that printed wrong results under interrupts before (phase 1's inventory,
 * tests/jit/tracing-jit-inventory.md) print lua5.4's. tests/run.php loads
 * the detector and the inventory's inputs (tests/jit/inventory_inputs.php).
 */

const INVENTORY = REPO_ROOT . '/tests/jit/tracing-jit-inventory.md';
/** the interrupt harness, after compiling our runtime and the program (see the file) */
const INTERRUPTS = REPO_ROOT . '/tests/jit/interrupts_after_compiling.php';

/** the input classes of tests/jit/inventory_inputs.php the test runs: lua2php output, plain and compact */
const CHECKED_INPUT_CLASSES = ['i', 'iii', 'v'];

/** PHP processes at a time (the machine's tests run 8 differential cases at a time) */
const PARALLEL_INPUTS = 8;

/** the speed workloads whose saved lua2php PHP printed wrong results under the tracing JIT with interrupts */
const WORKLOADS_THAT_WENT_WRONG = ['strings', 'string_library', 'table_library'];

const RUNS_PER_WORKLOAD = 10;

/**
 * The loop traces phase 1's inventory examined per input class, from its
 * "loop traces examined" list: ['i' => 5399, 'ii' => 8370, ...]
 *
 * @return array<string, int>
 */
function phaseOneLoopTraceCounts(): array
{
    $inventory = file_get_contents(INVENTORY);
    $found = preg_match('/^- loop traces examined: \d+\n((?:  - .*\n)+)/m', $inventory, $list);
    assertTrue($found === 1, 'no list of loop traces examined in ' . INVENTORY);
    preg_match_all('/^  - \((\w+)\) [^:\n]+: (\d+)$/m', $list[1], $matches, PREG_SET_ORDER);
    $counts = [];
    foreach ($matches as [, $class, $count]) {
        $counts[$class] = (int) $count;
    }
    return $counts;
}

function test_no_loop_trace_of_our_code_can_lose_values_at_an_interrupt(): void
{
    // as tests/jit/inventory.php runs them: each input once per hot-counter profile, transpiled anew
    // for each, with empty load() caches
    $examined = 0;
    $flagged = [];
    foreach (array_keys(HOT_COUNTER_PROFILES) as $profile) {
        $directory = scratchDirectory() . "/immunity-$profile";
        mkdir($directory);
        $inputs = inventoryInputs(CHECKED_INPUT_CLASSES, $directory);
        foreach (examineInputs($inputs, '1254', $profile, PARALLEL_INPUTS, $directory) as $result) {
            $examined += $result['loopTraces'];
            foreach ($result['flagged'] as ['place' => $place, 'verdict' => $verdict]) {
                $operations = array_map(withDefinitions(...), \array_slice($verdict['operations'], 0, 3));
                $flagged[] = "({$result['input']['class']}) {$result['input']['name']} ($profile): $place: "
                    . implode('; ', $operations);
            }
        }
    }
    assertSame([], $flagged, 'flagged loop traces under opcache.jit=1254');
    // and the JIT still compiles our loops as it did (no code was kept from it)
    $phaseOneCount = array_sum(array_intersect_key(phaseOneLoopTraceCounts(), array_flip(CHECKED_INPUT_CLASSES)));
    assertTrue(
        $examined >= 0.8 * $phaseOneCount,
        "loop traces examined: $examined, 80% of phase 1's $phaseOneCount for the same inputs: " . 0.8 * $phaseOneCount,
    );
}

function test_the_workloads_that_went_wrong_stay_right_under_interrupts(): void
{
    foreach (WORKLOADS_THAT_WENT_WRONG as $workload) {
        $luaFile = realpath(REPO_ROOT . "/bench/workloads/speed/$workload.lua");
        $phpFile = scratchDirectory() . "/$workload.php";
        [$status, , $errors] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $luaFile, '-o', $phpFile]);
        assertSame([0, ''], [$status, $errors], "bin/lua2php $workload.lua");
        [$status, $expected] = runCommand(['lua5.4', $luaFile]);
        assertSame(0, $status, "lua5.4 $workload.lua");

        // the tracing JIT compiles loops of our code in it (a run without interrupts, with its report)
        [$status, $stdout, $dump] = runCommand(['php', ...detectorOptions('1254'), $phpFile]);
        assertSame([0, $expected], [$status, $stdout], "$workload under opcache.jit=1254 without interrupts");
        $dumpFile = scratchDirectory() . "/$workload.dump";
        file_put_contents($dumpFile, $dump);
        $ourLoopTraces = 0;
        foreach (loopTraceVerdicts($dumpFile) as ['trace' => $trace]) {
            $ourLoopTraces += str_starts_with($trace['file'], realpath(REPO_ROOT . '/src') . '/') ? 1 : 0;
        }
        assertTrue($ourLoopTraces > 0, "$workload: the JIT compiled no loop trace in src/");

        for ($run = 1; $run <= RUNS_PER_WORKLOAD; $run++) {
            $label = "$workload under opcache.jit=1254 with interrupts, run $run";
            $report = scratchDirectory() . "/$workload-interrupts";
            @unlink($report);
            [$status, $stdout] = runCommand([
                'env', "JIT_INTERRUPTS_REPORT=$report",
                'php', ...jitOptions('1254'), '-d', 'auto_prepend_file=' . INTERRUPTS, $phpFile,
            ]);
            assertSame([0, $expected], [$status, $stdout], $label);
            assertTrue(is_file($report), "$label: the harness wrote no report");
            [$signals, $seconds] = sscanf(file_get_contents($report), '%d %f');
            assertTrue($signals >= 1000 * $seconds, "$label: $signals signals in $seconds seconds");
        }
    }
}
