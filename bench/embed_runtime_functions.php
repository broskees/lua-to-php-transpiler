<?php

/** The functions of the embedding runtime benchmark bench/embed_runtime.php. */

declare(strict_types=1);

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Lib\StandardLibraries;
use LuaPhp\Runtime\Budget;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;

const SAFE = [
    'base', 'package', 'string', 'table', 'math', 'utf8', 'coroutine', 'os.time', 'os.date', 'os.clock', 'os.difftime',
];

const BENCHMARK = <<<'LUA'
    local s = 0
    for i = 1, 3000000 do s = s + i % 7 end
    local t = {}
    for i = 1, 200000 do t[#t + 1] = tostring(i) end
    return s, #table.concat(t)
    LUA;

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
    $L->globalState->setBudget(new Budget(
        steps: 5_000_000,
        memoryBytes: 32 << 20,
        seconds: 2.0,
        outputBytes: 1 << 20,
        coroutines: 1000,
        callDepth: 1000,
    ));
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
        $L->globalState->setBudget(new Budget(
            steps: 100_000_000,
            memoryBytes: 256 << 20,
            seconds: 60.0,
            outputBytes: 1 << 20,
            coroutines: 1000,
            callDepth: 1000,
        ));
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
