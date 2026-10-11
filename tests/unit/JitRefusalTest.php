<?php

declare(strict_types=1);

namespace Tests\JitRefusalTest;

/*
 * PHP's function JIT with register allocation (opcache.jit T digit 0-3, R
 * digit 1 or 2) repeats loop iterations when a VM interrupt hits a
 * JIT-compiled loop (php-src#23983), so every entry point refuses to run
 * under it (LuaPhp\Runtime\JitSafety): bin/lua, bin/luac, bin/lua2php and
 * lua2php scripts write one line to stderr and exit with status 1, and the
 * embedding API throws LuaPhp\Embed\UnsafeJitSetting. Every other setting
 * runs: the tracing JIT, a function JIT without register allocation, the
 * JIT off or disabled, or not running at all (no buffer, opcache off for
 * the CLI). The check must not depend on opcache_get_status, which hosts
 * disable or restrict.
 */

// the 5 settings that refuse
const REFUSING_MODES = ['function', '1205', '1105', '1215', '1225'];

/** the 7 modes that run with the JIT options (2 more running settings: see jitSettings) */
const RUNNING_MODES = ['1005', 'tracing', '1254', '1054', '1154', 'off', 'disable'];

/** what the refusal names as the settings that work */
const SAFE_SETTINGS = ['tracing', '1005', 'disable'];

/** a host of the embedding API: Lua::run, then a sandbox of a new Environment as README.md shows */
const EMBEDDING_HOST = <<<'PHP'
    <?php
    declare(strict_types=1);
    require $argv[1] . '/src/autoload.php';
    $calls = [
        'Lua::run' => static fn (): array => LuaPhp\Embed\Lua::run('return 1'),
        'Environment' => static function (): array {
            $environment = new LuaPhp\Embed\Environment(
                limits: new LuaPhp\Embed\Limits(
                    steps: 5_000_000,
                    memoryBytes: 32 << 20,
                    seconds: 2.0,
                    outputBytes: 1 << 20,
                ),
            );
            return $environment->newSandbox()->load('return 1')->call();
        },
    ];
    $results = ['status available' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false];
    foreach ($calls as $label => $call) {
        try {
            $results[$label] = ['returned', $call()];
        } catch (Throwable $thrown) {
            $results[$label] = ['threw', get_class($thrown), $thrown->getMessage()];
        }
    }
    echo json_encode($results), "\n";
    PHP;

/**
 * A host that switches opcache.jit with ini_set while a sandbox exists,
 * as a host may at run time. Each mode in $argv[2] (comma-separated) is one
 * round: ini_set to it, then every public path that compiles or runs Lua,
 * on the sandbox made at the start (JIT off) and on new ones. The scripts
 * count their runs in the sandbox's global "runs", so a call that ran Lua
 * returns the next count. Last, a PHP function called from Lua calls
 * back into Lua with 1205 switched on: callBack catches the refusal and
 * switches back to 1005; then callBackUncaught lets it through, and the
 * host switches back to 1005 and calls into the sandbox twice more.
 */
