<?php

/** The functions of the scorer bench/score/score.php. */

declare(strict_types=1);

const SCORE_DIRECTORY = __DIR__;
const CHECKOUT_ROOT = __DIR__ . '/../..';

/**
 * Extensions every PHP has (they cannot be left out of a build) plus ctype,
 * the one extension composer.json requires. The functions of every other
 * extension are disabled in the profiles below (opcache's too: its engine
 * stays on, its functions are optional).
 */
const ALLOWED_EXTENSIONS = [
    'core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard', 'lexbor', 'uri', 'ctype',
];

/** what a typical shared host disables besides (tests/unit/DisabledFunctionsTest.php's list) */
const SHARED_HOST_DISABLED_FUNCTIONS = [
    'exec', 'passthru', 'shell_exec', 'system', 'popen', 'proc_open', 'proc_close', 'proc_get_status',
    'proc_terminate', 'proc_nice', 'symlink', 'link', 'putenv', 'getmypid', 'dl', 'ini_set', 'set_time_limit',
];

/** the longest any one benchmark run may take */
const RUN_TIMEOUT_SECONDS = 300;

/**
 * Run $command (no shell) with stdin from /dev/null, killed after
 * $timeoutSeconds. The CPU time is the user + system time of the command
 * and its children (getrusage of the children this process waited for).
 *
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{status: int, stdout: string, stderr: string, cpuSeconds: float}
 */
function runCommand(array $command, string $workingDirectory, array $environment, int $timeoutSeconds): array
{
    $stderrFile = tempnam(sys_get_temp_dir(), 'luaphp-score-stderr-');
    $usageBefore = getrusage(1);
    $process = proc_open(
        ['timeout', '-s', 'KILL', (string) $timeoutSeconds, ...$command],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $workingDirectory,
        $environment,
    );
    if ($process === false) {
        throw new RuntimeException('cannot run ' . implode(' ', $command));
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    $usageAfter = getrusage(1);
    $stderr = (string) file_get_contents($stderrFile);
    unlink($stderrFile);
    return [
        'status' => $status,
        'stdout' => (string) $stdout,
        'stderr' => $stderr,
        'cpuSeconds' => cpuSeconds($usageAfter) - cpuSeconds($usageBefore),
    ];
}

/** @param array<string, int> $usage */
function cpuSeconds(array $usage): float
{
    return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']
        + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
}

/**
 * Run a command whose failure ends the scoring.
 *
 * @param list<string> $command
 */
function runOrFail(array $command, string $workingDirectory, string $what, int $timeoutSeconds = 600): string
{
    $result = runCommand($command, $workingDirectory, baseEnvironment(), $timeoutSeconds);
    if ($result['status'] !== 0) {
        throw new RuntimeException(sprintf(
            "%s failed (exit status %d)\n%s%s",
            $what,
            $result['status'],
            lastLines($result['stdout'], 30),
            lastLines($result['stderr'], 30),
        ));
    }
    return $result['stdout'];
}

/** @return array<string, string> the environment of every command: PATH and HOME only */
function baseEnvironment(): array
{
    return ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'LC_ALL' => 'C'];
}

/** each line of a test run's output that starts with FAIL, with the 4 lines after it */
function failureLines(string $text): string
{
    $lines = explode("\n", $text);
    $failures = '';
    foreach ($lines as $index => $line) {
        if (str_starts_with($line, 'FAIL')) {
            $failures .= implode("\n", array_slice($lines, $index, 5)) . "\n";
        }
    }
    return $failures === '' ? '' : "--- failures\n$failures--- the end of the output\n";
}

function lastLines(string $text, int $count): string
{
    $lines = explode("\n", rtrim($text, "\n"));
    return $text === '' ? '' : implode("\n", array_slice($lines, -$count)) . "\n";
}

/**
 * The functions of every extension `php -n` loads except ALLOWED_EXTENSIONS.
 *
 * @return list<string>
 */
function extensionFunctions(): array
{
    $code = 'foreach (get_loaded_extensions() as $e) { foreach (get_extension_funcs($e) ?: [] as $f) '
        . '{ echo strtolower($e), " ", $f, "\n"; } }';
    $functions = [];
    foreach (explode("\n", trim(runOrFail(['php', '-n', '-r', $code], CHECKOUT_ROOT, 'listing functions'))) as $line) {
        [$extension, $function] = explode(' ', $line, 2);
        if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
            $functions[] = $function;
        }
    }
    return $functions;
}

/**
 * php as a typical shared host runs it: no php.ini, assertions off,
 * opcache on with a file cache (it stands for the warm opcache of a web
 * server), no JIT, the functions of optional extensions and the host's
 * usual list disabled, the default memory_limit (128M).
 *
 * @param list<string> $extensionFunctions
 * @return list<string>
 */
