<?php

declare(strict_types=1);

namespace Tests\EmbedSandboxTest;

use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Libraries;
use LuaPhp\Embed\Limits;
use LuaPhp\Embed\Loader\ArrayLoader;
use LuaPhp\Embed\Lua;
use LuaPhp\Embed\LuaFunction;
use LuaPhp\Embed\LuaTable;
use LuaPhp\Embed\Result;
use LuaPhp\Embed\RunContext;
use LuaPhp\Embed\RuntimeError;
use LuaPhp\Embed\SandboxClosed;
use LuaPhp\Embed\ScriptError;
use LuaPhp\Embed\SyntaxError;

/*
 * Environment -> Sandbox -> Result (LuaPhp\Embed): the brief's example,
 * errors as lua5.4 reports them, modules and require, isolation between
 * sandboxes, handles and Lua's collector, closing, and running without
 * changing the host PHP process.
 */

/** the brief's discount script (its print is a comment until output sinks exist: same lines) */
const LOYALTY = <<<'LUA'
    local orders = require("shop.orders")
    local cart = ...

    local recent = orders.recent(10)
    -- print("checked " .. #recent .. " orders")

    if #recent >= 5 then
      return { discount = cart.total * 0.10, reason = "loyal customer (" .. #recent .. " orders)" }
    end
    return { discount = 0, reason = "none" }
    LUA;

final class OrderRepository
{
    public ?\Throwable $failure = null;

    /** @return list<array{id: int}> */
    public function recentFor(int $customerId, int $limit): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return \array_slice([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5], ['id' => 6]], 0, min($limit, 5));
    }
}

/** the brief's environment, with the loyalty script changed by $change (a line => replacement) */
function shop(array $change = []): Environment
{
    $lines = explode("\n", LOYALTY);
    foreach ($change as $line => $replacement) {
        $lines[$line - 1] = $replacement;
    }
    $environment = new Environment(
        loader: new ArrayLoader(['discounts/loyalty.lua' => implode("\n", $lines)]),
        libraries: Libraries::SAFE,
        limits: new Limits(steps: 5_000_000, memoryBytes: 32 * 1024 * 1024, seconds: 2.0, outputBytes: 1024 * 1024),
    );
    $environment->addModule('text', [
        'slug' => static fn (string $s): string => trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s)), '-'),
        'version' => '1.0',
    ]);
    $environment->addModule('shop.orders', static fn (RunContext $context): array => [
        'recent' => static function (int $limit) use ($context): array {
            if ($limit > 100) {
                throw new ScriptError('orders.recent: limit must be 100 or less');
            }
            return $context->get('orders')->recentFor($context->get('customerId'), $limit);
        },
    ]);
    return $environment;
}

function runLoyalty(Environment $environment, OrderRepository $orders, array $cart = ['total' => 180.0, 'items' => 3]): Result
{
    return $environment->run('discounts/loyalty.lua', args: [$cart], context: ['customerId' => 42, 'orders' => $orders]);
}

function test_the_brief_example(): void
{
    $orders = new OrderRepository();
    $result = runLoyalty(shop(), $orders);
    assertSame([['discount' => 18.0, 'reason' => 'loyal customer (5 orders)']], $result->values);
    assertTrue($result->usage->milliseconds >= 0.0);

    $failures = [
        [[4 => 'local recent = orders.recent(500)'], 'discounts/loyalty.lua:4: orders.recent: limit must be 100 or less'],
        [[4 => 'local recent = orders.recent(2.5)'], "discounts/loyalty.lua:4: bad argument #1 to 'recent' (number has no integer representation)"],
        [[4 => 'local recent = orders.recent("ten")'], "discounts/loyalty.lua:4: bad argument #1 to 'recent' (number expected, got string)"],
    ];
    foreach ($failures as [$change, $message]) {
        assertSame($message, assertThrows(RuntimeError::class, static fn () => runLoyalty(shop($change), $orders)));
    }
    assertSame("discounts/loyalty.lua:8: attempt to perform arithmetic on a nil value (field 'total')",
        assertThrows(RuntimeError::class, static fn () => runLoyalty(shop(), $orders, ['items' => 3])));
    // the script's pcall catches a ScriptError and goes on
    $result = runLoyalty(shop([4 => 'local ok, message = pcall(function() return orders.recent(500) end) local recent = {ok, message, 3, 4, 5}']), $orders);
    assertSame('loyal customer (5 orders)', $result->values[0]['reason']);
    $result = runLoyalty(shop([4 => 'local recent = {pcall(function() return orders.recent(500) end)}', 7 => 'if true then return recent end if false then']), $orders);
    assertSame([[false, 'discounts/loyalty.lua:4: orders.recent: limit must be 100 or less']], $result->values);
    // a PDOException from the repository: that same exception, which the script's pcall never sees
    $orders->failure = new \PDOException('SQLSTATE[HY000]: General error');
    $caught = null;
    try {
        runLoyalty(shop([4 => 'local ok, recent = pcall(orders.recent, 10) recent = {}']), $orders);
    } catch (\PDOException $caught) {
    }
    assertTrue($caught === $orders->failure);
}