const SWITCHING_HOST = <<<'PHP'
    <?php
    declare(strict_types=1);
    require $argv[1] . '/src/autoload.php';
    $counting = 'runs = (runs or 0) + 1 return runs';
    $environment = new LuaPhp\Embed\Environment(new LuaPhp\Embed\Loader\ArrayLoader(['count.lua' => $counting]));
    $counter = null;
    $environment->addGlobal('callBack', static function () use (&$counter): LuaPhp\Embed\Multiple {
        ini_set('opcache.jit', '1205');
        try {
            return LuaPhp\Embed\Lua::multiple('returned', $counter->call());
        } catch (Throwable $thrown) {
            return LuaPhp\Embed\Lua::multiple('threw', get_class($thrown), $thrown->getMessage());
        } finally {
            ini_set('opcache.jit', '1005');
        }
    });
    $environment->addGlobal('callBackUncaught', static function () use (&$counter): array {
        ini_set('opcache.jit', '1205');
        return $counter->call();
    });
    $sandbox = $environment->newSandbox();
    $counter = $sandbox->load($counting);
    $sandbox->load("function count() $counting end")->call();
    $callBack = $sandbox->load('return callBack()');
    $callBackUncaught = $sandbox->load('return callBackUncaught()');
    $calls = [
        'Sandbox::load' => static fn (): string => $sandbox->load('return 1')->type(),
        'Sandbox::run' => static fn (): array => $sandbox->run('count.lua')->values,
        'LuaFunction::call' => static fn (): array => $counter->call(),
        'LuaFunction::__invoke' => static fn (): array => $counter(),
        'a function handle of getGlobalHandle' => static fn (): array => $sandbox->getGlobalHandle('count')->call(),
        'Environment::newSandbox' => static fn (): bool => $environment->newSandbox()->isClosed(),
        'Environment::run' => static fn (): array => $environment->run('count.lua')->values,
        'Environment::compile' => static fn (): array => $environment->compile(['count.lua']),
        'Lua::run' => static fn (): array => LuaPhp\Embed\Lua::run('return 1'),
    ];
    $rounds = [];
    foreach (explode(',', $argv[2]) as $mode) {
        ini_set('opcache.jit', $mode);
        $results = [];
        foreach ($calls as $label => $call) {
            try {
                $results[$label] = ['returned', $call()];
            } catch (Throwable $thrown) {
                $results[$label] = ['threw', get_class($thrown), $thrown->getMessage()];
            }
        }
        $results['sandbox closed'] = $sandbox->isClosed();
        $rounds[] = [$mode, $results];
    }
    ini_set('opcache.jit', '1005');
    $caught = $callBack->call();
    $openAfterCaught = !$sandbox->isClosed();
    $workingAfterCaught = $counter->call();
    $escapedError = null;
    try {
        $escaped = ['returned', $callBackUncaught->call()];
    } catch (Throwable $thrown) {
        $escapedError = $thrown;
        $escaped = ['threw', get_class($thrown), $thrown->getMessage()];
    }
    $modeAfterEscape = ini_get('opcache.jit');
    $closedAfterEscape = $sandbox->isClosed();
    ini_set('opcache.jit', '1005');
    $later = [];
    $laterCalls = [
        'LuaFunction::call' => static fn (): array => $counter->call(),
        'Sandbox::load' => static fn (): string => $sandbox->load('return 1')->type(),
    ];
    foreach ($laterCalls as $label => $call) {
        try {
            $later[$label] = ['returned', $call()];
        } catch (Throwable $thrown) {
            $previous = $thrown->getPrevious();
            $later[$label] = [
                'threw',
                get_class($thrown),
                $thrown->getMessage(),
                $previous === null ? null : get_class($previous),
                $previous !== null && $previous === $escapedError,
            ];
        }
    }
    echo json_encode([
        'rounds' => $rounds,
        'caught' => [$caught, $openAfterCaught, $workingAfterCaught],
        'escaped' => [$escaped, $modeAfterEscape, $closedAfterEscape, $later],
    ]), "\n";
    PHP;

/**
 * The php options of the acceptance criteria's "JIT options" with
 * opcache.jit=$mode, then $changes.
 *
 * @param array<string, string> $changes
 * @return list<string>
 */
function jitOptions(string $mode, array $changes = []): array
{
    $settings = [
        'opcache.enable' => '1',
        'opcache.enable_cli' => '1',
        'opcache.jit_buffer_size' => '64M',
        'opcache.file_update_protection' => '0',
        'opcache.jit' => $mode,
        ...$changes,
    ];
    $options = [];
    foreach ($settings as $name => $value) {
        array_push($options, '-d', "$name=$value");
    }
    return $options;
}

/**
 * The 14 settings: label => [php options, the opcache.jit value refused, or null when it runs].
 *
 * @return array<string, array{list<string>, ?string}>
 */
function jitSettings(): array
{
    $settings = [];
    foreach (REFUSING_MODES as $mode) {
        $settings["opcache.jit=$mode"] = [jitOptions($mode), $mode];
    }
    foreach (RUNNING_MODES as $mode) {
        $settings["opcache.jit=$mode"] = [jitOptions($mode), null];
    }
    $settings['opcache.jit=function, opcache.jit_buffer_size=0'] = [
        jitOptions('function', ['opcache.jit_buffer_size' => '0']),
        null,
    ];
    $settings['opcache.jit=function, opcache.enable_cli=0'] = [
        jitOptions('function', ['opcache.enable_cli' => '0']),
        null,
    ];
    return $settings;
}

