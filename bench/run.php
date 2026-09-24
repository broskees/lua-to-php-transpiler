<?php

declare(strict_types=1);

/**
 * Benchmark runner:  php bench/run.php <label> [runs]
 *
 * Runs every workload under lua5.4 and under `php bin/lua` (the ambient
 * php.ini), prints a markdown report and saves it to
 * bench/results/<label>.md. See bench/README.md for the columns.
 *
 * - Memory workloads (workloads/memory/*.lua) print /proc/self/status at
 *   their end; the report shows peak RSS (VmHWM) minus that of
 *   empty.lua (the interpreter's startup), and peak virtual memory
 *   (VmPeak). linked_list_drop.lua prints "survived" after freeing.
 * - Speed workloads (workloads/speed/*.lua, plus the official all.lua
 *   with -e"_U=true") report the median wall time of [runs] runs
 *   (default 5), startup included.
 * - The virtual-memory floor is the smallest `ulimit -v` (a multiple of
 *   128 MB) under which all.lua still prints "final OK !!!".
 */

const REPO_ROOT = __DIR__ . '/..';
const OFFICIAL_TESTS_DIRECTORY = REPO_ROOT . '/reference/lua-5.4.9-tests';
const FLOOR_STEP_KILOBYTES = 128 * 1024;

$label = $argv[1] ?? '';
if ($label === '' || !preg_match('/^[\w.-]+$/', $label)) {
    fwrite(STDERR, "usage: php bench/run.php <label> [runs]\n");
    exit(2);
}
$runs = (int) ($argv[2] ?? 5);

$interpreters = [
    'lua5.4' => ['lua5.4'],
    'ours' => ['php', realpath(REPO_ROOT . '/bin/lua')],
];

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
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $workingDirectory);
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

/** @param list<string> $interpreter */
function allLuaPasses(array $interpreter, ?int $virtualKilobytes): array
{
    [$status, $output, $seconds] = runCommand([...$interpreter, '-e_U=true', 'all.lua'], OFFICIAL_TESTS_DIRECTORY, $virtualKilobytes);
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

$report = "# Benchmark: $label\n\n";
$commit = trim((string) shell_exec('git -C ' . escapeshellarg(REPO_ROOT) . ' rev-parse --short HEAD 2>/dev/null'));
$dirty = trim((string) shell_exec('git -C ' . escapeshellarg(REPO_ROOT) . ' status --porcelain -- src bin 2>/dev/null')) !== '' ? ' (src/bin modified)' : '';
$report .= sprintf("- date: %s\n- commit: %s%s\n- %s\n- %s\n- speed: median of %d runs\n\n",
    date('Y-m-d H:i'), $commit === '' ? 'unknown' : $commit, $dirty,
    strtok((string) shell_exec('php -v'), "\n"), trim((string) shell_exec('lua5.4 -v 2>&1')), $runs);

// memory
$memoryDirectory = __DIR__ . '/workloads/memory';
$memory = [];
foreach ($interpreters as $name => $interpreter) {
    foreach (glob("$memoryDirectory/*.lua") as $file) {
        [$status, $output] = runCommand([...$interpreter, basename($file)], $memoryDirectory);
        $memory[basename($file, '.lua')][$name] = [
            'hwm' => $status === 0 ? statusField($output, 'VmHWM') : null,
            'peak' => $status === 0 ? statusField($output, 'VmPeak') : null,
            'survived' => $status === 0 && str_contains($output, 'survived') ? 'yes' : "no (exit status $status)",
        ];
    }
}
$report .= "## Memory (MB)\n\n"
    . "| workload | lua5.4 peak RSS - startup | ours peak RSS - startup | lua5.4 VmPeak | ours VmPeak |\n"
    . "|---|---:|---:|---:|---:|\n";
$empty = $memory['empty'];
$report .= sprintf("| startup (empty.lua, absolute) | %s | %s | %s | %s |\n",
    megabytes($empty['lua5.4']['hwm']), megabytes($empty['ours']['hwm']), megabytes($empty['lua5.4']['peak']), megabytes($empty['ours']['peak']));
foreach ($memory as $workload => $results) {
    if ($workload === 'empty') {
        continue;
    }
    $delta = static fn (string $name): ?int => $results[$name]['hwm'] === null || $empty[$name]['hwm'] === null ? null : $results[$name]['hwm'] - $empty[$name]['hwm'];
    $report .= sprintf("| %s | %s | %s | %s | %s |\n", $workload,
        megabytes($delta('lua5.4')), megabytes($delta('ours')), megabytes($results['lua5.4']['peak']), megabytes($results['ours']['peak']));
}
$report .= sprintf("\n1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 %s, ours %s\n\n",
    $memory['linked_list_drop']['lua5.4']['survived'], $memory['linked_list_drop']['ours']['survived']);
echo $report;

// speed
$speedDirectory = __DIR__ . '/workloads/speed';
$speedReport = "## Speed (median wall seconds)\n\n| workload | lua5.4 | ours | ours / lua5.4 |\n|---|---:|---:|---:|\n";
$workloads = [];
foreach (glob("$speedDirectory/*.lua") as $file) {
    $workloads[basename($file, '.lua')] = static fn (array $interpreter): array => runCommand([...$interpreter, basename($file)], $speedDirectory);
}
$workloads['all.lua -e"_U=true"'] = static function (array $interpreter): array {
    [$passed, $seconds] = allLuaPasses($interpreter, null);
    return [$passed ? 0 : 1, '', $seconds];
};
foreach ($workloads as $workload => $run) {
    $medians = [];
    foreach ($interpreters as $name => $interpreter) {
        $times = [];
        for ($i = 0; $i < $runs; $i++) {
            [$status, , $seconds] = $run($interpreter);
            if ($status !== 0) {
                $times = null;
                break;
            }
            $times[] = $seconds;
        }
        $medians[$name] = $times === null ? null : median($times);
    }
    $format = static fn (?float $seconds): string => $seconds === null ? 'FAIL' : sprintf('%.3f', $seconds);
    $ratio = $medians['lua5.4'] !== null && $medians['ours'] !== null ? sprintf('%.1f', $medians['ours'] / $medians['lua5.4']) : '-';
    $speedReport .= sprintf("| %s | %s | %s | %s |\n", $workload, $format($medians['lua5.4']), $format($medians['ours']), $ratio);
}
$speedReport .= "\n";
echo $speedReport;
$report .= $speedReport;

// virtual-memory floor
$floorReport = "## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e\"_U=true\")\n\n| lua5.4 | ours |\n|---:|---:|\n";
$floors = [];
foreach ($interpreters as $name => $interpreter) {
    $floor = virtualMemoryFloor($interpreter);
    $floors[$name] = $floor === null ? '> 64 GB' : sprintf('%d MB', $floor / 1024);
}
$floorReport .= "| {$floors['lua5.4']} | {$floors['ours']} |\n";
echo $floorReport;
$report .= $floorReport;

file_put_contents(__DIR__ . "/results/$label.md", $report);
echo "\nsaved bench/results/$label.md\n";