function test_lower_level_use(): void
{
    $lua = shop()->newSandbox(context: ['customerId' => 42, 'orders' => new OrderRepository()]);
    $lua->setGlobal('config', ['tax' => 0.08]);
    [$addTax] = $lua->load('return function(x) return x * (1 + config.tax) end', chunkName: '=inline')->call();
    assertTrue($addTax instanceof LuaFunction);
    assertSame([108.0], $addTax(100));
    assertSame([108.0], $addTax->call(100));
    assertSame(['tax' => 0.08], $lua->getGlobal('config'));
    $t = $lua->getGlobalHandle('config');
    assertTrue($t instanceof LuaTable);
    $t->set('tax', 0.1);
    assertSame(0.1, $t->get('tax'));
    assertSame([110.00000000000001], $addTax(100));
    assertSame([2], Lua::run('return 1 + 1'));
    assertSame([6], Lua::run('local a, b = ... return a * b', [2, 3]));
    assertSame("[string \"return 1 +\"]:1: unexpected symbol near <eof>", assertThrows(SyntaxError::class, static fn () => Lua::run('return 1 +')));
}

function test_runtime_errors_carry_lua_details(): void
{
    $script = <<<'LUA'
        local function f(x)
          error("boom " .. x)
        end
        function g(x) return f(x) end
        local t = {}
        function t:m(x) g(x) end
        t:m(1)
        LUA;
    $directory = scratchDirectory() . '/embed-traceback';
    @mkdir($directory);
    file_put_contents("$directory/tb.lua", $script);
    [, , $reference] = runCommand(['lua5.4', 'tb.lua'], '', $directory);
    $lines = explode("\n", rtrim($reference, "\n"));
    assertSame("\t[C]: in ?", array_pop($lines), 'lua.c calls the chunk from C');
    $environment = new Environment(loader: new ArrayLoader(['tb.lua' => $script]));
    $error = null;
    try {
        $environment->run('tb.lua');
    } catch (RuntimeError $error) {
    }
    assertSame(substr(array_shift($lines), \strlen('lua5.4: ')), $error?->luaMessage);
    assertSame($error->luaMessage, $error->getMessage());
    assertSame($error->luaMessage, $error->value);
    assertSame(implode("\n", $lines), $error->traceback);

    $sandbox = $environment->newSandbox();
    $cases = [
        'error({code = 7})' => [['code' => 7], 'table: 0x'],
        'error(setmetatable({}, {__tostring = function () return "custom" end}))' => [[], 'custom'],
        'error(setmetatable({}, {__tostring = function () error("inner") end}))' => [[], '(error object is a table value)'],
        'error()' => [null, 'nil'],
        'error(42)' => [42, '42'],
        'error(true)' => [true, 'true'],
        'error("plain", 0)' => ['plain', 'plain'],
        'local t = {} t.self = t error(t)' => [LuaTable::class, 'table: 0x'],
    ];
    foreach ($cases as $code => [$value, $message]) {
        $error = null;
        try {
            $sandbox->load($code)->call();
        } catch (RuntimeError $error) {
        }
        assertSame($value, \is_object($error?->value) ? $error->value::class : $error?->value, $code);
        assertTrue(str_starts_with($error->luaMessage, $message), "$code: $error->luaMessage");
        assertTrue(str_starts_with($error->traceback, "stack traceback:\n\t[C]: in function 'error'"), "$code: $error->traceback");
    }
    // Lua's own stack overflow stays a normal, catchable error
    [$ok, $message] = $sandbox->load('local function f() return f() + 1 end return pcall(f)', '=deep')->call();
    assertSame(false, $ok);
    assertTrue(str_contains($message, 'stack overflow'), $message);
    assertSame([3], $sandbox->load('return 3')->call(), 'still usable');
}

