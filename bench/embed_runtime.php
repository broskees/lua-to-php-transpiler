<?php

declare(strict_types=1);

/*
 * The runtime costs of embedding (AGENTS.md "Embedding conventions"), in
 * one warm process:
 *
 *     php bench/embed_runtime.php [eval|include] [runs]
 *
 * - creating a state with the SAFE libraries (sandboxed, with a budget),
 *   and with all libraries as bin/lua opens them;
 * - PHP memory per empty SAFE state kept alive;
 * - the embedding brief's benchmark compiled with step counting and run
 *   with every limit set, against the same code compiled without and run
 *   without a budget. "eval": the code is eval'd (as load() does without
 *   a disk cache); "include": it is included from files (as the disk cache
 *   and lua2php output are), so opcache (and its JIT, when on) compile it:
 *   run with -d opcache.enable_cli=1 -d opcache.file_update_protection=0,
 *   and -d opcache.jit=tracing -d opcache.jit_buffer_size=64M for the JIT.
 */

require __DIR__ . '/../src/autoload.php';

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Lib\StandardLibraries;
use LuaPhp\Runtime\Budget;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;

const SAFE = ['base', 'package', 'string', 'table', 'math', 'utf8', 'coroutine', 'os.time', 'os.date', 'os.clock', 'os.difftime'];

const BENCHMARK = <<<'LUA'
    local s = 0
    for i = 1, 3000000 do s = s + i % 7 end
    local t = {}
    for i = 1, 200000 do t[#t + 1] = tostring(i) end
    return s, #table.concat(t)
    LUA;

$mode = $argv[1] ?? 'eval';
$runs = (int) ($argv[2] ?? 7);

function median(array $values): float
{
    sort($values);
    return $values[intdiv(\count($values), 2)];
}

function safeState(): Coroutine
{
    $L = Coroutine::newState();
    $L->globalState->countSteps = true;
    StandardLibraries::openSelected($L, SAFE, true, false);
    $L->globalState->setBudget(new Budget(steps: 5_000_000, memoryBytes: 32 << 20, seconds: 2.0, outputBytes: 1 << 20, coroutines: 1000, callDepth: 1000));
    return $L;
}

function allState(): Coroutine
{
    $L = Coroutine::newState();
    StandardLibraries::openAll($L);
    return $L;
}

/** milliseconds per state, median of $runs batches of 1000 */
function creation(callable $create, int $runs): float
{
    $times = [];
    for ($run = 0; $run < $runs; $run++) {
        $started = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $create();
        }
        $times[] = (hrtime(true) - $started) / 1e6 / 1000;
    }
    return median($times);
}

/** bytes of PHP memory per state kept alive */
function memoryPerState(callable $create): float
{
    gc_collect_cycles();
    $keep = [];
    $before = memory_get_usage();
    for ($i = 0; $i < 1000; $i++) {
        $keep[] = $create();
    }
    return (memory_get_usage() - $before) / 1000;
}

/** the factory of the benchmark's code, eval'd or included */
function factory(string $source, string $mode, string $name): \Closure
{
    $php = Emitter::PREAMBLE . "\nreturn " . $source . ";\n";
    if ($mode === 'eval') {
        return eval($php);
    }
    $file = sys_get_temp_dir() . '/luaphp-embed-bench-' . getmypid() . "-$name.php";
    file_put_contents($file, "<?php\n" . $php);
    $factory = include $file;
    unlink($file);
    return $factory;
}

/** milliseconds of one run of the benchmark */
function benchmarkRun(\Closure $factory, bool $budgeted): float
{
    $L = Coroutine::newState();
    StandardLibraries::openSelected($L, SAFE, true, false);
    if ($budgeted) {
        $L->globalState->setBudget(new Budget(steps: 100_000_000, memoryBytes: 256 << 20, seconds: 60.0, outputBytes: 1 << 20, coroutines: 1000, callDepth: 1000));
    }
    $main = ChunkLoader::instantiate(Compiler::compile(BENCHMARK, '=benchmark'), $factory);
    $main->getUpval(0)->v = $L->globalState->globals;
    gc_collect_cycles();
    $started = hrtime(true);
    [$status, $results] = Calls::protectedCall($L, $main, []);
    $milliseconds = (hrtime(true) - $started) / 1e6;
    if ($status !== Lua::LUA_OK || $results !== [8999997, 1088895]) {
        throw new \RuntimeException('benchmark failed: ' . json_encode($results));
    }
    return $milliseconds;
}

for ($i = 0; $i < 200; $i++) {  // warm up
    safeState();
    allState();
}
printf("| state creation, SAFE + budget | %.4f ms |\n", creation(safeState(...), $runs));
printf("| state creation, all libraries | %.4f ms |\n", creation(allState(...), $runs));
printf("| memory per empty SAFE state | %.0f bytes |\n", memoryPerState(safeState(...)));

$proto = Compiler::compile(BENCHMARK, '=benchmark');
$plain = factory(Emitter::emitChunk($proto), $mode, 'plain');
$counted = factory(Emitter::emitChunk($proto, true), $mode, 'counted');
$plainTimes = [];
$countedTimes = [];
for ($run = 0; $run < $runs; $run++) {  // interleaved
    $plainTimes[] = benchmarkRun($plain, false);
    $countedTimes[] = benchmarkRun($counted, true);
}
$jit = \function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false) ? ', JIT' : '';
printf("| benchmark ($mode$jit), no step counting, no budget | %.1f ms |\n", median($plainTimes));
printf("| benchmark ($mode$jit), step counting, every limit | %.1f ms |\n", median($countedTimes));
printf("| ratio | %.3f |\n", median($countedTimes) / median($plainTimes));
