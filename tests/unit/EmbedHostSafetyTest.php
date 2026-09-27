<?php

declare(strict_types=1);

namespace Tests\EmbedHostSafetyTest;

/*
 * A script must never crash or hang the host: each scenario below runs
 * in its own PHP process at memory_limit=128M under a timeout, and must
 * end in a LimitExceeded, a normal result or a normal PHP exception
 * (never a PHP fatal error, a crash or a hang). The scenarios are the ways
 * a review found around the limits: '__call' cycles, host functions that
 * catch what stopped the run, converting huge values to PHP, library
 * calls that copy big strings, compiling big scripts, single calls with
 * many results.
 */

/** runs scenario $name (see SCENARIOS); its outcome */
function outcomeOf(string $name): array
{
    static $driver = null;
    if ($driver === null) {
        $driver = scratchDirectory() . '/host-safety-driver.php';
        file_put_contents($driver, SCENARIOS);
    }
    [$status, $output, $errors] = runCommand(
        ['timeout', '-s', 'KILL', '60', 'php', '-d', 'memory_limit=128M', $driver, REPO_ROOT, $name]
    );
    assertSame(0, $status, "$name: the host process survives (exit $status): " . substr($errors . $output, 0, 600));
    $outcome = json_decode($output, true);
    assertTrue(\is_array($outcome), "$name: " . substr($output, 0, 600));
    return $outcome;
}

/** each scenario => the outcome it must have (a prefix of it) */
function assertOutcomes(array $expected): void
{
    $failures = [];
    foreach ($expected as $name => $outcome) {
        try {
            $actual = outcomeOf($name);
            if (\array_slice($actual, 0, \count($outcome)) !== $outcome) {
                $failures[] = "$name: expected " . json_encode($outcome)
                    . ', got ' . substr(json_encode($actual), 0, 300);
            }
        } catch (\Tests\AssertionFailed $failed) {
            $failures[] = $failed->getMessage();
        }
    }
    assertSame([], $failures);
}