/** $text as a Lua string literal */
function luaString(string $text): string
{
    return '"' . preg_replace_callback('/[^\x20-\x7e]|["\\\\]/', static fn (array $match): string => sprintf('\\%03d', \ord($match[0])), $text) . '"';
}

function test_syntax_errors_as_lua54_reports_them(): void
{
    $chunks = ["x = = 1", "local t = {\n1,\n2", "for i = 1 do end", "return 'unfinished", "goto nowhere", "x = 1\n\n  )", "local x <const> = 1; x = 2", "\xEF\xBB\xBFreturn"];
    $script = '';
    foreach ($chunks as $chunk) {
        foreach (['@x.lua', '=inline', null] as $name) {
            $script .= 'io.write(select(2, load(' . luaString($chunk) . ($name === null ? '' : ', ' . luaString($name)) . ")) or 'loaded', '\\0')\n";
        }
    }
    [$status, $output, $errors] = runCommand(['lua5.4', '-'], $script);
    assertSame(0, $status, $errors);
    $expected = explode("\0", rtrim($output, "\0"));
    $sandbox = (new Environment())->newSandbox();
    $index = 0;
    foreach ($chunks as $chunk) {
        foreach (['@x.lua', '=inline', null] as $name) {
            $error = null;
            try {
                $sandbox->load($chunk, $name);
            } catch (SyntaxError $error) {
            }
            assertSame($expected[$index++], $error?->getMessage() ?? 'loaded', var_export([$chunk, $name], true));
            assertSame($name ?? $chunk, $error?->chunkName);
            assertTrue(\is_int($error->luaLine) && preg_match('/:' . $error->luaLine . ': /', $error->getMessage()) === 1, $error->getMessage());
        }
    }
    // text only: a binary chunk is refused as load(s, name, "t") refuses it
    $binary = runCommand(['lua5.4', '-e', 'io.write(string.dump(function () return 1 end))'])[1];
    $reference = runCommand(['lua5.4', '-e', 'print(select(2, load(string.dump(function () return 1 end), "=b", "t")))'])[1];
    $error = null;
    try {
        $sandbox->load($binary, '=b');
    } catch (SyntaxError $error) {
    }
    assertSame(rtrim($reference, "\n"), $error?->getMessage());
    assertSame(null, $error->luaLine);
    // a module that does not compile is a Lua error of require
    $environment = new Environment(loader: new ArrayLoader(['main.lua' => 'return pcall(require, "broken")', 'broken.lua' => 'return +']));
    assertSame([false, "error loading module 'broken' from file 'broken.lua':\n\tbroken.lua:1: unexpected symbol near '+'"], $environment->run('main.lua')->values);
}

