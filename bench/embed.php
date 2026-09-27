<?php

/**
 * The embedding API's costs (LuaPhp\Embed), in one warm process:
 *
 *     php bench/embed.php [eval|include] [runs]
 *
 * - a new sandbox with the SAFE libraries and the default limits, and
 *   with Libraries::ALL;
 * - Environment::run of the brief's discount example (a module factory,
 *   a PHP function, a print, a table back);
 * - PHP memory per empty sandbox kept alive, and per sandbox kept alive
 *   after running the discount example;
 * - the brief's benchmark with every limit set against Limits::none().
 *   "eval": scripts compiled in memory (the Environment's cache without a
 *   cacheDir); "include": from a cacheDir, so opcache (and its JIT, when
 *   on) compile them: run with -d opcache.enable_cli=1
 *   -d opcache.file_update_protection=0, and -d opcache.jit=tracing
 *   -d opcache.jit_buffer_size=64M for the JIT.
 * Results: bench/results/embed.md.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/embed_functions.php';

use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Libraries;
use LuaPhp\Embed\Limits;
use LuaPhp\Embed\Loader\ArrayLoader;

$mode = $argv[1] ?? 'eval';
$runs = (int) ($argv[2] ?? 7);
$cacheDirectory = null;
if ($mode === 'include') {
    $cacheDirectory = sys_get_temp_dir() . '/luaphp-embed-bench-' . getmypid();
}

$orders = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
$context = ['customerId' => 42, 'orders' => $orders];
$safe = shop($cacheDirectory);
if ($cacheDirectory !== null) {  // (the scripts from the cacheDir, included)
    $safe->run('discounts/loyalty.lua', [['total' => 180.0, 'items' => 3]], ['customerId' => 42, 'orders' => []]);
    $safe = shop($cacheDirectory);
}
$all = new Environment(libraries: Libraries::ALL);
$runLoyalty = static fn () => $safe->run('discounts/loyalty.lua', [['total' => 180.0, 'items' => 3]], $context);
for ($i = 0; $i < 2000; $i++) {  // warm up
    $safe->newSandbox();
    $all->newSandbox();
    $runLoyalty();
}
printf("new SAFE sandbox (default limits): %.4f ms\n", perCall($runs, 20000, static fn () => $safe->newSandbox()));
printf("new ALL sandbox: %.4f ms\n", perCall($runs, 20000, static fn () => $all->newSandbox()));
printf("Environment::run of the discount example: %.4f ms\n", perCall($runs, 5000, $runLoyalty));

gc_collect_cycles();
$kept = [];
$before = memory_get_usage();
for ($i = 0; $i < 1000; $i++) {
    $kept[] = $safe->newSandbox($context);
}
printf("memory per empty SAFE sandbox: %.1f KB\n", (memory_get_usage() - $before) / 1000 / 1024);
$kept = [];
gc_collect_cycles();
$before = memory_get_usage();
for ($i = 0; $i < 1000; $i++) {
    $sandbox = $safe->newSandbox($context);
    $sandbox->run('discounts/loyalty.lua', [['total' => 180.0, 'items' => 3]]);
    $kept[] = $sandbox;
}
printf("memory per SAFE sandbox after the discount example: %.1f KB\n", (memory_get_usage() - $before) / 1000 / 1024);
$kept = [];
gc_collect_cycles();

$newBenchmarkEnvironments = static fn (): array => [
    new Environment(
        loader: new ArrayLoader(['benchmark.lua' => BENCHMARK]),
        cacheDir: $cacheDirectory,
        limits: new Limits(
            steps: 1_000_000_000,
            memoryBytes: 256 * 1024 * 1024,
            seconds: 60.0,
            outputBytes: 1024 * 1024,
            coroutines: 1000,
            callDepth: 1000,
        ),
    ),
    new Environment(
        loader: new ArrayLoader(['benchmark.lua' => BENCHMARK]),
        cacheDir: $cacheDirectory,
        limits: Limits::none(),
    ),
];
[$limited, $unlimited] = $newBenchmarkEnvironments();
$limited->run('benchmark.lua');  // compiles (and, with a cacheDir, writes its file)
$unlimited->run('benchmark.lua');
if ($cacheDirectory !== null) {
    // as a later process would: new Environments find the scripts in the cacheDir and include them
    [$limited, $unlimited] = $newBenchmarkEnvironments();
    $limited->run('benchmark.lua');
    $unlimited->run('benchmark.lua');
}
$times = ['limits' => [], 'none' => []];
for ($run = 0; $run < $runs; $run++) {
    foreach (['limits' => $limited, 'none' => $unlimited] as $label => $environment) {
        $start = hrtime(true);
        $result = $environment->run('benchmark.lua');
        $times[$label][] = (hrtime(true) - $start) / 1e6;
    }
}
$steps = $limited->run('benchmark.lua')->usage->steps;
printf("brief's benchmark, Limits::none(): %.1f ms\n", median($times['none']));
printf("brief's benchmark, every limit set: %.1f ms (%d steps)\n", median($times['limits']), $steps);
printf("ratio: %.3f\n", median($times['limits']) / median($times['none']));
if ($cacheDirectory !== null) {
    exec('rm -rf ' . escapeshellarg($cacheDirectory));
}
