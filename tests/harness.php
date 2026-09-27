<?php

/**
 * What the tests share (tests/run.php loads it before the tests): the
 * assert* helpers, processes, scratch files and paths, and the helpers of
 * the differential cases.
 */

declare(strict_types=1);

use Tests\AssertionFailed;

const REPO_ROOT = __DIR__ . '/..';
const OFFICIAL_TESTS_DIRECTORY = REPO_ROOT . '/reference/lua-5.4.9-tests';

/**
 * php options for a process that runs files a test has just generated or
 * cached: opcache compiles and optimizes them, where by default it skips
 * files younger than opcache.file_update_protection (2 seconds).
 */
const OPCACHE_ON_NEW_FILES = ['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0'];

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

/*
 * Differential cases (tests/run.php runs them after the unit tests).
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
            return sprintf(
                " at line %d:\n       expected: %s\n       got:      %s",
                $i + 1,
                substr($expectedLine, 0, 300),
                substr($actualLine, 0, 300),
            );
        }
    }
    return '';
}