/** scratch files every case uses: hello.lua (prints 1), its lua2php script, the embedding host */
function prepareDirectory(): string
{
    static $directory = null;
    if ($directory !== null) {
        return $directory;
    }
    $directory = scratchDirectory() . '/jit-refusal';
    mkdir($directory);
    file_put_contents("$directory/hello.lua", "print(1)\n");
    file_put_contents("$directory/host.php", EMBEDDING_HOST);
    [$status, , $errorOutput] = runCommand(
        ['php', '-d', 'opcache.jit=disable', REPO_ROOT . '/bin/lua2php', 'hello.lua', '-o', 'hello.php'],
        '',
        $directory,
    );
    assertSame(0, $status, "lua2php hello.lua: $errorOutput");
    return $directory;
}

/**
 * One case: the command of entry point $entryPoint under $options. lua2php
 * writes to its own file, named by $caseNumber.
 *
 * @param list<string> $options
 * @return list<string>
 */
function entryPointCommand(string $entryPoint, array $options, int $caseNumber): array
{
    return match ($entryPoint) {
        'bin/lua' => ['php', ...$options, REPO_ROOT . '/bin/lua', '-e', 'print(1)'],
        'bin/luac' => ['php', ...$options, REPO_ROOT . '/bin/luac', '-p', 'hello.lua'],
        'bin/lua2php' => ['php', ...$options, REPO_ROOT . '/bin/lua2php', 'hello.lua', '-o', "out-$caseNumber.php"],
        'lua2php script' => ['php', ...$options, 'hello.php'],
        'embedding API' => ['php', ...$options, 'host.php', REPO_ROOT],
    };
}

/** a message of the refusal names the opcache.jit value and the settings that work */
function assertNamesValueAndSafeSettings(string $message, string $refusedValue, string $label): void
{
    assertTrue(
        str_contains($message, "opcache.jit=$refusedValue"),
        "$label: names opcache.jit=$refusedValue: $message",
    );
    foreach (SAFE_SETTINGS as $safeSetting) {
        assertTrue(str_contains($message, $safeSetting), "$label: names the safe setting $safeSetting: $message");
    }
}

/**
 * What one case must show: under a refusing setting, nothing on stdout,
 * one line on stderr, status 1, no output file (CLI); each API call
 * throwing the same public exception. Else the entry point's normal job.
 *
 * @param array{int, string, string} $result
 * @param ?bool $statusAvailable whether the embedding host must find opcache_get_status working (null: not checked)
 */
function assertCaseResult(
    string $entryPoint,
    ?string $refusedValue,
    array $result,
    string $directory,
    int $caseNumber,
    string $label,
    ?bool $statusAvailable,
): void {
    [$status, $output, $errorOutput] = $result;
    if ($entryPoint === 'embedding API') {
        assertSame([0, ''], [$status, $errorOutput], "$label: exit status and stderr");
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($statusAvailable !== null) {
            assertSame($statusAvailable, $results['status available'], "$label: opcache_get_status works");
        }
        foreach (['Lua::run', 'Environment'] as $call) {
            if ($refusedValue === null) {
                assertSame(['returned', [1]], $results[$call], "$label, $call");
                continue;
            }
            [$what, $class, $message] = $results[$call] + [null, null, null];
            assertSame('threw', $what, "$label, $call");
            assertSame('LuaPhp\Embed\UnsafeJitSetting', $class, "$label, $call: the exception's class");
            assertNamesValueAndSafeSettings($message, $refusedValue, "$label, $call");
        }
        return;
    }
    $outputFile = "$directory/out-$caseNumber.php";
    if ($refusedValue !== null) {
        assertSame([1, ''], [$status, $output], "$label: exit status and stdout");
        assertSame(1, substr_count($errorOutput, "\n"), "$label: one line on stderr: $errorOutput");
        assertTrue(str_ends_with($errorOutput, "\n"), "$label: one line on stderr: $errorOutput");
        assertNamesValueAndSafeSettings($errorOutput, $refusedValue, $label);
        assertTrue(!file_exists($outputFile), "$label: no output file");
        return;
    }
    $expectedOutput = match ($entryPoint) {
        'bin/lua', 'lua2php script' => "1\n",
        'bin/luac', 'bin/lua2php' => '',
    };
    assertSame([0, $expectedOutput, ''], $result, $label);
    if ($entryPoint === 'bin/lua2php') {
        $written = (string) file_get_contents($outputFile);
        assertTrue(str_contains($written, 'Standalone::runCompiledScript'), "$label: wrote $outputFile");
    }
}

