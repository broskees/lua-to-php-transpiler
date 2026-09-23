<?php

declare(strict_types=1);

/**
 * Test runner:  php tests/run.php [substring-filter]
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

printf("\n%d passed, %d failed\n", $passedCount, count($failedTests));
exit($failedTests === [] ? 0 : 1);
