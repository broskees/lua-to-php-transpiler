<?php

/**
 * The scorer of the speed score run:  php bench/score/score.php [--quick]
 * (run it through bench/score/score.sh: a heavy-run slot, a 3 GB scope).
 *
 * It measures this checkout's src/ and bin/ (the attempt, as they are on
 * disk) against the start (the commit that added this file), both copied
 * read-only to scratch paths of equal length (collector pacing and speed
 * depend on path lengths) and run interleaved, as CPU time (user + system):
 *
 * - execution, weight 2/3: every workload of bench/workloads/speed and
 *   bench/score/workloads, transpiled by each side's bin/lua2php and run as
 *   `php out.php`; the median of the rounds (7, or 3 with --quick) per
 *   workload and side;
 * - transpiling, weight 1/3: each side's bin/lua2php in directory mode on
 *   the 33 official test files (16,597 lines), into a new directory.
 *
 * Every run uses the shared-host profile (score_functions.php sharedHostPhp:
 * no php.ini, opcache with a warm file cache and no JIT, optional
 * extensions' functions and a host's usual list disabled, memory_limit
 * 128M) and its own new TMPDIR. Every run must print what lua5.4 prints,
 * and each workload's peak PHP memory may grow at most 10% + 1 MB over the
 * start's.
 *
 * Unless --quick, the gates follow: the existing tests/diff cases unchanged
 * (new ones may be added), composer lint clean, php tests/run.php green,
 * and all.lua -e"_U=true" printing "final OK !!!" under bin/lua with the
 * optional extensions' functions disabled.
 *
 * The last line is the score: 100 times the weighted geometric mean of the
 * attempt / start ratios; lower is better, 100 is the start's speed. On
 * any failure there is no score: the reason goes to stderr, exit status 1.
 */

declare(strict_types=1);

require __DIR__ . '/score_functions.php';

const EXECUTION_WEIGHT = 2 / 3;
const TRANSPILE_WEIGHT = 1 / 3;
const MEMORY_GROWTH_RATIO = 1.10;
const MEMORY_GROWTH_BYTES = 1024 * 1024;

$quick = in_array('--quick', array_slice($argv, 1), true);
$rounds = $quick ? 3 : 7;
$temporaryDirectory = getenv('TMPDIR');
$scratch = ($temporaryDirectory === false || $temporaryDirectory === '' ? '/var/tmp' : $temporaryDirectory)
    . '/luaphp-score-' . bin2hex(random_bytes(4));
register_shutdown_function(static fn () => removeDirectory($scratch));

try {
    $start = startCommit();
    printf("attempt: %s (src/ and bin/ as on disk)\nstart: %s\n", realpath(CHECKOUT_ROOT), $start);
    if (!$quick) {
        checkDiffCasesKept($start);
    }
    mkdir($scratch, 0700);
    mkdir("$scratch/home");
    $extensionFunctions = extensionFunctions();
    // equal-length paths for both sides
    $trees = ['attempt' => "$scratch/A", 'start' => "$scratch/B"];
    copyTrees($start, $trees['attempt'], $trees['start']);
    $workDirectory = "$scratch/work";
    $workloads = copyWorkloads($workDirectory);
    $expected = [];
    foreach ($workloads as $name) {
        $expected[$name] = luaOutput($workDirectory, $name);
    }
    $transpileInputs = ['transpile official tests' => CHECKOUT_ROOT . '/reference/lua-5.4.9-tests'];

    $php = [];
    $outputDirectories = [];
    foreach ($trees as $side => $tree) {
        $php[$side] = sharedHostPhp($extensionFunctions, "$tree-opcache");
        mkdir("$tree-opcache");
        $outputDirectories[$side] = "$tree-out";
        transpile($php[$side], $tree, $workDirectory, $outputDirectories[$side], $scratch);
        // untimed: fills opcache's file cache and checks the output
        foreach ($workloads as $name) {
            runWorkload($php[$side], "$tree-out/$name.php", $workDirectory, $expected[$name], $scratch);
        }
        foreach ($transpileInputs as $input) {
            transpile($php[$side], $tree, $input, freshDirectory($scratch) . '/out', $scratch);
        }
    }

    $cpuSeconds = [];
    $peakBytes = [];
    for ($round = 0; $round < $rounds; $round++) {
        $order = $round % 2 === 0 ? ['attempt', 'start'] : ['start', 'attempt'];
        foreach ($workloads as $name) {
            foreach ($order as $side) {
                $run = runWorkload(
                    $php[$side],
                    "{$outputDirectories[$side]}/$name.php",
                    $workDirectory,
                    $expected[$name],
                    $scratch,
                );
                $cpuSeconds[$name][$side][] = $run['cpuSeconds'];
                $peakBytes[$name][$side] = max($peakBytes[$name][$side] ?? 0, $run['peakBytes']);
            }
        }
        foreach ($transpileInputs as $input => $directory) {
            foreach ($order as $side) {
                $cpuSeconds[$input][$side][] = transpile(
                    $php[$side],
                    $trees[$side],
                    $directory,
                    freshDirectory($scratch) . '/out',
                    $scratch,
                );
            }
        }
    }

    printf("\n%-28s %10s %10s %7s %10s %10s\n", 'median CPU ms', 'start', 'attempt', 'ratio', 'start MB', 'attempt MB');
    $ratios = ['execution' => [], 'transpile' => []];
    $memoryGrowth = [];
    foreach ($cpuSeconds as $name => $sides) {
        $startMedian = median($sides['start']);
        $attemptMedian = median($sides['attempt']);
        $ratio = $attemptMedian / $startMedian;
        $isTranspile = isset($transpileInputs[$name]);
        $ratios[$isTranspile ? 'transpile' : 'execution'][] = $ratio;
        $startPeak = $peakBytes[$name]['start'] ?? null;
        $attemptPeak = $peakBytes[$name]['attempt'] ?? null;
        printf(
            "%-28s %10.1f %10.1f %7.3f %10s %10s\n",
            $name,
            $startMedian * 1000,
            $attemptMedian * 1000,
            $ratio,
            $startPeak === null ? '' : sprintf('%.1f', $startPeak / 1048576),
            $attemptPeak === null ? '' : sprintf('%.1f', $attemptPeak / 1048576),
        );
        if ($startPeak !== null && $attemptPeak > $startPeak * MEMORY_GROWTH_RATIO + MEMORY_GROWTH_BYTES) {
            $memoryGrowth[] = $name;
        }
    }
    $execution = geometricMean($ratios['execution']);
    $transpiling = geometricMean($ratios['transpile']);
    $score = 100 * $execution ** EXECUTION_WEIGHT * $transpiling ** TRANSPILE_WEIGHT;
    printf(
        "\nexecution %.4f (geometric mean of %d), transpiling %.4f (of %d), %d rounds%s\n",
        $execution,
        count($ratios['execution']),
        $transpiling,
        count($ratios['transpile']),
        $rounds,
        $quick ? ', --quick: no gates' : '',
    );
    if ($memoryGrowth !== []) {
        throw new RuntimeException(
            'peak memory grew more than 10% + 1 MB over the start: ' . implode(', ', $memoryGrowth)
        );
    }
    if (!$quick) {
        runGates($extensionFunctions);
    }
    printf("score (100 = the start, lower is better)\n%.3f\n", $score);
} catch (Throwable $failure) {
    fwrite(STDERR, "\nno score: " . $failure->getMessage() . "\n");
    exit(1);
}