/**
 * Runs the cases ([entry point, setting label, php options, refused value,
 * status available]) a few processes at a time, and checks each.
 *
 * @param list<array{string, string, list<string>, ?string, ?bool}> $cases
 */
function runCases(array $cases): void
{
    $directory = prepareDirectory();
    foreach (array_chunk($cases, 6, true) as $batch) {
        $running = [];
        foreach ($batch as $caseNumber => [$entryPoint, , $options]) {
            $running[$caseNumber] = startProcess(entryPointCommand($entryPoint, $options, $caseNumber), $directory);
        }
        foreach ($batch as $caseNumber => [$entryPoint, $settingLabel, , $refusedValue, $statusAvailable]) {
            $result = finishProcess($running[$caseNumber]);
            $label = "$entryPoint, $settingLabel";
            assertCaseResult($entryPoint, $refusedValue, $result, $directory, $caseNumber, $label, $statusAvailable);
        }
    }
}

function test_every_entry_point_refuses_a_function_jit_with_register_allocation_and_only_that(): void
{
    $cases = [];
    foreach (['bin/lua', 'bin/luac', 'bin/lua2php', 'lua2php script', 'embedding API'] as $entryPoint) {
        foreach (jitSettings() as $settingLabel => [$options, $refusedValue]) {
            // with opcache running for the CLI, the embedding host finds opcache_get_status working
            $statusAvailable = !str_contains($settingLabel, 'enable_cli=0');
            $cases[] = [$entryPoint, $settingLabel, $options, $refusedValue, $statusAvailable];
        }
    }
    assertSame(70, count($cases), '5 entry points x 14 settings');
    runCases($cases);
}

function test_the_refusal_does_not_depend_on_opcache_get_status(): void
{
    $cases = [];
    foreach (['bin/lua', 'embedding API'] as $entryPoint) {
        foreach ([['1205', '1205'], ['1254', null]] as [$mode, $refusedValue]) {
            $variants = [
                'disable_functions=opcache_get_status' => ['disable_functions' => 'opcache_get_status'],
                'opcache.restrict_api=/nonexistent' => ['opcache.restrict_api' => '/nonexistent'],
            ];
            foreach ($variants as $variantLabel => $changes) {
                $settingLabel = "opcache.jit=$mode, $variantLabel";
                $cases[] = [$entryPoint, $settingLabel, jitOptions($mode, $changes), $refusedValue, false];
            }
        }
    }
    assertSame(8, count($cases), '2 entry points x 2 modes x 2 ways to take opcache_get_status away');
    runCases($cases);
}

/**
 * A host may switch opcache.jit with ini_set after it made a sandbox. Every
 * call from PHP into Lua code repeats the check: while a refused mode is on,
 * each public path that compiles or runs Lua throws UnsafeJitSetting before
 * any Lua runs (the run counts go on without a gap), and the sandbox stays
 * open, so the same calls work again once the host switches back. A PHP
 * function called from Lua may catch the refusal of its call back; one
 * that lets it through stops the run, as any PHP exception that passes
 * through Lua code: the host gets the UnsafeJitSetting, and later calls
 * get SandboxClosed with it as the previous exception.
 */