/** the driver: php driver.php <repo> <scenario> prints the scenario's outcome as JSON */
const SCENARIOS = <<<'PHP'
    <?php
    declare(strict_types=1);
    require $argv[1] . '/src/autoload.php';

    use LuaPhp\Embed\{Environment, Limits, LimitExceeded, LuaFunction, RunContext, ScriptError};
    use LuaPhp\Embed\Loader\ArrayLoader;

    const LIMITS = ['steps' => 10_000_000, 'memoryBytes' => 32 << 20, 'seconds' => 2.0];
    /** a table that shares its subtables: 2^20 leaves as a tree */
    const DAG = 'local t = {1} for i = 1, 20 do t = {t, t} end';
    /** a script of $statements statements a = a + 1 (200,000: 1.2 MB of source, 400,000 instructions) */
    function large(int $statements = 200000): string
    {
        return "local a = 0\n" . str_repeat('a = a + 1;', $statements) . "\nreturn a";
    }
    const LONG_KEY_CHAIN = 'local k = string.rep("k", 1000000) local t = {} local c = t'
        . ' for i = 1, 90 do c[k] = {} c = c[k] end ';

    /**
     * runs script $code in a new sandbox (after $setup($sandbox)) of an
     * Environment ($prepare($environment) first): its values, or what it
     * threw
     */
    function script(
        string $code,
        array $limits = LIMITS,
        ?Closure $setup = null,
        bool $allowLoad = false,
        array $files = [],
        ?Closure $prepare = null,
    ): array {
        $environment = new Environment(
            loader: new ArrayLoader(['x.lua' => $code] + $files),
            limits: $limits === [] ? Limits::none() : new Limits(...$limits),
            allowLoad: $allowLoad,
        );
        if ($prepare !== null) {
            $prepare($environment);
        }
        return outcome(static function () use ($environment, $setup): array {
            $sandbox = $environment->newSandbox();
            if ($setup !== null) {
                $setup($sandbox);
            }
            $result = $sandbox->run('x.lua');
            return [$result->values, $result->output];
        });
    }

    function outcome(Closure $run): array
    {
        try {
            return ['result', $run()];
        } catch (LimitExceeded $exceeded) {
            return ['limit', $exceeded->limit, $exceeded->usage->peakMemoryBytes];
        } catch (Throwable $thrown) {
            return [$thrown::class, substr($thrown->getMessage(), 0, 200), \strlen($thrown->getMessage())];
        }
    }

    function withSandbox(array $limits, Closure $use): array
    {
        $sandbox = (new Environment(limits: new Limits(...$limits)))->newSandbox();
        return outcome(static fn () => $use($sandbox));
    }

    $hostData = static fn (string $data): Closure => static function ($sandbox) use ($data): void {
        $sandbox->setGlobal('data', $data);
    };
    $scenarios = [
        // 1. '__call' cycles
        'call-cycle' => static fn () => script('local t = {} setmetatable(t, {__call = t}) t() return 1'),
        'tail-call-cycle' => static fn () => script('local t = {} setmetatable(t, {__call = t}) return t()'),
        'call-cycle-in-pcall' => static fn () => script(
            'local t = {} setmetatable(t, {__call = t}) print(pcall(t)) return 1'
        ),
        'call-cycle-without-steps' => static fn () => script(
            'local t = {} setmetatable(t, {__call = t}) t() return 1',
            ['seconds' => 60.0],
        ),
        // 2. host functions that catch what stopped the run
        'host-maps-limit' => static fn () => script(
            'pcall(host, function() while true do end end) print("AFTER LIMIT") return 42',
            ['steps' => 1000],
            static function ($sandbox): void {
                $sandbox->setGlobal('host', static function (LuaFunction $f): void {
                    try {
                        $f();
                    } catch (Throwable) {
                        throw new ScriptError('mapped');
                    }
                });
            },
        ),
        'host-maps-seconds' => static fn () => script(
            'local ok, e = pcall(sleeper) print("AFTER LIMIT") return ok, e',
            ['seconds' => 0.005],
            static function ($sandbox): void {
                $sandbox->setGlobal('sleeper', static function (): void {
                    usleep(30000);
                    throw new ScriptError('nope');
                });
            },
        ),
        'host-maps-charge' => static fn () => script(
            'local m = require("m") pcall(m.charge) print("AFTER LIMIT") return 42',
            ['steps' => 1000],
            null,
            false,
            [],
            static function (Environment $environment): void {
                $environment->addModule('m', static fn (RunContext $context): array => [
                    'charge' => static function () use ($context): void {
                        try {
                            $context->chargeSteps(1_000_000);
                        } catch (Throwable) {
                            throw new ScriptError('mapped');
                        }
                    },
                ]);
            },
        ),
        // 3. converting values to PHP
        'result-dag' => static fn () => script(DAG . ' return t'),
        'error-dag' => static fn () => script(DAG . ' error(t)'),
        'result-dag-no-limits' => static fn () => script(DAG . ' return t', []),
        'result-dag-17-small-budget' => static fn () => script(
            'local t = {1} for i = 1, 17 do t = {t, t} end return t',
            ['memoryBytes' => 1 << 20],
        ),
        'handle-to-array' => static fn () => withSandbox(LIMITS, static function ($sandbox): string {
            $sandbox->load(DAG . ' dag = t')->call();
            return 'converted ' . \count($sandbox->getGlobalHandle('dag')->toArray());
        }),
        'get-global' => static fn () => withSandbox(LIMITS, static function ($sandbox): string {
            $sandbox->load(DAG . ' dag = t')->call();
            return 'converted ' . \count($sandbox->getGlobal('dag'));
        }),
        'array-argument' => static fn () => script(DAG . ' return take(t)', LIMITS, static function ($sandbox): void {
            $sandbox->setGlobal('take', static fn (array $a): int => \count($a));
        }),
        'long-key-chain' => static fn () => withSandbox(LIMITS, static function ($sandbox): string {
            [$chain] = $sandbox->load(LONG_KEY_CHAIN . 'c[1] = print return t')->call();
            for ($depth = 0; !array_is_list($chain); $depth++) {  // (the last level, {print}, is a list)
                $chain = reset($chain);
            }
            return "$depth levels";
        }),
        'long-key-bad-value' => static fn () => script(LONG_KEY_CHAIN . 'c[1.5] = 1 return t'),
        // 4. library calls that copy big strings
        'require-long-name' => static fn () => script('pcall(require, string.rep("x", 16000000)) return 1'),
        'unpack-c-host-data' => static fn () => script(
            'return #(string.unpack("c70000000", data))',
            LIMITS,
            $hostData(str_repeat('x', 70 << 20)),
        ),
        'unpack-s-host-data' => static fn () => script(
            'return #(string.unpack("<s4", data))',
            LIMITS,
            $hostData(str_pad(pack('V', 70 << 20), (70 << 20) + 4, 'x')),
        ),
        'unpack-z-host-data' => static fn () => script(
            'return #(string.unpack("z", data))',
            LIMITS,
            $hostData((static function (): string {
                $data = str_repeat('x', (70 << 20) + 1);  // (in place: a concatenation would copy it)
                $data[70 << 20] = "\0";
                return $data;
            })()),
        ),
        'tostring-host-name' => static fn () => script(
            'return #tostring(setmetatable({}, {__name = data}))',
            LIMITS,
            $hostData(str_repeat('x', 70 << 20)),
        ),
        'type-error-host-name' => static fn () => script(
            'return math.floor(setmetatable({}, {__name = data}))',
            LIMITS,
            $hostData(str_repeat('x', 70 << 20)),
        ),
        'arithmetic-error-host-name' => static fn () => script(
            'local x = setmetatable({}, {__name = data}) return x + 1',
            LIMITS,
            $hostData(str_repeat('x', 70 << 20)),
        ),
        'error-host-data' => static fn () => script('error(data)', LIMITS, $hostData(str_repeat('x', 70 << 20))),
        'wrap-error-host-data' => static fn () => script(
            'coroutine.wrap(function () error(data, 0) end)()',
            LIMITS,
            $hostData(str_repeat('x', 70 << 20)),
        ),
        'print-host-data' => static fn () => script(
            'print(data)',
            ['memoryBytes' => 32 << 20],
            $hostData(str_repeat('x', 70 << 20)),
        ),
        'traceback-module-name' => static fn () => script(
            'local name = string.rep("x", 20000000)'
            . ' package.loaded[name] = {f = function (n)'
            . ' if n == 0 then error("deep") end return (package.loaded[name].f(n - 1)) end}'
            . ' package.loaded[name].f(15)'
        ),
        // 5. compiling big scripts
        'compile-large' => static fn () => script(
            large(),
            ['steps' => 10_000_000, 'memoryBytes' => 1 << 20, 'seconds' => 0.5],
        ),
        'compile-large-host-room' => static fn () => script(large(), ['steps' => 10_000_000]),
        'compile-small-small-budget' => static fn () => script(
            'local t = {} for i = 1, 10 do t[i] = i * 2 end return t[10]',
            ['memoryBytes' => 1 << 20],
        ),
        'compile-medium' => static fn () => script(large(2000)),
        'compile-too-big' => static fn () => script(large(1_000_000)),
        'load-large' => static fn () => script(
            'local f, e = load(string.rep("a = 1;", 200000)) return f ~= nil, e',
            LIMITS,
            null,
            true,
        ),
        'require-large' => static fn () => script('return require("big")', LIMITS, null, false, ['big.lua' => large()]),
        'compile-ahead-large' => static function (): array {
            $environment = new Environment(loader: new ArrayLoader(['x.lua' => large()]));
            return outcome(static fn (): array => array_map(
                static fn ($error): string => $error->getMessage(),
                $environment->compile(['x.lua']),
            ));
        },
        // 6. single calls with many results
        'unpack-results' => static fn () => script('return select("#", table.unpack({}, 1, 999900))'),
        'byte-results' => static fn () => script(
            'local s = string.rep("x", 999000) return select("#", string.byte(s, 1, -1))'
        ),
        'codepoint-results' => static fn () => script(
            'local s = string.rep("x", 999000) return select("#", utf8.codepoint(s, 1, -1))'
        ),
        'tostring-lua-name' => static fn () => script(
            'local n = string.rep("x", 20000000) return #tostring(setmetatable({}, {__name = n}))'
        ),
        'require-name-8m' => static fn () => script('pcall(require, string.rep("x", 8000000)) return 1'),
        'vararg-copies' => static fn () => script(
            'local big = {} for i = 1, 200000 do big[i] = i end local kept = {}'
            . ' local function keep(...) for i = 1, 1000 do kept[i] = {...} end end'
            . ' keep(table.unpack(big)) return #kept'
        ),
    ];
    echo json_encode(($scenarios[$argv[2]])(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    PHP;

function test_call_cycles_stop_at_the_limits(): void
{
    assertOutcomes([
        'call-cycle' => ['limit', 'steps'],
        'tail-call-cycle' => ['limit', 'steps'],
        'call-cycle-in-pcall' => ['limit', 'steps'],
        // without a steps limit, Lua's own "stack overflow" (lua5.4 gets there too, after hours of moving its stack)
        'call-cycle-without-steps' => [\LuaPhp\Embed\RuntimeError::class, 'x.lua:1: stack overflow'],
    ]);
}

function test_a_host_function_cannot_let_a_stopped_run_go_on(): void
{
    assertOutcomes([
        'host-maps-limit' => ['limit', 'steps'],
        'host-maps-seconds' => ['limit', 'seconds'],
        'host-maps-charge' => ['limit', 'steps'],
    ]);
}

function test_converting_huge_values_stops_at_the_memory_limit(): void
{
    assertOutcomes([
        'result-dag' => ['limit', 'memory'],
        'error-dag' => ['limit', 'memory'],
        'result-dag-no-limits' => ['limit', 'memory'],
        'result-dag-17-small-budget' => ['limit', 'memory'],
        'handle-to-array' => ['limit', 'memory'],
        'get-global' => ['limit', 'memory'],
        'array-argument' => ['limit', 'memory'],
    ]);
    // long keys are not copied into paths
    assertSame(['result', '90 levels'], outcomeOf('long-key-chain'));
    $bad = outcomeOf('long-key-bad-value');
    assertSame(\LuaPhp\Embed\ConversionError::class, $bad[0], json_encode($bad));
    assertTrue($bad[2] < 10_000, 'a path shortens long keys: ' . $bad[2] . ' bytes');
}

function test_library_calls_check_big_string_copies(): void
{
    assertOutcomes([
        'require-long-name' => ['limit', 'memory'],
        'unpack-c-host-data' => ['limit', 'memory'],
        'unpack-s-host-data' => ['limit', 'memory'],
        'unpack-z-host-data' => ['limit', 'memory'],
        'tostring-host-name' => ['limit', 'memory'],
        'type-error-host-name' => ['limit', 'memory'],
        'arithmetic-error-host-name' => ['limit', 'memory'],
        'error-host-data' => ['limit', 'memory'],
        'wrap-error-host-data' => ['limit', 'memory'],
        'print-host-data' => ['limit', 'memory'],
        // a traceback names functions after their module in package.loaded
        'traceback-module-name' => ['limit', 'memory'],
    ]);
}

function test_compiling_big_scripts_stops_at_the_limits(): void
{
    assertOutcomes([
        'compile-large' => ['limit', 'memory'],
        'compile-large-host-room' => ['limit', 'memory'],
        // what fits compiles, under a small budget too
        'compile-small-small-budget' => ['result', [[20], '']],
        'compile-medium' => ['result', [[2000], '']],
        'compile-too-big' => ['limit', 'source'],
        'load-large' => ['limit', 'memory'],
        'require-large' => ['limit', 'memory'],
        'compile-ahead-large' => ['result', ['x.lua' => 'not enough memory']],
    ]);
}

function test_single_calls_with_many_results_check_memory(): void
{
    assertOutcomes([
        'unpack-results' => ['limit', 'memory'],
        'byte-results' => ['limit', 'memory'],
        'codepoint-results' => ['limit', 'memory'],
        'tostring-lua-name' => ['limit', 'memory'],
        'require-name-8m' => ['limit', 'memory'],
        // copying many values in one instruction ('...') counts them as steps, so the memory is checked
        'vararg-copies' => ['limit', 'memory'],
    ]);
}