function sharedHostPhp(array $extensionFunctions, string $opcacheDirectory): array
{
    return [
        'php', '-n',
        '-d', 'zend.assertions=-1',
        '-d', 'opcache.enable=1',
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.file_update_protection=0',
        '-d', 'opcache.file_cache=' . $opcacheDirectory,
        '-d', 'opcache.jit=disable',
        '-d', 'auto_prepend_file=' . SCORE_DIRECTORY . '/peak.php',
        '-d', 'disable_functions=' . implode(',', [...$extensionFunctions, ...SHARED_HOST_DISABLED_FUNCTIONS]),
    ];
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    return $count % 2 === 1 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
}

/** @param list<float> $values */
function geometricMean(array $values): float
{
    $logSum = 0.0;
    foreach ($values as $value) {
        $logSum += log($value);
    }
    return exp($logSum / count($values));
}

/**
 * The start: the commit that added bench/score/score.php, an ancestor of
 * every attempt.
 */
function startCommit(): string
{
    $commits = runOrFail(
        ['git', 'log', '--diff-filter=A', '--format=%H', '--', 'bench/score/score.php'],
        CHECKOUT_ROOT,
        'finding the start commit',
    );
    $lines = explode("\n", trim($commits));
    $start = end($lines);
    if (!preg_match('/^[0-9a-f]{40}$/', (string) $start)) {
        throw new RuntimeException('no commit added bench/score/score.php');
    }
    return $start;
}

/** a copy of the attempt's src/ and bin/ (as they are on disk) and the start's, read-only */
function copyTrees(string $start, string $attemptTree, string $startTree): void
{
    mkdir($attemptTree);
    mkdir($startTree);
    runOrFail(['cp', '-a', CHECKOUT_ROOT . '/src', CHECKOUT_ROOT . '/bin', $attemptTree], CHECKOUT_ROOT, 'copying');
    runOrFail(
        ['sh', '-c', 'git archive "$1" src bin | tar -x -C "$2"', 'sh', $start, $startTree],
        CHECKOUT_ROOT,
        'extracting the start',
    );
    runOrFail(['chmod', '-R', 'a-w', $attemptTree, $startTree], CHECKOUT_ROOT, 'making the trees read-only');
}

function removeDirectory(string $directory): void
{
    if (is_dir($directory)) {
        exec('chmod -R u+w ' . escapeshellarg($directory) . ' && rm -rf ' . escapeshellarg($directory));
    }
}

/** a new empty directory under $scratch (each run gets its own TMPDIR, so no run can reuse a cache) */
function freshDirectory(string $scratch): string
{
    static $count = 0;
    $count++;
    $directory = sprintf('%s/fresh/%06d', $scratch, $count);
    mkdir($directory, 0700, true);
    return $directory;
}

/**
 * Copy every workload (bench/workloads/speed and bench/score/workloads)
 * into $workDirectory.
 *
 * @return list<string> their names, without ".lua"
 */
function copyWorkloads(string $workDirectory): array
{
    mkdir($workDirectory);
    $names = [];
    $files = [...glob(CHECKOUT_ROOT . '/bench/workloads/speed/*.lua'), ...glob(SCORE_DIRECTORY . '/workloads/*.lua')];
    foreach ($files as $file) {
        $name = basename($file, '.lua');
        if (in_array($name, $names, true)) {
            throw new RuntimeException("two workloads are named $name");
        }
        copy($file, "$workDirectory/$name.lua");
        $names[] = $name;
    }
    return $names;
}

/** what lua5.4 prints for a workload */
function luaOutput(string $workDirectory, string $name): string
{
    return runOrFail(['lua5.4', "$name.lua"], $workDirectory, "lua5.4 $name.lua");
}

/**
 * Transpile a directory with a side's bin/lua2php into a new directory
 * under the shared-host profile; the CPU seconds it took.
 *
 * @param list<string> $php
 */
function transpile(array $php, string $tree, string $sourceDirectory, string $outputDirectory, string $scratch): float
{
    $environment = ['PATH' => (string) getenv('PATH'), 'HOME' => "$scratch/home", 'TMPDIR' => freshDirectory($scratch)];
    $run = runCommand(
        [...$php, "$tree/bin/lua2php", $sourceDirectory, '-o', $outputDirectory],
        $sourceDirectory,
        $environment,
        RUN_TIMEOUT_SECONDS,
    );
    if ($run['status'] !== 0) {
        throw new RuntimeException(sprintf(
            "%s/bin/lua2php %s failed (exit status %d)\n%s%s",
            $tree,
            $sourceDirectory,
            $run['status'],
            lastLines($run['stdout'], 20),
            lastLines($run['stderr'], 20),
        ));
    }
    return $run['cpuSeconds'];
}

