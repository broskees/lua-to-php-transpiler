<?php

/**
 * Test runner:  php tests/run.php [substring-filter]
 *
 * Runs the unit tests below, then the differential cases (tests/diff/*.lua,
 * see the end of this file). The filter matches "UnitFile::test_name" or
 * "diff/case.lua".
 *
 * Convention:
 * - Every tests/unit/*Test.php file is loaded once. Each function it defines
 *   whose unqualified name starts with "test_" is a test, run in file order.
 * - Give each file its own namespace (Tests\<FileName>) so test names may
 *   repeat across files.
 * - A test passes when it returns and fails when it throws. Use the assert*
 *   helpers of tests/harness.php; unqualified calls resolve to them from any
 *   namespace.
 * - Tests that consult the reference interpreter call lua5.4 / luac5.4
 *   directly; scratch files go in scratchDirectory(), removed at exit.
 *
 * Exit status is 1 if any test fails.
 *
 * C stack: the runner and every process it starts run with an 8 MB stack,
 * the usual default (it runs itself again under `ulimit -s 8192` when the
 * limit differs). A bigger stack, like the 16 MB of some shells, hides PHP
 * crashing in its own recursion: opcache's optimizer recurses about once
 * per basic block of a function, so a huge generated function segfaults
 * (exit 139) at 8 MB and passes at 16 MB.
 */

declare(strict_types=1);

const TEST_STACK_BYTES = 8 * 1024 * 1024;
$limits = @file_get_contents('/proc/self/limits');
if (
    $limits !== false && preg_match('/^Max stack size +(\S+)/m', $limits, $match)
    && $match[1] !== (string) TEST_STACK_BYTES
) {
    // the same command line (with php's own -d options) under the usual stack
    $commandLine = explode("\0", rtrim(file_get_contents('/proc/self/cmdline'), "\0"));
    $quotedCommandLine = implode(' ', array_map('escapeshellarg', $commandLine));
    passthru('ulimit -s ' . (TEST_STACK_BYTES >> 10) . ' && exec ' . $quotedCommandLine, $status);
    exit($status);
}

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/AssertionFailed.php';
require __DIR__ . '/harness.php';
require __DIR__ . '/jit/detector.php';
require __DIR__ . '/jit/inventory_inputs.php';

$filter = $argv[1] ?? '';
$testFiles = glob(__DIR__ . '/unit/*Test.php');
sort($testFiles);

$passedCount = 0;
$failedTests = [];
foreach ($testFiles as $testFile) {
    $functionsBefore = get_defined_functions()['user'];
    require $testFile;
    $newFunctions = array_diff(get_defined_functions()['user'], $functionsBefore);
    foreach ($newFunctions as $functionName) {
        $reflection = new \ReflectionFunction($functionName);
        $shortName = $reflection->getShortName();
        if (!str_starts_with($shortName, 'test_')) {
            continue;
        }
        $testLabel = basename($testFile, '.php') . '::' . $shortName;
        if ($filter !== '' && !str_contains($testLabel, $filter)) {
            continue;
        }
        $startTime = microtime(true);
        try {
            $reflection->invoke();
            $passedCount++;
            printf("PASS %s (%.2fs)\n", $testLabel, microtime(true) - $startTime);
        } catch (\Throwable $thrown) {
            $failedTests[] = $testLabel;
            printf("FAIL %s\n     %s: %s\n", $testLabel, get_class($thrown), $thrown->getMessage());
            // point at the test line (for assertions) or where it was thrown
            $location = $thrown->getFile() . ':' . $thrown->getLine();
            foreach ($thrown->getTrace() as $frame) {
                if (($frame['file'] ?? '') === realpath($testFile)) {
                    $location .= ' (from ' . basename($testFile) . ':' . $frame['line'] . ')';
                    break;
                }
            }
            echo "     at $location\n";
        }
    }
}

/*
 * Differential cases: every tests/diff/*.lua runs under lua5.4 and under
 * bin/lua, from tests/diff with its relative name (so chunk names match).
 * Exit status, stdout and stderr must be identical once 0x... addresses
 * and the program name at the start of stderr lines are normalized.
 * Cases run in parallel; output goes through temporary files.
 */

$diffDirectory = __DIR__ . '/diff';
$diffFiles = glob($diffDirectory . '/*.lua');
sort($diffFiles);
if ($filter !== '') {
    $diffFiles = array_values(array_filter(
        $diffFiles,
        static fn (string $file): bool => str_contains('diff/' . basename($file), $filter),
    ));
}
$ourProgram = realpath(REPO_ROOT . '/bin/lua');
$parallelCases = 8;
foreach (array_chunk($diffFiles, $parallelCases) as $batch) {
    $running = [];
    $startTime = microtime(true);
    foreach ($batch as $file) {
        $name = basename($file);
        $running[$name] = [
            startProcess(['lua5.4', $name], $diffDirectory),
            startProcess(['php', $ourProgram, $name], $diffDirectory),
        ];
    }
    foreach ($running as $name => [$referenceProcess, $ourProcess]) {
        [$referenceStatus, $referenceStdout, $referenceStderr] = finishProcess($referenceProcess);
        [$ourStatus, $ourStdout, $ourStderr] = finishProcess($ourProcess);
        $label = "diff/$name";
        $problems = [];
        if ($referenceStatus !== $ourStatus) {
            $problems[] = "exit status: expected $referenceStatus, got $ourStatus";
        }
        $expectedStdout = normalizeDifferentialOutput($referenceStdout, 'lua5.4');
        $actualStdout = normalizeDifferentialOutput($ourStdout, 'lua5.4');
        if ($expectedStdout !== $actualStdout) {
            $problems[] = 'stdout differs' . firstDifference($expectedStdout, $actualStdout);
        }
        $expectedStderr = normalizeDifferentialOutput($referenceStderr, 'lua5.4');
        $actualStderr = normalizeDifferentialOutput($ourStderr, $ourProgram);
        if ($expectedStderr !== $actualStderr) {
            $problems[] = 'stderr differs' . firstDifference($expectedStderr, $actualStderr);
        }
        if ($problems === []) {
            $passedCount++;
            printf("PASS %s (%.2fs)\n", $label, microtime(true) - $startTime);
        } else {
            $failedTests[] = $label;
            printf("FAIL %s\n     %s\n", $label, implode("\n     ", $problems));
        }
    }
}

printf("\n%d passed, %d failed\n", $passedCount, count($failedTests));
exit($failedTests === [] ? 0 : 1);