function test_a_sandbox_refuses_lua_while_the_host_switched_a_refused_jit_on(): void
{
    $directory = prepareDirectory();
    file_put_contents("$directory/switching-host.php", SWITCHING_HOST);
    $modes = ['off', '1205', '1005', '1205', 'off'];
    [$status, $output, $errorOutput] = runCommand(
        ['php', ...jitOptions('off'), 'switching-host.php', REPO_ROOT, implode(',', $modes)],
        '',
        $directory,
    );
    assertSame([0, ''], [$status, $errorOutput], "exit status and stderr: $output");
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $runs = 0;  // the sandbox's count of runs: 4 calls of each round run a counting script in it
    foreach ($report['rounds'] as $round => [$mode, $results]) {
        $label = "round $round, opcache.jit=$mode";
        assertSame(false, $results['sandbox closed'], "$label: the sandbox stays open");
        unset($results['sandbox closed']);
        assertSame(9, count($results), "$label: 9 public paths");
        if ($mode === '1205') {
            foreach ($results as $call => $result) {
                [$what, $class, $message] = $result + [null, null, null];
                assertSame(['threw', 'LuaPhp\Embed\UnsafeJitSetting'], [$what, $class], "$label, $call");
                assertNamesValueAndSafeSettings($message, '1205', "$label, $call");
            }
            continue;
        }
        $expected = [
            'Sandbox::load' => ['returned', 'function'],
            'Sandbox::run' => ['returned', [++$runs]],
            'LuaFunction::call' => ['returned', [++$runs]],
            'LuaFunction::__invoke' => ['returned', [++$runs]],
            'a function handle of getGlobalHandle' => ['returned', [++$runs]],
            'Environment::newSandbox' => ['returned', false],
            'Environment::run' => ['returned', [1]],
            'Environment::compile' => ['returned', []],
            'Lua::run' => ['returned', [1]],
        ];
        assertSame($expected, $results, $label);
    }
    // a PHP function that Lua called and that called back into Lua with 1205 on: refused, and it may catch it
    [$caught, $openAfterCaught, $workingAfterCaught] = $report['caught'];
    [$what, $class, $message] = $caught + [null, null, null];
    assertSame(['threw', 'LuaPhp\Embed\UnsafeJitSetting'], [$what, $class], 'a call back into Lua from a PHP function');
    assertNamesValueAndSafeSettings($message, '1205', 'a call back into Lua from a PHP function');
    assertSame(true, $openAfterCaught, 'the sandbox stays open after the caught refusal');
    assertSame([++$runs], $workingAfterCaught, 'the sandbox works at 1005 after the caught refusal');
    // one that lets the refusal through: it passed through running Lua code, whose frames it abandoned, so it
    // stops the run (AGENTS.md: Calls into Lua) and reaches the host; later calls are SandboxClosed
    [$escaped, $modeAfterEscape, $closedAfterEscape, $later] = $report['escaped'];
    $label = 'a refusal a PHP function let through';
    [$what, $class, $message] = $escaped + [null, null, null];
    assertSame(['threw', 'LuaPhp\Embed\UnsafeJitSetting'], [$what, $class], "$label: what the host got");
    assertNamesValueAndSafeSettings($message, '1205', $label);
    assertSame('1205', $modeAfterEscape, "$label: nothing switched the mode back");
    assertSame(true, $closedAfterEscape, "$label: the sandbox is closed");
    $closedMessage = 'the sandbox is closed: a LuaPhp\Embed\UnsafeJitSetting stopped a run in it';
    $expected = ['threw', 'LuaPhp\Embed\SandboxClosed', $closedMessage, 'LuaPhp\Embed\UnsafeJitSetting', true];
    assertSame(['LuaFunction::call' => $expected, 'Sandbox::load' => $expected], $later, "$label, then 1005");
}

/** the exception the embedding API throws is public: in LuaPhp\Embed, not in Internal, not marked internal */
function test_the_refusal_exception_is_public(): void
{
    $class = new \ReflectionClass(\LuaPhp\Embed\UnsafeJitSetting::class);
    assertSame('LuaPhp\Embed', $class->getNamespaceName());
    assertTrue(!str_contains((string) $class->getDocComment(), '@internal'), 'the class is not marked @internal');
    assertTrue($class->isSubclassOf(\Exception::class), 'an \Exception, as the other errors of LuaPhp\Embed');
}
