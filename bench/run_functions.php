<?php

/** The functions of the benchmark runner bench/run.php. */

declare(strict_types=1);

const REPO_ROOT = __DIR__ . '/..';
const OFFICIAL_TESTS_DIRECTORY = REPO_ROOT . '/reference/lua-5.4.9-tests';
const FLOOR_STEP_KILOBYTES = 128 * 1024;

/**
 * Run a command with stdin from /dev/null and stderr merged into stdout,
 * optionally under `ulimit -v $virtualKilobytes`.
 *
 * @param list<string> $command
 * @return array{int, string, float} [exit status, output, wall seconds]
 */
function runCommand(array $command, string $workingDirectory, ?int $virtualKilobytes = null): array
{
    if ($virtualKilobytes !== null) {
        $command = ['sh', '-c', "ulimit -v $virtualKilobytes && exec \"\$@\"", 'sh', ...$command];
    }
    $startTime = hrtime(true);
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $workingDirectory,
    );
    if ($process === false) {
        throw new RuntimeException('cannot run ' . implode(' ', $command));
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    return [$status, $output, (hrtime(true) - $startTime) / 1e9];
}

/** a field of /proc/self/status (in kB) printed by a memory workload, or null */
function statusField(string $output, string $field): ?int
{
    return preg_match('/^' . $field . ':\s*(\d+) kB/m', $output, $match) ? (int) $match[1] : null;
}

function megabytes(?int $kilobytes): string
{
    return $kilobytes === null ? 'FAIL' : sprintf('%.1f', $kilobytes / 1024);
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    return $count % 2 === 1 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
}

/**
 * The median wall seconds of $runs runs of $command, after one untimed run
 * (it fills opcache's file cache and the page cache), or null when a run
 * fails.
 *
 * @param list<string> $command
 */
function medianWallSeconds(array $command, string $workingDirectory, int $runs): ?float
{
    $times = [];
    for ($run = 0; $run <= $runs; $run++) {
        [$status, , $seconds] = runCommand($command, $workingDirectory);
        if ($status !== 0) {
            return null;
        }
        if ($run > 0) {
            $times[] = $seconds;
        }
    }
    return median($times);
}

/** "-" unless both are known */
function ratio(?float $seconds, ?float $referenceSeconds): string
{
    return $seconds === null || $referenceSeconds === null ? '-' : sprintf('%.1f', $seconds / $referenceSeconds);
}

/** @param list<string> $interpreter */
function allLuaPasses(array $interpreter, ?int $virtualKilobytes): array
{
    [$status, $output, $seconds] = runCommand(
        [...$interpreter, '-e_U=true', 'all.lua'],
        OFFICIAL_TESTS_DIRECTORY,
        $virtualKilobytes,
    );
    return [$status === 0 && str_contains($output, 'final OK !!!'), $seconds];
}

/**
 * The smallest multiple of 128 MB of `ulimit -v` under which all.lua
 * passes (doubling, then bisecting), or null if even 64 GB fails.
 *
 * @param list<string> $interpreter
 */
function virtualMemoryFloor(array $interpreter): ?int
{
    $high = 1;  // in steps
    while (!allLuaPasses($interpreter, $high * FLOOR_STEP_KILOBYTES)[0]) {
        $high *= 2;
        if ($high > 512) {
            return null;
        }
    }
    $low = intdiv($high, 2);  // fails (0: not tried)
    while ($high - $low > 1) {
        $middle = intdiv($low + $high, 2);
        if (allLuaPasses($interpreter, $middle * FLOOR_STEP_KILOBYTES)[0]) {
            $high = $middle;
        } else {
            $low = $middle;
        }
    }
    return $high * FLOOR_STEP_KILOBYTES;
}
