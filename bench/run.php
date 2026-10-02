<?php

/**
 * Benchmark runner:  php bench/run.php <label> [runs]
 *
 * Measures against lua5.4, prints a markdown report and saves it to
 * bench/results/<label>.md. See bench/README.md for the columns.
 *
 * - Memory workloads (workloads/memory/*.lua) run under `php bin/lua` (the
 *   ambient php.ini) and print /proc/self/status at their end; the report
 *   shows peak RSS (VmHWM) minus that of empty.lua (the interpreter's
 *   startup), and peak virtual memory (VmPeak). linked_list_drop.lua
 *   prints "survived" after freeing.
 * - Speed workloads (workloads/speed/*.lua) run as the PHP file bin/lua2php
 *   saved (`php out.php`) with a warm opcache, with and without the tracing
 *   JIT; transpiling (`bin/lua2php in.lua -o out.php`) is timed on its own.
 *   Each number is the median wall time of [runs] runs (default 5) after
 *   one untimed run, process startup included.
 * - The official all.lua with -e"_U=true" runs under `php bin/lua`:
 *   lua2php output cannot run it (files.lua dofile()s a file it writes while
 *   it runs, and lua2php output loads only files transpiled ahead of time).
 * - Transpiling the official test files gives the time per 1,000 lines.
 * - The virtual-memory floor is the smallest `ulimit -v` (a multiple of
 *   128 MB) under which all.lua still prints "final OK !!!" under bin/lua.
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
    'bin/lua' => ['php', realpath(REPO_ROOT . '/bin/lua')],
];
$lua2php = realpath(REPO_ROOT . '/bin/lua2php');

$scratchDirectory = sys_get_temp_dir() . '/luaphp-bench-' . bin2hex(random_bytes(4));
mkdir($scratchDirectory);
register_shutdown_function(static function () use ($scratchDirectory): void {
    exec('rm -rf ' . escapeshellarg($scratchDirectory));
});
// php for the saved PHP and for bin/lua2php: opcache keeps compiled files in
// a file cache across processes, and caches files however new they are
mkdir("$scratchDirectory/opcache");
mkdir("$scratchDirectory/opcache-jit");
$warmOpcacheOptions = ['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0'];
$warmPhp = ['php', ...$warmOpcacheOptions, '-d', "opcache.file_cache=$scratchDirectory/opcache"];
$warmPhpWithJit = [
    'php', ...$warmOpcacheOptions, '-d', "opcache.file_cache=$scratchDirectory/opcache-jit",
    '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=64M',
];
$format = static fn (?float $seconds): string => $seconds === null ? 'FAIL' : sprintf('%.3f', $seconds);

$report = "# Benchmark: $label\n\n";
$git = 'git -C ' . escapeshellarg(REPO_ROOT);
$commit = trim((string) shell_exec("$git rev-parse --short HEAD 2>/dev/null"));
$changes = trim((string) shell_exec("$git status --porcelain -- src bin 2>/dev/null"));
$dirty = $changes !== '' ? ' (src/bin modified)' : '';
$report .= sprintf(
    "- date: %s\n- commit: %s%s\n- %s\n- %s\n- php.ini opcache.jit: %s\n- speed: median of %d runs\n\n",
    date('Y-m-d H:i'),
    $commit === '' ? 'unknown' : $commit,
    $dirty,
    strtok((string) shell_exec('php -v'), "\n"),
    trim((string) shell_exec('lua5.4 -v 2>&1')),
    ini_get('opcache.jit'),
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
    . "| workload | lua5.4 peak RSS - startup | bin/lua peak RSS - startup | lua5.4 VmPeak | bin/lua VmPeak |\n"
    . "|---|---:|---:|---:|---:|\n";
$empty = $memory['empty'];
$report .= sprintf(
    "| startup (empty.lua, absolute) | %s | %s | %s | %s |\n",
    megabytes($empty['lua5.4']['hwm']),
    megabytes($empty['bin/lua']['hwm']),
    megabytes($empty['lua5.4']['peak']),
    megabytes($empty['bin/lua']['peak'])
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
        megabytes($delta('bin/lua')),
        megabytes($results['lua5.4']['peak']),
        megabytes($results['bin/lua']['peak'])
    );
}
$report .= sprintf(
    "\n1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 %s, bin/lua %s\n\n",
    $memory['linked_list_drop']['lua5.4']['survived'],
    $memory['linked_list_drop']['bin/lua']['survived']
);
echo $report;

// speed: the saved lua2php output; transpiling is its own column
$speedDirectory = __DIR__ . '/workloads/speed';
$speedReport = "## Speed (median wall seconds, process startup included)\n\n"
    . "*lua5.4* runs the Lua file. *saved PHP* runs the file `bin/lua2php` saved (`php out.php`) with a warm "
    . 'opcache: `php ' . implode(' ', $warmOpcacheOptions) . ' -d opcache.file_cache=<directory>`, '
    . "after one untimed run that fills the file cache. *JIT* adds `-d opcache.jit=tracing` (its own file cache). "
    . '*transpile ms* is `bin/lua2php in.lua -o out.php` under the same options (one untimed run first), '
    . "not part of any run time.\n\n"
    . "| workload | lua5.4 | saved PHP | saved PHP / lua5.4 | saved PHP, JIT | JIT / lua5.4 | transpile ms |\n"
    . "|---|---:|---:|---:|---:|---:|---:|\n";
foreach (glob("$speedDirectory/*.lua") as $file) {
    $workload = basename($file, '.lua');
    $savedFile = "$scratchDirectory/$workload.php";
    $transpileSeconds = medianWallSeconds(
        [...$warmPhp, $lua2php, basename($file), '-o', $savedFile],
        $speedDirectory,
        $runs,
    );
    $luaSeconds = medianWallSeconds(['lua5.4', basename($file)], $speedDirectory, $runs);
    $savedSeconds = null;
    $jitSeconds = null;
    if ($transpileSeconds !== null) {
        $savedSeconds = medianWallSeconds([...$warmPhp, $savedFile], $speedDirectory, $runs);
        $jitSeconds = medianWallSeconds([...$warmPhpWithJit, $savedFile], $speedDirectory, $runs);
    }
    $speedReport .= sprintf(
        "| %s | %s | %s | %s | %s | %s | %s |\n",
        $workload,
        $format($luaSeconds),
        $format($savedSeconds),
        ratio($savedSeconds, $luaSeconds),
        $format($jitSeconds),
        ratio($jitSeconds, $luaSeconds),
        $transpileSeconds === null ? 'FAIL' : sprintf('%.0f', $transpileSeconds * 1000),
    );
}
$speedReport .= "\n";
echo $speedReport;
$report .= $speedReport;

// the official test suite, under bin/lua (lua2php output cannot run all.lua)
$allLuaMedians = [];
foreach ($interpreters as $name => $interpreter) {
    $times = [];
    for ($run = 0; $run < $runs; $run++) {
        [$passed, $seconds] = allLuaPasses($interpreter, null);
        if (!$passed) {
            $times = null;
            break;
        }
        $times[] = $seconds;
    }
    $allLuaMedians[$name] = $times === null ? null : median($times);
}
$allLuaReport = "## Official test suite under bin/lua (median wall seconds)\n\n"
    . '`php bin/lua -e"_U=true" all.lua` (the ambient php.ini) transpiles each file while it runs. lua2php '
    . "output cannot run all.lua: files.lua `dofile()`s a file it writes at run time, never transpiled.\n\n"
    . "| | lua5.4 | bin/lua | bin/lua / lua5.4 |\n|---|---:|---:|---:|\n"
    . sprintf(
        "| all.lua -e\"_U=true\" | %s | %s | %s |\n\n",
        $format($allLuaMedians['lua5.4']),
        $format($allLuaMedians['bin/lua']),
        ratio($allLuaMedians['bin/lua'], $allLuaMedians['lua5.4']),
    );
echo $allLuaReport;
$report .= $allLuaReport;

// transpiling a project: the official test files
$officialFiles = glob(OFFICIAL_TESTS_DIRECTORY . '/*.lua');
$officialLines = 0;
foreach ($officialFiles as $file) {
    $officialLines += substr_count(file_get_contents($file), "\n");
}
$officialSeconds = medianWallSeconds(
    [...$warmPhp, $lua2php, OFFICIAL_TESTS_DIRECTORY, '-o', "$scratchDirectory/official"],
    $scratchDirectory,
    $runs,
);
$transpileReport = sprintf(
    "## Transpiling (median wall seconds)\n\n"
        . "`bin/lua2php <directory> -o <output>` on the %d official test files, %s lines, in one process "
        . "(startup included, the speed section's opcache options): %s s, %s ms per 1,000 lines\n\n",
    count($officialFiles),
    number_format($officialLines),
    $format($officialSeconds),
    $officialSeconds === null ? 'FAIL' : sprintf('%.0f', $officialSeconds * 1000 * 1000 / $officialLines),
);
echo $transpileReport;
$report .= $transpileReport;

// virtual-memory floor
$floorReport = "## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e\"_U=true\")\n\n"
    . "| lua5.4 | bin/lua |\n|---:|---:|\n";
$floors = [];
foreach ($interpreters as $name => $interpreter) {
    $floor = virtualMemoryFloor($interpreter);
    $floors[$name] = $floor === null ? '> 64 GB' : sprintf('%d MB', $floor / 1024);
}
$floorReport .= "| {$floors['lua5.4']} | {$floors['bin/lua']} |\n";
echo $floorReport;
$report .= $floorReport;

file_put_contents(__DIR__ . "/results/$label.md", $report);
echo "\nsaved bench/results/$label.md\n";
