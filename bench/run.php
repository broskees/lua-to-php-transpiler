<?php

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

declare(strict_types=1);

require __DIR__ . '/run_functions.php';

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

$report = "# Benchmark: $label\n\n";
$git = 'git -C ' . escapeshellarg(REPO_ROOT);
$commit = trim((string) shell_exec("$git rev-parse --short HEAD 2>/dev/null"));
$changes = trim((string) shell_exec("$git status --porcelain -- src bin 2>/dev/null"));
$dirty = $changes !== '' ? ' (src/bin modified)' : '';
$report .= sprintf(
    "- date: %s\n- commit: %s%s\n- %s\n- %s\n- speed: median of %d runs\n\n",
    date('Y-m-d H:i'),
    $commit === '' ? 'unknown' : $commit,
    $dirty,
    strtok((string) shell_exec('php -v'), "\n"),
    trim((string) shell_exec('lua5.4 -v 2>&1')),
    $runs
);

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
$report .= sprintf(
    "| startup (empty.lua, absolute) | %s | %s | %s | %s |\n",
    megabytes($empty['lua5.4']['hwm']),
    megabytes($empty['ours']['hwm']),
    megabytes($empty['lua5.4']['peak']),
    megabytes($empty['ours']['peak'])
);
foreach ($memory as $workload => $results) {
    if ($workload === 'empty') {
        continue;
    }
    $delta = static fn (string $name): ?int => $results[$name]['hwm'] === null || $empty[$name]['hwm'] === null
        ? null
        : $results[$name]['hwm'] - $empty[$name]['hwm'];
    $report .= sprintf(
        "| %s | %s | %s | %s | %s |\n",
        $workload,
        megabytes($delta('lua5.4')),
        megabytes($delta('ours')),
        megabytes($results['lua5.4']['peak']),
        megabytes($results['ours']['peak'])
    );
}
$report .= sprintf(
    "\n1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 %s, ours %s\n\n",
    $memory['linked_list_drop']['lua5.4']['survived'],
    $memory['linked_list_drop']['ours']['survived']
);
echo $report;

// speed
$speedDirectory = __DIR__ . '/workloads/speed';
$speedReport = "## Speed (median wall seconds)\n\n| workload | lua5.4 | ours | ours / lua5.4 |\n|---|---:|---:|---:|\n";
$workloads = [];
foreach (glob("$speedDirectory/*.lua") as $file) {
    $workloads[basename($file, '.lua')] = static fn (array $interpreter): array
        => runCommand([...$interpreter, basename($file)], $speedDirectory);
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
    $ratio = $medians['lua5.4'] !== null && $medians['ours'] !== null
        ? sprintf('%.1f', $medians['ours'] / $medians['lua5.4'])
        : '-';
    $speedReport .= sprintf(
        "| %s | %s | %s | %s |\n",
        $workload,
        $format($medians['lua5.4']),
        $format($medians['ours']),
        $ratio,
    );
}
$speedReport .= "\n";
echo $speedReport;
$report .= $speedReport;

// virtual-memory floor
$floorReport = "## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e\"_U=true\")\n\n"
    . "| lua5.4 | ours |\n|---:|---:|\n";
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
