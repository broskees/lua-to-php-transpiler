<?php

declare(strict_types=1);

namespace Tests\EmbedLimitsTest;

use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Libraries;
use LuaPhp\Embed\LimitExceeded;
use LuaPhp\Embed\Limits;
use LuaPhp\Embed\Loader\ArrayLoader;
use LuaPhp\Embed\RuntimeError;
use LuaPhp\Embed\SandboxClosed;
use LuaPhp\Embed\StdoutSink;

/*
 * The embedding API's limits, output and library profiles, through the
 * public API: every row of the brief's limits table (at memory_limit=128M,
 * in one PHP process that must survive them all), print and warn into the
 * sinks, the SAFE profile as lua5.4 reports what it leaves out, and load()
 * with allowLoad. The runtime underneath has its own tests
 * (EmbedRuntimeTest).
 */

/** the brief's limits */
const BRIEF_LIMITS = ['steps' => 5_000_000, 'memoryBytes' => 32 * 1024 * 1024, 'seconds' => 2.0, 'outputBytes' => 1024 * 1024];

/** runs each case (name => [code, limits]) in its own sandbox of one process; prints a JSON line per case */
const DRIVER = <<<'PHP'
    <?php
    declare(strict_types=1);
    require $argv[1] . '/src/autoload.php';
    foreach (json_decode(file_get_contents($argv[2]), true) as $name => [$code, $limits]) {
        $environment = new LuaPhp\Embed\Environment(
            loader: new LuaPhp\Embed\Loader\ArrayLoader([$name => $code]),
            limits: new LuaPhp\Embed\Limits(...$limits),
        );
        $environment->addGlobal('sleep', static function (float $seconds): void {
            usleep((int) ($seconds * 1e6));
        });
        $sandbox = $environment->newSandbox();
        try {
            $result = $sandbox->run($name);
            $outcome = ['result', $result->output];
        } catch (LuaPhp\Embed\LimitExceeded $exceeded) {
            $usage = $exceeded->usage;
            try {
                $sandbox->run($name);
                $closed = false;
            } catch (LuaPhp\Embed\SandboxClosed) {
                $closed = true;
            }
            $outcome = ['limit', $exceeded->limit, $usage->steps, $usage->peakMemoryBytes, $usage->milliseconds, $closed];
        } catch (Throwable $thrown) {
            $outcome = [$thrown::class, $thrown->getMessage()];
        }
        echo json_encode([$name, $outcome]), "\n";
    }
    echo json_encode(['done', memory_get_peak_usage(true)]), "\n";
    PHP;

/**
 * @param array<string, array{string, array<string, mixed>}> $cases
 * @return array<string, list<mixed>> name => outcome
 */
function runCases(array $cases): array
{
    $directory = scratchDirectory() . '/embed-limits-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("$directory/driver.php", DRIVER);
    file_put_contents("$directory/cases.json", json_encode($cases));
    [$status, $output, $errors] = runCommand(['php', '-d', 'memory_limit=128M', "$directory/driver.php", REPO_ROOT, "$directory/cases.json"]);
    assertSame(0, $status, "the host process survives: $errors");
    $outcomes = [];
    foreach (explode("\n", rtrim($output, "\n")) as $line) {
        [$name, $outcome] = json_decode($line, true);
        $outcomes[$name] = $outcome;
    }
    assertTrue(isset($outcomes['done']), 'every case ran');
    assertTrue($outcomes['done'] < 128 * 1024 * 1024);
    return $outcomes;
}