function test_modules_and_require(): void
{
    $environment = new Environment(loader: new ArrayLoader([
        'main.lua' => 'return 1',
        'a/b.lua' => 'local name, file = ... return {name = name, file = file}',
        'c/init.lua' => 'return "init of " .. ...',
        'shadowed.lua' => 'return "the file"',
        'bad/value.lua' => 'return nil',
        'raises.lua' => 'error("while loading")',
    ]));
    $environment->addModule('shadowed', ['from' => 'host']);
    $environment->addModule('config', ['nested' => ['list' => [1, 2]], 'double' => static fn (int $x): int => 2 * $x]);
    $sandbox = $environment->newSandbox();
    $run = static fn (string $code): array => $sandbox->load($code, '=test')->call();
    assertSame([['name' => 'a.b', 'file' => 'a/b.lua'], 'init of c', 'host', 2, 42], $run(
        'return require("a.b"), require("c"), require("shadowed").from, #require("config").nested.list, require("config").double(21)'
    ));
    $run = static fn (string $code): array => $environment->newSandbox()->load($code, '=test')->call();
    assertSame([true, true, true, ':host:', 'a/b.lua'], $run(
        'local _, data = require("config") local _, file = require("a.b")'
        . ' return rawequal(require("config"), require("config")), rawequal(require("a.b"), package.loaded["a.b"]), config == nil, data, file'
    ));
    $run = static fn (string $code): array => $sandbox->load($code, '=test')->call();
    assertSame([true, true], $run('return require("bad.value"), package.loaded["bad.value"]'));
    assertSame([false, "test:1: module 'no.such' not found:\n\tno host module 'no.such'\n\tno file 'no/such.lua'\n\tno file 'no/such/init.lua'"],
        $run('return pcall(function () return require("no.such") end)'));
    assertSame([false, 'raises.lua:1: while loading'], $run('return pcall(require, "raises")'));
    // each sandbox has its own module tables
    $run('require("config").nested.changed = true');
    assertSame([null], $environment->newSandbox()->load('return require("config").nested.changed')->call());
    // an Environment's globals: in every new sandbox, each its own copy
    $environment->addGlobal('settings', ['mode' => 'test']);
    $first = $environment->newSandbox();
    $first->load('settings.mode = "changed"')->call();
    assertSame(['test'], $environment->newSandbox()->load('return settings.mode')->call());
    assertSame(['changed'], $first->load('return settings.mode')->call());
}

function test_sandboxes_are_isolated(): void
{
    $environment = new Environment();
    $a = $environment->newSandbox();
    $b = $environment->newSandbox();
    $a->load('string.upper = nil; x = 1; getmetatable("").__index = {}; package.loaded.string = nil')->call();
    assertSame(['function', true, 'A', true], $b->load('return type(string.upper), x == nil, ("a"):upper(), package.loaded.string ~= nil')->call());
    assertSame([true], $a->load('return ("a").upper == nil')->call());
    // alive at once, calls interleaved
    $counter = 'n = (n or 0) + 1 return n';
    assertSame([1], $a->load($counter)->call());
    assertSame([1], $b->load($counter)->call());
    assertSame([2], $a->load($counter)->call());
    // re-entry: a PHP function called from A runs B and returns its result to A
    $a->setGlobal('runInB', static fn (string $code): array => $b->load($code)->call());
    assertSame([2, 2, 'B'], $a->load('local results = runInB("n = n + 1 return n, \'B\'") return n, results[1], results[2]')->call());
    // and a fresh sandbox inside a run
    $a->setGlobal('fresh', static fn (): array => $environment->newSandbox()->load('return x')->call());
    assertSame([0], $a->load('return #fresh()')->call());
}

function test_handles_keep_their_sandbox_alive(): void
{
    $environment = new Environment(loader: new ArrayLoader(['counter.lua' => 'local n = 0 return function () n = n + 1 return n end']));
    $counter = (static fn (): LuaFunction => $environment->newSandbox()->load('local n = 0 return function () n = n + 1 return n end')->call()[0])();
    gc_collect_cycles();
    assertSame([1], $counter());
    assertSame([2], $counter());
    // also what Environment::run returns
    [$fromRun] = $environment->run('counter.lua')->values;
    gc_collect_cycles();
    assertSame([1], $fromRun());
}

