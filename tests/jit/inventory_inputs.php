<?php

/**
 * The inventory input set of tests/jit/inventory.php and how each input
 * runs under the detector (tests/jit/detector.php). Needs tests/harness.php.
 *
 * The classes:
 *   i    every tests/diff/*.lua, transpiled (bin/lua2php tests/diff -o ...)
 *        and run as saved PHP, as Lua2PhpTest runs them (arguments "first"
 *        and "second arg", load()'s cache in a new LUAPHP_CACHE_DIR)
 *   ii   every tests/diff/*.lua under bin/lua, as tests/run.php runs them
 *   iii  bench/workloads/speed/*.lua, transpiled and run as saved PHP
 *   iv   every official test file tests/official.sh runs but heavy.lua,
 *        under bin/lua as official.sh runs them
 *   v    every tests/diff/*.lua as in i, with every instruction in compact
 *        form (tests/unit/compact_forms.php, prepended to bin/lua2php and
 *        to each run, as Lua2PhpTest's compact runs do)
 * bin/lua evals the code it emits, which PHP never JIT-compiles: under it
 * only the runtime's own PHP is traced; saved PHP is traced too.
 */

declare(strict_types=1);

namespace Tests\TracingJit;

const INPUT_CLASSES = [
    'i' => 'tests/diff as saved lua2php PHP',
    'ii' => 'tests/diff under bin/lua',
    'iii' => 'speed workloads as saved lua2php PHP',
    'iv' => 'official test files under bin/lua',
    'v' => 'tests/diff as saved lua2php PHP in compact form',
];

/** seconds an input may run (official.sh allows 300; the JIT's report slows compiling) */
const INPUT_TIMEOUT_SECONDS = 900;

const COMPACT_FORMS = REPO_ROOT . '/tests/unit/compact_forms.php';

/**
 * The inputs of $classes (keys of INPUT_CLASSES), prepared in
 * $scratchDirectory (transpiled there): each runs as `php <detector
 * options> <arguments>` with the environment changes `env` takes, from
 * its directory.
 *
 * @param list<string> $classes
 * @return list<array{class: string, name: string, arguments: list<string>, environment: list<string>,
 *     directory: string}>
 */
function inventoryInputs(array $classes, string $scratchDirectory): array
{
    $diffDirectory = realpath(REPO_ROOT . '/tests/diff');
    $diffFiles = glob("$diffDirectory/*.lua");
    sort($diffFiles);
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $inputs = [];
    foreach ($classes as $class) {
        if ($class === 'i' || $class === 'v') {
            $prepend = $class === 'v' ? ['-d', 'auto_prepend_file=' . COMPACT_FORMS] : [];
            $outputDirectory = transpileProject($diffDirectory, "$scratchDirectory/$class", $prepend);
            $cacheDirectory = "$scratchDirectory/$class-cache";
            mkdir($cacheDirectory, 0700);
            foreach ($diffFiles as $file) {
                $inputs[] = [
                    'class' => $class,
                    'name' => 'diff/' . basename($file),
                    'arguments' => [
                        ...$prepend, '-d', 'memory_limit=4G', "$outputDirectory/" . basename($file, '.lua') . '.php',
                        'first', 'second arg',
                    ],
                    'environment' => ["LUAPHP_CACHE_DIR=$cacheDirectory"],
                    'directory' => $outputDirectory,
                ];
            }
        } elseif ($class === 'ii') {
            foreach ($diffFiles as $file) {
                $inputs[] = [
                    'class' => $class,
                    'name' => 'diff/' . basename($file),
                    'arguments' => [$ourProgram, basename($file)],
                    'environment' => ['-u', 'LUAPHP_CACHE_DIR'],
                    'directory' => $diffDirectory,
                ];
            }
        } elseif ($class === 'iii') {
            $speedDirectory = realpath(REPO_ROOT . '/bench/workloads/speed');
            $outputDirectory = transpileProject($speedDirectory, "$scratchDirectory/$class", []);
            $cacheDirectory = "$scratchDirectory/$class-cache";
            mkdir($cacheDirectory, 0700);
            $files = glob("$speedDirectory/*.lua");
            sort($files);
            foreach ($files as $file) {
                $inputs[] = [
                    'class' => $class,
                    'name' => 'speed/' . basename($file),
                    'arguments' => ["$outputDirectory/" . basename($file, '.lua') . '.php'],
                    'environment' => ["LUAPHP_CACHE_DIR=$cacheDirectory"],
                    'directory' => $outputDirectory,
                ];
            }
        } elseif ($class === 'iv') {
            foreach (officialTestFiles() as $file) {
                if (basename($file) === 'heavy.lua') {
                    continue;
                }
                $inputs[] = [
                    'class' => $class,
                    'name' => 'official/' . basename($file),
                    'arguments' => [$ourProgram, '-e_U=true _soft=true _port=true _nomsg=true', basename($file)],
                    'environment' => ['-u', 'LUAPHP_CACHE_DIR'],
                    'directory' => realpath(OFFICIAL_TESTS_DIRECTORY),
                ];
            }
        } else {
            throw new \InvalidArgumentException("unknown input class $class");
        }
    }
    return $inputs;
}

