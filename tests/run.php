<?php

declare(strict_types=1);

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
 *   helpers below; unqualified calls resolve to them from any namespace.
 * - Tests that consult the reference interpreter call lua5.4 / luac5.4
 *   directly; scratch files go in scratchDirectory(), removed at exit.
 *
 * Exit status is 1 if any test fails.
 */

require __DIR__ . '/../src/autoload.php';

const REPO_ROOT = __DIR__ . '/..';
const OFFICIAL_TESTS_DIRECTORY = REPO_ROOT . '/reference/lua-5.4.9-tests';

final class AssertionFailed extends \RuntimeException
{
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected === $actual) {
        return;
    }
    $details = 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    throw new AssertionFailed($message === '' ? $details : "$message: $details");
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

/**
 * Run $callback and return the message of the $exceptionClass it throws;
 * fail if it throws nothing.
 */
function assertThrows(string $exceptionClass, callable $callback): string
{
    try {
        $callback();
    } catch (\Throwable $thrown) {
        if ($thrown instanceof $exceptionClass) {
            return $thrown->getMessage();
        }
        throw $thrown;
    }
    throw new AssertionFailed("expected $exceptionClass to be thrown");
}

/**
 * Run a command (argument list, no shell); returns [exit code, stdout, stderr].
 *
 * @param list<string> $command
 * @return array{int, string, string}
 */
function runCommand(array $command, string $standardInput = '', ?string $workingDirectory = null): array
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
    );
    if ($process === false) {
        throw new \RuntimeException('cannot run ' . implode(' ', $command));
    }
    fwrite($pipes[0], $standardInput);
    fclose($pipes[0]);
    $standardOutput = stream_get_contents($pipes[1]);
    $standardError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $standardOutput, $standardError];
}

function scratchDirectory(): string
{
    static $directory = null;
    if ($directory === null) {
        $directory = sys_get_temp_dir() . '/luaphp-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($directory);
        register_shutdown_function(static function () use ($directory): void {
            exec('rm -rf ' . escapeshellarg($directory));
        });
    }
    return $directory;
}

/** @return list<string> absolute paths of the top-level official test files */
function officialTestFiles(): array
{
    $files = glob(OFFICIAL_TESTS_DIRECTORY . '/*.lua');
    sort($files);
    return $files;
}

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

/**
 * Start $command with stdin from /dev/null and stdout/stderr into files.
 *
 * @param list<string> $command
 * @return array{resource, string, string}
 */
function startProcess(array $command, string $workingDirectory): array
{
    $stdoutFile = tempnam(scratchDirectory(), 'out');
    $stderrFile = tempnam(scratchDirectory(), 'err');
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $workingDirectory,
    );
    if ($process === false) {
        throw new \RuntimeException('cannot run ' . implode(' ', $command));
    }
    return [$process, $stdoutFile, $stderrFile];
}

/**
 * @param array{resource, string, string} $started
 * @return array{int, string, string} [exit status, stdout, stderr]
 */
function finishProcess(array $started): array
{
    [$process, $stdoutFile, $stderrFile] = $started;
    $exitStatus = proc_close($process);
    $result = [$exitStatus, file_get_contents($stdoutFile), file_get_contents($stderrFile)];
    unlink($stdoutFile);
    unlink($stderrFile);
    return $result;
}

function normalizeDifferentialOutput(string $text, string $programName): string
{
    $text = preg_replace('/0x[0-9a-f]+/', '0x?', $text);
    return preg_replace('/^' . preg_quote($programName, '/') . ':/m', 'lua:', $text);
}

$diffDirectory = __DIR__ . '/diff';
$diffFiles = glob($diffDirectory . '/*.lua');
sort($diffFiles);
if ($filter !== '') {
    $diffFiles = array_values(array_filter($diffFiles, static fn (string $file): bool => str_contains('diff/' . basename($file), $filter)));
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
        if (normalizeDifferentialOutput($referenceStdout, 'lua5.4') !== normalizeDifferentialOutput($ourStdout, 'lua5.4')) {
            $problems[] = 'stdout differs' . firstDifference(
                normalizeDifferentialOutput($referenceStdout, 'lua5.4'),
                normalizeDifferentialOutput($ourStdout, 'lua5.4'),
            );
        }
        if (normalizeDifferentialOutput($referenceStderr, 'lua5.4') !== normalizeDifferentialOutput($ourStderr, $ourProgram)) {
            $problems[] = 'stderr differs' . firstDifference(
                normalizeDifferentialOutput($referenceStderr, 'lua5.4'),
                normalizeDifferentialOutput($ourStderr, $ourProgram),
            );
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

/** ": line N: expected '...', got '...'" for the first differing line */
function firstDifference(string $expected, string $actual): string
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $count = max(count($expectedLines), count($actualLines));
    for ($i = 0; $i < $count; $i++) {
        $expectedLine = $expectedLines[$i] ?? '<missing>';
        $actualLine = $actualLines[$i] ?? '<missing>';
        if ($expectedLine !== $actualLine) {
            return sprintf(" at line %d:\n       expected: %s\n       got:      %s", $i + 1, substr($expectedLine, 0, 300), substr($actualLine, 0, 300));
        }
    }
    return '';
}

printf("\n%d passed, %d failed\n", $passedCount, count($failedTests));
exit($failedTests === [] ? 0 : 1);