function test_values_php_holds_stay_alive_for_lua(): void
{
    $finalized = [];
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('finalized', static function (string $name) use (&$finalized): void {
        $finalized[] = $name;
    });
    $held = $sandbox->load(<<<'LUA'
        cache = setmetatable({}, {__mode = "v"})
        local t = setmetatable({}, {__gc = function () finalized("held") end})
        cache[1] = t
        cache[2] = setmetatable({}, {__gc = function () finalized("dropped") end})
        return t
        LUA)->call();  // (a copy: [], but the handle below holds the table itself)
    assertSame([[]], $sandbox->load('return cache[1]')->call(), 'a copy');
    $table = $sandbox->getGlobalHandle('cache')->get(1);
    assertTrue($table instanceof LuaTable);
    $sandbox->load('collectgarbage() collectgarbage()')->call();
    assertSame(['dropped'], $finalized);
    assertSame([true, false], $sandbox->load('return cache[1] ~= nil, cache[2] ~= nil')->call());
    unset($table);
    $sandbox->load('collectgarbage() collectgarbage()')->call();
    assertSame(['dropped', 'held'], $finalized);
    assertSame([false], $sandbox->load('return cache[1] ~= nil')->call());
}

function test_closing_a_sandbox(): void
{
    $finalized = [];
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('finalized', static function () use (&$finalized): void {
        $finalized[] = true;
    });
    [$function] = $sandbox->load('pending = setmetatable({}, {__gc = function () finalized() end}) return print')->call();
    $table = $sandbox->getGlobalHandle('pending');
    $sandbox->close();
    assertTrue($sandbox->isClosed());
    foreach ([
        static fn () => $sandbox->load('return 1'),
        static fn () => $sandbox->run('x.lua'),
        static fn () => $sandbox->getGlobal('pending'),
        static fn () => $sandbox->getGlobalHandle('pending'),
        static fn () => $sandbox->setGlobal('x', 1),
        static fn () => $function(),
        static fn () => $table->get('x'),
        static fn () => $table->set('x', 1),
        static fn () => $table->length(),
        static fn () => $table->toArray(),
        static fn () => iterator_to_array($table->pairs()),
    ] as $index => $use) {
        assertSame('the sandbox is closed', assertThrows(SandboxClosed::class, $use), "use $index");
    }
    // pending finalizers are skipped
    unset($sandbox, $function, $table);
    gc_collect_cycles();
    assertSame([], $finalized);
}

/**
 * Creating an Environment or a Sandbox, loading and running code
 * (successes and failures) leave the host's output buffers, error and
 * exception handlers, settings and working directory as they were, and
 * echo nothing. (Printing and coroutines join once output sinks and
 * fiber settings are per sandbox.)
 */
function test_runs_do_not_change_the_host_process(): void
{
    $settings = ['memory_limit', 'serialize_precision', 'zend.exception_ignore_args', 'error_reporting', 'display_errors', 'fiber.stack_size', 'precision'];
    $before = array_map('ini_get', array_combine($settings, $settings));
    $hostErrorHandler = static fn (): bool => false;
    $hostExceptionHandler = static function (\Throwable $thrown): void {
    };
    set_error_handler($hostErrorHandler);
    set_exception_handler($hostExceptionHandler);
    $levels = ob_get_level();
    $workingDirectory = getcwd();
    ob_start();
    ob_start();
    try {
        $environment = shop();
        $orders = new OrderRepository();
        runLoyalty($environment, $orders);
        foreach ([
            static fn () => runLoyalty(shop([4 => 'local recent = orders.recent(500)']), $orders),
            static fn () => $environment->newSandbox()->load('x = = 1'),
            static fn () => $environment->newSandbox()->load('local t = setmetatable({}, {__index = function (t, k) return k .. 1 end}) return t.x, #t, t[{}]')->call(),
            static fn () => Lua::run('return string.format("%5.2f", 1/3), os.time{year = 2020, month = 1, day = 1}'),
            static fn () => $environment->newSandbox()->load('return require("nope")')->call(),
        ] as $run) {
            try {
                $run();
            } catch (\Throwable) {
            }
        }
        $orders->failure = new \RuntimeException('host failure');
        try {
            runLoyalty($environment, $orders);
        } catch (\RuntimeException) {
        }
        $echoed = ob_get_contents();
        ob_end_clean();
        $echoedOuter = ob_get_contents();
        ob_end_clean();
    } finally {
        $errorHandler = set_error_handler(null);
        restore_error_handler();
        restore_error_handler();
        $exceptionHandler = set_exception_handler(null);
        restore_exception_handler();
        restore_exception_handler();
    }
    assertSame('', $echoed);
    assertSame('', $echoedOuter);
    assertSame($levels, ob_get_level());
    assertTrue($errorHandler === $hostErrorHandler, 'the host error handler is still installed');
    assertTrue($exceptionHandler === $hostExceptionHandler, 'the host exception handler is still installed');
    assertSame($before, array_map('ini_get', array_combine($settings, $settings)));
    assertSame($workingDirectory, getcwd());
}

