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

/** the exception the embedding API throws is public: in LuaPhp\Embed, not in Internal, not marked internal */
function test_the_refusal_exception_is_public(): void
{
    $class = new \ReflectionClass(\LuaPhp\Embed\UnsafeJitSetting::class);
    assertSame('LuaPhp\Embed', $class->getNamespaceName());
    assertTrue(!str_contains((string) $class->getDocComment(), '@internal'), 'the class is not marked @internal');
    assertTrue($class->isSubclassOf(\Exception::class), 'an \Exception, as the other errors of LuaPhp\Embed');
}