function test_the_brief_s_limits_table(): void
{
    // no memoryBytes: what memory_limit leaves is the limit (and no steps limit, which a growing table would reach first)
    $noMemoryLimit = ['memoryBytes' => null, 'steps' => null, 'seconds' => 60.0] + BRIEF_LIMITS;
    $cases = [
        'loop.lua' => ['while true do end', BRIEF_LIMITS],
        'pcall.lua' => ['pcall(function() while true do end end)', BRIEF_LIMITS],
        'rep.lua' => ['local s = ("x"):rep(1e9)', BRIEF_LIMITS],
        'doubling.lua' => ['local s = ("x"):rep(2^20) for i = 1, 20 do s = s .. s end', BRIEF_LIMITS],
        'table.lua' => ['local t = {} for i = 1, 1e9 do t[i] = ("x"):rep(100) end', BRIEF_LIMITS],
        'rep-room.lua' => ['local s = ("x"):rep(1e9)', $noMemoryLimit],
        'doubling-room.lua' => ['local s = ("x"):rep(2^20) for i = 1, 20 do s = s .. s end', $noMemoryLimit],
        'table-room.lua' => ['local t = {} for i = 1, 1e9 do t[i] = ("x"):rep(100) end', $noMemoryLimit],
        'overflow.lua' => ['local function f() return f() + 1 end print(pcall(f))', BRIEF_LIMITS],
        'coroutines.lua' => ['local t = {} for i = 1, 100000 do t[i] = coroutine.create(function() coroutine.yield() end) coroutine.resume(t[i]) end', BRIEF_LIMITS],
        'sleep.lua' => ['sleep(3) local x = 1', BRIEF_LIMITS],
    ];
    $outcomes = runCases($cases);
    $expectedLimits = [
        'loop.lua' => 'steps', 'pcall.lua' => 'steps', 'rep.lua' => 'memory', 'doubling.lua' => 'memory', 'table.lua' => 'memory',
        'rep-room.lua' => 'memory', 'doubling-room.lua' => 'memory', 'table-room.lua' => 'memory',
        'coroutines.lua' => 'coroutines', 'sleep.lua' => 'seconds',
    ];
    foreach ($expectedLimits as $name => $limit) {
        $outcome = $outcomes[$name];
        assertSame(['limit', $limit], \array_slice($outcome, 0, 2), "$name: " . json_encode($outcome));
        assertSame(true, $outcome[5], "$name: the sandbox is closed afterwards");
    }
    // usage: the steps limit was passed, the time counted the host function
    assertTrue($outcomes['loop.lua'][2] > 5_000_000, 'steps used');
    assertTrue($outcomes['sleep.lua'][4] >= 3000, 'milliseconds include the PHP function');
    assertTrue($outcomes['table.lua'][3] > 16 * 1024 * 1024, 'peak memory');
    // Lua's own stack overflow stays a normal error, as lua5.4 prints it
    $directory = scratchDirectory() . '/embed-overflow';
    @mkdir($directory);
    file_put_contents("$directory/overflow.lua", $cases['overflow.lua'][0]);
    assertSame(['result', runCommand(['lua5.4', 'overflow.lua'], '', $directory)[1]], $outcomes['overflow.lua']);
}

/** the scripts of test_output_is_collected_and_capped */
const OUTPUT_SCRIPTS = [
    'big.lua' => 'print(("x"):rep(2e6))',
    'mixed.lua' => "print(1, 2.5, 'x', nil, true, 1e100)\nprint()\nprint(setmetatable({}, {__tostring = function () return 'object' end}))",
    'warn.lua' => 'warn("@on") warn("a", "b") warn("@off") warn("c") warn("@on") warn("d")',
    'silent.lua' => 'warn("not shown")',
];

/** the limit $run exceeds */
function limitOf(callable $run): string
{
    try {
        $run();
    } catch (LimitExceeded $exceeded) {
        return $exceeded->limit;
    }
    throw new \RuntimeException('no limit was exceeded');
}

function test_output_is_collected_and_capped(): void
{
    $environment = new Environment(loader: new ArrayLoader(OUTPUT_SCRIPTS), limits: new Limits(...BRIEF_LIMITS));
    $levels = ob_get_level();
    ob_start();
    ob_start();
    echo 'host output';
    try {
        $exceeded = null;
        try {
            $environment->run('big.lua');
        } catch (LimitExceeded $exceeded) {
        }
        $mixed = $environment->run('mixed.lua');
        $warnings = $environment->run('warn.lua');
        $silent = $environment->run('silent.lua');
        $inner = ob_get_contents();
        ob_end_clean();
        $outer = ob_get_contents();
        ob_end_clean();
    } finally {
        while (ob_get_level() > $levels) {
            ob_end_clean();
        }
    }
    assertSame('host output', $inner, "the host's buffers hold only what the host wrote");
    assertSame('', $outer);
    assertSame('output', $exceeded?->limit);
    assertSame(0, $exceeded->usage->outputBytes, 'nothing of it was written');
    $directory = scratchDirectory() . '/embed-output';
    @mkdir($directory);
    foreach (OUTPUT_SCRIPTS as $name => $code) {
        file_put_contents("$directory/$name", $code);
    }
    [, $referenceOutput] = runCommand(['lua5.4', 'mixed.lua'], '', $directory);
    [, , $referenceWarnings] = runCommand(['lua5.4', 'warn.lua'], '', $directory);
    assertSame(preg_replace('/0x[0-9a-f]+/', '0x?', $referenceOutput), preg_replace('/0x[0-9a-f]+/', '0x?', $mixed->output));
    assertSame(\strlen($mixed->output), $mixed->usage->outputBytes);
    assertSame($referenceWarnings, $warnings->output, 'warnings go to the sink once on, as lua5.4 writes them');
    assertSame('', $silent->output);
}