function test_throwaway_sandboxes_do_not_accumulate(): void
{
    $environment = shop();
    $orders = new OrderRepository();
    runLoyalty($environment, $orders);
    gc_collect_cycles();
    $before = memory_get_usage();
    for ($i = 0; $i < 2000; $i++) {
        runLoyalty($environment, $orders);
    }
    gc_collect_cycles();
    $growth = memory_get_usage() - $before;
    assertTrue($growth < 2 * 1024 * 1024, "2000 runs kept $growth bytes");
}

function test_compiling_ahead_of_time_runs_nothing(): void
{
    $ran = [];
    $environment = new Environment(loader: new ArrayLoader([
        'good.lua' => 'record("good") return 1',
        'bad.lua' => "record('bad')\nreturn +",
        'also/bad.lua' => 'local x <const> = 1; x = 2',
    ]));
    $environment->addGlobal('record', static function (string $what) use (&$ran): void {
        $ran[] = $what;
    });
    $errors = $environment->compile(['good.lua', 'bad.lua', 'also/bad.lua']);
    assertSame(['bad.lua', 'also/bad.lua'], array_keys($errors));
    assertSame("bad.lua:2: unexpected symbol near '+'", $errors['bad.lua']->getMessage());
    assertSame('@bad.lua', $errors['bad.lua']->chunkName);
    assertSame(2, $errors['bad.lua']->luaLine);
    assertSame("also/bad.lua:1: attempt to assign to const variable 'x'", $errors['also/bad.lua']->getMessage());
    assertSame([], $ran);
    $cache = (new \ReflectionProperty(Environment::class, 'cache'))->getValue($environment);
    assertSame(1, $cache->memoryEntryCount(), 'the good script is cached');
    assertSame([1], $environment->run('good.lua')->values);
    assertSame(['good'], $ran);
    assertSame(1, $cache->memoryEntryCount(), 'and reused');
    // the key: the chunk name and the exact bytes
    $sandbox = $environment->newSandbox();
    $sandbox->load('return 1', '=a');
    $sandbox->load('return 1', '=a');
    assertSame(2, $cache->memoryEntryCount());
    $sandbox->load('return 1', '=b');
    $sandbox->load('return 1 ', '=a');
    assertSame(4, $cache->memoryEntryCount());
    assertThrows(\InvalidArgumentException::class, static fn () => $environment->compile(['missing.lua']));
}

function test_environment_options_are_checked(): void
{
    assertTrue(str_starts_with(assertThrows(\InvalidArgumentException::class, static fn () => new Environment(libraries: ['base', 'network'])), "unknown library 'network'"));
    assertThrows(\InvalidArgumentException::class, static fn () => new Environment(libraries: ['os.']));
    new Environment(libraries: ['base', 'string', 'table', 'math', 'os.time', 'os.date']);
    new Environment(libraries: Libraries::ALL);
    assertSame('Limits: steps must be null or at least 0', assertThrows(\InvalidArgumentException::class, static fn () => new Limits(steps: -1)));
    assertThrows(\InvalidArgumentException::class, static fn () => new Limits(seconds: NAN));
    assertThrows(\LogicException::class, static fn () => (new Environment())->run('x.lua'));
    assertThrows(\InvalidArgumentException::class, static fn () => (new Environment())->addModule('', []));
    assertThrows(\InvalidArgumentException::class, static fn () => (new Environment())->newSandbox()->load('return ...')->call(x: 1));
}