/**
 * Run a transpiled workload under the shared-host profile and check it
 * prints what lua5.4 printed.
 *
 * @param list<string> $php
 * @return array{cpuSeconds: float, peakBytes: int}
 */
function runWorkload(array $php, string $phpFile, string $workDirectory, string $expected, string $scratch): array
{
    $peakFile = freshDirectory($scratch) . '/peak';
    $environment = [
        'PATH' => (string) getenv('PATH'),
        'HOME' => "$scratch/home",
        'TMPDIR' => freshDirectory($scratch),
        'LUAPHP_SCORE_PEAK_FILE' => $peakFile,
    ];
    $run = runCommand([...$php, $phpFile], $workDirectory, $environment, RUN_TIMEOUT_SECONDS);
    if ($run['status'] !== 0 || $run['stdout'] !== $expected) {
        throw new RuntimeException(sprintf(
            "%s does not behave like lua5.4 (exit status %d)\n--- lua5.4 printed\n%s--- it printed\n%s%s",
            $phpFile,
            $run['status'],
            lastLines($expected, 20),
            lastLines($run['stdout'], 20),
            lastLines($run['stderr'], 20),
        ));
    }
    $peakBytes = is_file($peakFile) ? (int) file_get_contents($peakFile) : 0;
    if ($peakBytes <= 0) {
        throw new RuntimeException("$phpFile ended without its peak memory (bench/score/peak.php's shutdown function)");
    }
    return ['cpuSeconds' => $run['cpuSeconds'], 'peakBytes' => $peakBytes];
}

/** the existing tests/diff cases must stay as the start has them; new cases may be added */
function checkDiffCasesKept(string $start): void
{
    $changed = trim(runOrFail(
        ['git', 'diff', '--name-only', '--no-renames', '--diff-filter=DMT', $start, '--', 'tests/diff'],
        CHECKOUT_ROOT,
        'listing changed diff cases',
    ));
    if ($changed !== '') {
        throw new RuntimeException("existing tests/diff cases were changed or deleted (only new ones may be added):\n"
            . $changed);
    }
}

/** vendor/bin/phpcs of this checkout, else of the main checkout of its repository */
function phpcsPath(): string
{
    $local = CHECKOUT_ROOT . '/vendor/bin/phpcs';
    if (is_file($local)) {
        return $local;
    }
    $commonDirectory = trim(runOrFail(
        ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'],
        CHECKOUT_ROOT,
        'finding the main checkout',
    ));
    $main = dirname($commonDirectory) . '/vendor/bin/phpcs';
    if (!is_file($main)) {
        throw new RuntimeException("no vendor/bin/phpcs in this checkout or in $main: run composer install there");
    }
    return $main;
}

/**
 * The gates: composer lint, php tests/run.php, and all.lua -e"_U=true"
 * under bin/lua with the functions of optional extensions disabled.
 *
 * @param list<string> $extensionFunctions
 */
function runGates(array $extensionFunctions): void
{
    $environment = getenv();
    $gates = [
        'composer lint (phpcs)' => [['php', phpcsPath()], CHECKOUT_ROOT, 600],
        'php tests/run.php' => [['php', 'tests/run.php'], CHECKOUT_ROOT, 3600],
        'all.lua -e"_U=true" without optional extensions' => [
            [
                'php', '-n', '-d', 'disable_functions=' . implode(',', $extensionFunctions),
                CHECKOUT_ROOT . '/bin/lua', '-e_U=true', 'all.lua',
            ],
            CHECKOUT_ROOT . '/reference/lua-5.4.9-tests',
            1200,
        ],
    ];
    foreach ($gates as $what => [$command, $workingDirectory, $timeoutSeconds]) {
        $startTime = hrtime(true);
        $run = runCommand($command, $workingDirectory, $environment, $timeoutSeconds);
        $passed = $run['status'] === 0
            && (!str_starts_with($what, 'all.lua') || str_contains($run['stdout'], 'final OK !!!'));
        if (!$passed) {
            throw new RuntimeException(sprintf(
                "gate failed: %s (exit status %d)\n%s%s%s",
                $what,
                $run['status'],
                failureLines($run['stdout']),
                lastLines($run['stdout'], 20),
                lastLines($run['stderr'], 20),
            ));
        }
        printf("gate passed: %s (%.0f s)\n", $what, (hrtime(true) - $startTime) / 1e9);
    }
}