function test_host_sinks(): void
{
    $pieces = [];
    $environment = new Environment(
        loader: new ArrayLoader(['hello.lua' => 'print("hello", 1) warn("@on") warn("careful")']),
        output: static function (string $bytes) use (&$pieces): void {
            $pieces[] = $bytes;
        },
    );
    assertSame('', $environment->run('hello.lua')->output);
    assertSame("hello\t1\nLua warning: careful\n", implode('', $pieces));
    // StdoutSink: PHP's output, through the host's buffers, which stay open
    $levels = ob_get_level();
    ob_start();
    $result = (new Environment(loader: new ArrayLoader(['hello.lua' => 'print("hello") io.write("more\n")']), libraries: Libraries::ALL, output: new StdoutSink()))->run('hello.lua');
    $echoed = ob_get_clean();
    assertSame($levels, ob_get_level());
    assertSame("hello\nmore\n", $echoed);
    assertSame('', $result->output);
    assertSame(11, $result->usage->outputBytes);
    // a sink that throws stops the run
    $failing = new Environment(loader: new ArrayLoader(['p.lua' => 'pcall(print, "x")']), output: static function (): never {
        throw new \RuntimeException('disk full');
    });
    assertSame('disk full', assertThrows(\RuntimeException::class, static fn () => $failing->run('p.lua')));
}

