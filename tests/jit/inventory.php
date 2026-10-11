<?php

/**
 * The tracing-JIT inventory: every loop trace that PHP's tracing JIT
 * compiles in our code and that the JIT bug php-src#24186 can reach (see
 * tests/jit/detector.php), over the whole inventory input set
 * (tests/jit/inventory_inputs.php).
 *
 *   php tests/jit/inventory.php [opcache.jit mode, default 1254] [class ...]
 *
 * Classes: i ii iii iv v (default: all). Each input runs once per
 * hot-counter profile (HOT_COUNTER_PROFILES: PHP's defaults, and eager).
 * Prints, as markdown: the inputs run (and the count the tree calls for),
 * the loop traces examined (in all, per class and per profile) and every
 * flagged place: the PHP file and line of the loop (for emitted code the
 * Lua file, the emitted function and the pc), the operation that caused it
 * and how often it was flagged. tests/jit/tracing-jit-inventory.md holds
 * its output for 1254.
 *
 * A heavy run (about 750 PHP processes, 4 at a time, about 11 minutes):
 * run it inside the memory cap and the heavy-run lock like any full test
 * run.
 */

declare(strict_types=1);

namespace Tests\TracingJit;

require __DIR__ . '/../harness.php';
require __DIR__ . '/../../src/autoload.php';
require __DIR__ . '/detector.php';
require __DIR__ . '/inventory_inputs.php';

const PARALLEL_INPUTS = 4;

$mode = $argv[1] ?? '1254';
$classes = \array_slice($argv, 2) ?: array_keys(INPUT_CLASSES);
$profiles = array_keys(HOT_COUNTER_PROFILES);

$startTime = microtime(true);
$results = [];
foreach ($profiles as $profile) {
    // the inputs transpiled anew, with empty load() caches, for each profile
    $profileDirectory = scratchDirectory() . "/$profile";
    mkdir($profileDirectory);
    $inputs = inventoryInputs($classes, $profileDirectory);
    foreach (examineInputs($inputs, $mode, $profile, PARALLEL_INPUTS, $profileDirectory) as $result) {
        $results[] = $result + ['profile' => $profile];
    }
}

$diffCount = \count(glob(REPO_ROOT . '/tests/diff/*.lua'));
$speedCount = \count(glob(REPO_ROOT . '/bench/workloads/speed/*.lua'));
$officialCount = \count(officialTestFiles()) - 1;
$expectedCounts = ['i' => $diffCount, 'ii' => $diffCount, 'iii' => $speedCount, 'iv' => $officialCount,
    'v' => $diffCount];
$inputClasses = [];
$loopTraceCounts = array_fill_keys($classes, 0);
$profileLoopTraceCounts = array_fill_keys($profiles, 0);
$withoutInterruptCheck = 0;
$statuses = [];
$failedInputs = [];
$places = [];
$flaggedWithoutOpline = 0;
$flaggedWithUnplacedUses = 0;
foreach ($results as $result) {
    $class = $result['input']['class'];
    $profile = $result['profile'];
    $input = "($class) {$result['input']['name']}";
    $inputClasses[$input] = $class;
    $loopTraceCounts[$class] += $result['loopTraces'];
    $profileLoopTraceCounts[$profile] += $result['loopTraces'];
    $withoutInterruptCheck += $result['withoutInterruptCheck'];
    $statuses[$result['status']] = ($statuses[$result['status']] ?? 0) + 1;
    if ($result['status'] !== 0) {
        $failedInputs[$input][] = "{$result['status']} ($profile)";
    }
    foreach ($result['flagged'] as $flagged) {
        $operations = array_map(withDefinitions(...), $flagged['verdict']['operations']);
        $flaggedWithoutOpline += preg_match('/ at \d{4}\b/', $operations[0] ?? '') === 1 ? 0 : 1;
        $flaggedWithUnplacedUses += $flagged['verdict']['unplacedUses'] > 0 ? 1 : 0;
        $operation = $operations[0] ?? '(no use of the IP found)';
        $key = $flagged['place'] . "\n" . $operation;
        $places[$key] ??= ['place' => $flagged['place'], 'operation' => $operation, 'laterUses' => [],
            'registers' => [], 'traces' => array_fill_keys($classes, 0), 'profiles' => array_fill_keys($profiles, 0),
            'inputs' => [], 'tracesWithUnplacedUses' => 0];
        $places[$key]['tracesWithUnplacedUses'] += $flagged['verdict']['unplacedUses'] > 0 ? 1 : 0;
        $places[$key]['traces'][$class]++;
        $places[$key]['profiles'][$profile]++;
        $places[$key]['inputs'][$input] = true;
        foreach (\array_slice($operations, 1) as $laterUse) {
            // without their oplines, which vary with the trace's path
            $places[$key]['laterUses'][preg_replace('/ at [\d, ]+(?= in | \()/', '', $laterUse)] = true;
        }
        foreach ($flagged['verdict']['registers'] as $register) {
            $places[$key]['registers'][$register] = true;
        }
    }
}
ksort($places);
ksort($statuses);