/**
 * bin/lua2php $projectDirectory -o $outputDirectory with the php options
 * $phpOptions, and the project's other files copied along (as
 * Lua2PhpTest does); returns the output directory's real path
 *
 * @param list<string> $phpOptions
 */
function transpileProject(string $projectDirectory, string $outputDirectory, array $phpOptions): string
{
    // tests/diff/modules/syntax_error.lua does not compile (status 1); the rest is transpiled
    [$status, , $errorOutput] = runCommand(
        ['php', ...$phpOptions, REPO_ROOT . '/bin/lua2php', $projectDirectory, '-o', $outputDirectory]
    );
    if ($status !== 0 && !str_contains($errorOutput, 'modules/syntax_error.lua')) {
        throw new \RuntimeException("bin/lua2php $projectDirectory: $errorOutput");
    }
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($projectDirectory, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!str_ends_with($file->getFilename(), '.lua')) {
            $copy = $outputDirectory . substr($file->getPathname(), \strlen($projectDirectory));
            @mkdir(\dirname($copy), 0777, true);
            copy($file->getPathname(), $copy);
        }
    }
    return realpath($outputDirectory);
}

/**
 * Runs each input under the detector's options with opcache.jit=$mode and
 * the hot counters of $profile (HOT_COUNTER_PROFILES), $parallel at a time
 * (stdout discarded, stderr, the JIT's report, in a file of
 * $scratchDirectory that is removed once read), and returns per input: its
 * exit status, how many loop traces the JIT compiled, and the flagged ones
 * (trace, verdict and place, see tracePlace).
 *
 * @param list<array{class: string, name: string, arguments: list<string>, environment: list<string>,
 *     directory: string}> $inputs
 * @return list<array{input: array, status: int, loopTraces: int, withoutInterruptCheck: int,
 *     flagged: list<array{trace: array, verdict: array, place: string}>}>
 */
function examineInputs(array $inputs, string $mode, string $profile, int $parallel, string $scratchDirectory): array
{
    $results = [];
    $running = [];
    $next = 0;
    while ($next < \count($inputs) || $running !== []) {
        while ($next < \count($inputs) && \count($running) < $parallel) {
            $input = $inputs[$next];
            $dumpFile = "$scratchDirectory/input-$next.dump";
            $process = proc_open(
                [
                    'timeout', '-s', 'KILL', (string) INPUT_TIMEOUT_SECONDS, 'env', ...$input['environment'],
                    'php', ...detectorOptions($mode, $profile), ...$input['arguments'],
                ],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dumpFile, 'w']],
                $pipes,
                $input['directory'],
            );
            $running[$next] = [$process, $dumpFile];
            $next++;
        }
        usleep(20000);
        foreach ($running as $index => [$process, $dumpFile]) {
            $status = proc_get_status($process);
            if ($status['running']) {
                continue;
            }
            proc_close($process);
            unset($running[$index]);
            $results[$index] = examineDump($inputs[$index], $status['exitcode'], $dumpFile);
            unlink($dumpFile);
        }
    }
    ksort($results);
    return array_values($results);
}