function test_the_safe_profile_leaves_out_what_the_brief_lists(): void
{
    $cases = ['io.open("/etc/passwd")', 'os.execute("id")', 'debug.getinfo(1)', 'load("return 1")', 'dofile("x")', 'string.dump(print)', 'os.exit()', 'require("os").setlocale("C")'];
    $script = "local load, pcall, print, select = load, pcall, print, select\nio, debug, dofile, loadfile = nil\n"
        . "for _, name in ipairs{'execute', 'exit', 'setlocale', 'getenv', 'remove', 'rename', 'tmpname'} do os[name] = nil end\n"
        . "string.dump, _G.load = nil\n";
    foreach ($cases as $code) {
        $script .= 'print(select(2, pcall(load(' . var_export($code, true) . ', "=test"))))' . "\n";
    }
    [$status, $output, $errors] = runCommand(['lua5.4', '-'], $script);
    assertSame(0, $status, $errors);
    $expected = explode("\n", rtrim($output, "\n"));
    $sandbox = (new Environment())->newSandbox();
    foreach ($cases as $index => $code) {
        assertSame($expected[$index], assertThrows(RuntimeError::class, static fn () => $sandbox->load($code, '=test')->call()), $code);
    }
    assertSame(["attempt to index a nil value (global 'io')", "attempt to call a nil value (field 'execute')",
        "attempt to index a nil value (global 'debug')", "attempt to call a nil value (global 'load')"],
        array_map(static fn (string $line): string => substr($line, \strlen('test:1: ')), \array_slice($expected, 0, 4)), "the brief's messages");
    // exactly the brief's globals and library functions
    [$globals, $os, $package, $stringHasDump] = $sandbox->load(<<<'LUA'
        local function keys(t) local k = {} for name in pairs(t) do k[#k + 1] = name end table.sort(k) return table.concat(k, " ") end
        return keys(_G), keys(os), keys(package), string.dump ~= nil
        LUA)->call();
    assertSame('_G _VERSION assert collectgarbage coroutine error getmetatable ipairs math next os package pairs pcall print rawequal rawget rawlen rawset require select setmetatable string table tonumber tostring type utf8 warn xpcall', $globals);
    assertSame('clock date difftime time', $os);
    assertSame('loaded', $package);
    assertSame(false, $stringHasDump);
    // collectgarbage: collect, count and step only
    assertSame([0, true], $sandbox->load('return collectgarbage("collect"), math.type(collectgarbage("count")) == "float"')->call());
    assertSame("test:1: bad argument #1 to 'collectgarbage' (invalid option 'stop')",
        assertThrows(RuntimeError::class, static fn () => $sandbox->load('collectgarbage("stop")', '=test')->call()));
}

function test_load_with_allow_load(): void
{
    $binary = runCommand(['lua5.4', '-e', 'io.write(string.dump(function () return 1 end))'])[1];
    $reference = rtrim(runCommand(['lua5.4', '-e', 'print(select(2, load(string.dump(function () return 1 end), "=b", "t")))'])[1], "\n");
    $environment = new Environment(allowLoad: true, limits: new Limits(steps: 100_000));
    $sandbox = $environment->newSandbox();
    $sandbox->setGlobal('binary', $binary);
    $cache = (new \ReflectionProperty(Environment::class, 'cache'))->getValue($environment);
    $before = $cache->memoryEntryCount();
    assertSame([2, 3, null, $reference], $sandbox->load('return load("return 1 + 1")(), load("return 1 + 1", "=same")() + 1, load(binary, "=b")')->call());
    assertSame($before + 3, $cache->memoryEntryCount(), "load() compiles into the Environment's cache: the chunk and two loaded chunks");
    $sandbox->load('return load("return 1 + 1")()')->call();
    assertSame($before + 4, $cache->memoryEntryCount(), 'a new chunk; the loaded one was cached');
    // the run's limits apply to what it loads
    assertSame('steps', limitOf(static fn () => $sandbox->load('load("while true do end")()')->call()));
    assertThrows(SandboxClosed::class, static fn () => $sandbox->load('return 1'));
}

function test_limits_are_per_run(): void
{
    $environment = new Environment(loader: new ArrayLoader(['spin.lua' => 'local n = ... for i = 1, n do end return n']), limits: new Limits(steps: 100_000));
    $a = $environment->newSandbox();
    $b = $environment->newSandbox();
    // a limit is per run: runs of 60,000 steps pass one after another, interleaved with another sandbox
    $usages = [];
    foreach ([$a, $b, $a, $b, $a] as $sandbox) {
        $result = $sandbox->run('spin.lua', [30_000]);
        assertSame([30_000], $result->values);
        $usages[] = $result->usage->steps;
    }
    assertTrue(min($usages) > 30_000 && max($usages) < 100_000, json_encode($usages));
    assertSame('steps', limitOf(static fn () => $a->run('spin.lua', [1_000_000])));
    assertTrue($a->isClosed() && !$b->isClosed());
    assertSame([10], $b->run('spin.lua', [10])->values);
}

function test_a_sandbox_run_from_another_keeps_its_own_limits(): void
{
    $inner = new Environment(loader: new ArrayLoader(['spin.lua' => 'local n = ... for i = 1, n do end return n']), limits: new Limits(steps: 10_000));
    $outer = new Environment(loader: new ArrayLoader(['main.lua' => <<<'LUA'
        local small = inner(100)
        local stopped = inner(1000000)
        local n = 0
        for i = 1, 200000 do n = n + 1 end
        return small, stopped, n
        LUA]), limits: new Limits(steps: 2_000_000));
    $sandboxes = [];
    $outer->addGlobal('inner', static function (int $n) use ($inner, &$sandboxes): string {
        $sandboxes[] = $sandbox = $inner->newSandbox();
        try {
            return 'ran ' . $sandbox->run('spin.lua', [$n])->values[0];
        } catch (LimitExceeded $exceeded) {
            return 'inner stopped: ' . $exceeded->limit;
        }
    });
    $result = $outer->run('main.lua');
    assertSame(['ran 100', 'inner stopped: steps', 200000], $result->values);
    assertTrue($result->usage->steps > 200_000, 'the outer run counted its own loop');
    assertTrue(!$sandboxes[0]->isClosed() && $sandboxes[1]->isClosed());
}