$inputCounts = array_count_values($inputClasses);
$expectedTotal = array_sum(array_intersect_key($expectedCounts, array_flip($classes)));
$flaggedTotal = array_sum(array_map(static fn (array $place): int => array_sum($place['traces']), $places));
$counts = static fn (array $countsByName): string => implode(', ', array_map(
    static fn (string $name, int $count): string => "($name) $count",
    array_keys($countsByName),
    $countsByName,
));
echo "# Tracing-JIT inventory, opcache.jit=$mode\n\n";
foreach ($profiles as $profile) {
    echo "php options of the runs of profile $profile: `" . implode(' ', detectorOptions($mode, $profile)) . "`\n\n";
}
printf(
    "- inputs run: %d (the tree's count: %s = %d), each once per hot-counter profile (%s): %d runs\n",
    \count($inputClasses),
    implode(' + ', array_map(static fn (string $class): string => "$expectedCounts[$class] ($class)", $classes)),
    $expectedTotal,
    implode(', ', $profiles),
    \count($results),
);
foreach ($classes as $class) {
    printf("  - (%s) %s: %d\n", $class, INPUT_CLASSES[$class], $inputCounts[$class] ?? 0);
}
echo '- exit statuses of the runs: ' . implode(', ', array_map(
    static fn (int $status, int $count): string => "$count with $status",
    array_keys($statuses),
    $statuses,
)) . "\n";
echo '- inputs with another exit status than 0 (most diff cases that end in an error expect it): '
    . (implode(', ', array_map(
        static fn (string $input, array $runs): string => "$input: " . implode(', ', $runs),
        array_keys($failedInputs),
        $failedInputs,
    )) ?: 'none') . "\n";
printf("- loop traces examined: %d\n", array_sum($loopTraceCounts));
foreach ($classes as $class) {
    printf("  - (%s) %s: %d\n", $class, INPUT_CLASSES[$class], $loopTraceCounts[$class]);
}
foreach ($profiles as $profile) {
    printf("  - profile %s: %d\n", $profile, $profileLoopTraceCounts[$profile]);
}
printf("- loop traces without an interrupt check (never flagged): %d\n", $withoutInterruptCheck);
printf("- flagged traces: %d, at %d places\n", $flaggedTotal, \count($places));
printf("  - whose operation names no opline: %d\n", $flaggedWithoutOpline);
printf("  - with a use of the IP not placed (the operation could be another): %d\n", $flaggedWithUnplacedUses);
printf("- run time: %.0f s\n", microtime(true) - $startTime);

echo "\n## Flagged places\n";
foreach ($places as $place) {
    $inputNames = array_keys($place['inputs']);
    sort($inputNames);
    $laterUses = array_keys($place['laterUses']);
    sort($laterUses);
    $registers = array_keys($place['registers']);
    sort($registers);
    echo "\n- {$place['place']}\n";
    echo "  - operation: {$place['operation']}\n";
    if ($place['tracesWithUnplacedUses'] > 0) {
        // the operation is the first use placed; one not placed may come before it
        echo "  - traces with uses of the IP not placed (the operation may be another): "
            . "{$place['tracesWithUnplacedUses']}\n";
    }
    echo '  - later uses of the IP in its traces: ' . (implode('; ', $laterUses) ?: 'none') . "\n";
    echo '  - registers: ' . implode(', ', $registers) . "\n";
    printf(
        "  - traces: %d (%s; profiles %s) in %d inputs, the first %s\n",
        array_sum($place['traces']),
        $counts($place['traces']),
        $counts($place['profiles']),
        \count($inputNames),
        $inputNames[0],
    );
}