/** the detector's findings in the JIT report $dumpFile of one input's run */
function examineDump(array $input, int $status, string $dumpFile): array
{
    $result = ['input' => $input, 'status' => $status, 'loopTraces' => 0, 'withoutInterruptCheck' => 0,
        'flagged' => []];
    foreach (loopTraceVerdicts($dumpFile) as ['trace' => $trace, 'verdict' => $verdict]) {
        $result['loopTraces']++;
        if (!$verdict['interruptCheck']) {
            $result['withoutInterruptCheck']++;
        }
        if ($verdict['flagged']) {
            $result['flagged'][] = ['trace' => $trace, 'verdict' => $verdict, 'place' => tracePlace($trace)];
        }
    }
    return $result;
}

/**
 * Where a trace's loop is: "src/Lib/TableLib.php:244 LuaPhp\Lib\TableLib::concat"
 * for our PHP; for PHP that bin/lua2php or load()'s cache generated, the
 * Lua file (or "a load() chunk"), the emitted function and the pc of the
 * instruction the loop's line is part of.
 */
function tracePlace(array $trace): string
{
    $root = realpath(REPO_ROOT) . '/';
    $file = $trace['file'];
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    $header = $lines === false ? '' : implode("\n", \array_slice($lines, 0, 4));
    if (preg_match('~^// Generated by bin/lua2php from (\S+)~m', $header, $match) === 1) {
        return generatedPlace($match[1], $lines, $trace['line']);
    }
    if (str_contains($header, 'A compiled Lua chunk (LuaPhp\Runtime\CacheDirectory)')) {
        return generatedPlace('a load() chunk', $lines, $trace['line']);
    }
    $shownFile = str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file;
    return "$shownFile:{$trace['line']} {$trace['function']}";
}

/** "<lua file> $function_0_1 <3,9> [12] LEN ; line 4" for line $line of emitted code */
function generatedPlace(string $luaFile, array $lines, int $line): string
{
    $instruction = '(no instruction)';
    $function = '(no function)';
    for ($index = $line - 1; $index >= 0; $index--) {
        // "[12] LEN 10 0 0 ; line 4", or "[3..9] constant table constructor"
        $isComment = preg_match('~^\s*// (\[[\d.]+\] .*)$~', $lines[$index], $match) === 1;
        if ($instruction === '(no instruction)' && $isComment) {
            $instruction = $match[1];
        }
        if (preg_match('~^\s*(\$function_[\d_]+) = static function~', $lines[$index], $match) === 1) {
            $definition = preg_match('~// function (<[^>]*>)~', $lines[$index + 1] ?? '', $lineRange) === 1
                ? ' ' . $lineRange[1] : '';
            $function = $match[1] . $definition;
            break;
        }
    }
    return "$luaFile $function $instruction";
}

/**
 * $operation (an IP use, loopTraceVerdicts) with the file and first line
 * of each method it names: "COALESCE at 0010 in
 * LuaPhp\Runtime\LuaTable::partCount (src/Runtime/LuaTable.php:528)"
 * (Reflection: the runtime's classes must load)
 */
function withDefinitions(string $operation): string
{
    $root = realpath(REPO_ROOT) . '/';
    return preg_replace_callback(
        '/ in ([A-Za-z_\\\\][\w\\\\]*)::(\w+)/',
        static function (array $match) use ($root): string {
            if (!method_exists($match[1], $match[2])) {
                return $match[0];
            }
            $method = new \ReflectionMethod($match[1], $match[2]);
            $file = (string) $method->getFileName();
            $shownFile = str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file;
            return "{$match[0]} ($shownFile:{$method->getStartLine()})";
        },
        $operation,
    );
}
