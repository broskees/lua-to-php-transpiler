<?php

declare(strict_types=1);

namespace Tests\EmbedHostFunctionTest;

use LuaPhp\Embed\ConversionError;
use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Internal\HostFunction;
use LuaPhp\Embed\Lua;
use LuaPhp\Embed\LuaFunction;
use LuaPhp\Embed\LuaTable;
use LuaPhp\Embed\RunContext;
use LuaPhp\Embed\RuntimeError;
use LuaPhp\Embed\Sandbox;
use LuaPhp\Embed\SandboxClosed;
use LuaPhp\Embed\ScriptError;
use LuaPhp\Runtime\LuaObject;

/*
 * PHP Closures as Lua functions (LuaPhp\Embed): arguments follow the PHP
 * parameter types with luaL_check*'s rules and messages, results follow
 * the brief, ScriptError is a Lua error, and any other exception stops
 * the run and reaches the PHP caller unchanged: no pcall, xpcall handler,
 * coroutine or '__close' sees it.
 */

/**
 * Host modules standing in for lua5.4's C functions with the same checks:
 * my<library>.<name> is compared with <library>.<name>.
 */
function hostModules(Environment $environment): void
{
    $environment->addModule('mystring', [
        'rep' => static fn (string $s, int $n): string => str_repeat($s, max(0, $n)),
        'upper' => static fn (string $s): string => strtoupper($s),
        'char' => static fn (int ...$codes): string => implode('', array_map('chr', $codes)),
        'fail' => static function (): never {
            throw new ScriptError('resulting string too large');
        },
    ]);
    $environment->addModule('mymath', ['sqrt' => static fn (float $x): float => sqrt($x)]);
    $environment->addModule('my_G', ['next' => static fn (LuaTable $t): int => $t->length()]);
    $environment->addModule('mytable', ['concat' => static fn (array $list, string $separator = ''): string => implode($separator, $list)]);
    $environment->addModule('mycoroutine', ['create' => static fn (LuaFunction $f): string => 'thread']);
}

/** [code with T for the library table, library] */
const CASES = [
    ["return T.rep('x', 2.5)", 'string'],
    ["return T.rep('x', 'ten')", 'string'],
    ["return T.rep('x')", 'string'],
    ["return T.rep('x', nil)", 'string'],
    ["return T.rep('x', 2^63)", 'string'],
    ["return T.rep({}, 1)", 'string'],
    ["return T.rep(nil, 1)", 'string'],
    ["return T.rep(true, 1)", 'string'],
    ["return T.rep(setmetatable({}, {__name = 'MyType'}), 1)", 'string'],
    ["return T.rep('x', '3')", 'string'],
    ["return T.rep('x', 3.0)", 'string'],
    ["return T.rep('x', ' 0x10 ')", 'string'],
    ["return T.rep(12, 2)", 'string'],
    ["return T.rep(1.5, 2)", 'string'],
    ["local F = T.rep return F('x', 2.5)", 'string'],
    ["local t = {f = T.rep} return t.f('x', 2.5)", 'string'],
    ["return T:rep(2.5)", 'string'],
    ["return select(2, pcall(T.rep, 'x', 2.5))", 'string'],
    ["return T.upper(42)", 'string'],
    ["return T.upper()", 'string'],
    ["return T.char(65, 66.0, '67')", 'string'],
    ["return T.char(65, 'x')", 'string'],
    ["return T.char()", 'string'],
    ["return T.sqrt('x')", 'math'],
    ["return T.sqrt({})", 'math'],
    ["return T.sqrt('4')", 'math'],
    ["return T.sqrt(4)", 'math'],
    ["return T.sqrt()", 'math'],
    ["return T.next(1)", '_G'],
    ["return T.next()", '_G'],
    ["return T.concat(1)", 'table'],
    ["return T.concat({1, 2, 3}, ', ')", 'table'],
    ["return T.concat({1, 2}, nil)", 'table'],
    ["return T.concat({'a', 'b'}, 3)", 'table'],
    ["return T.create(1)", 'coroutine'],
    ["return T.create()", 'coroutine'],
];

function newEnvironment(): Environment
{
    $environment = new Environment();
    hostModules($environment);
    return $environment;
}

function test_arguments_are_checked_as_lua_checks_them(): void
{
    $script = '';
    foreach (CASES as [$code, $library]) {
        $script .= 'do local ok, a = pcall(load(' . var_export("local T = $library $code", true) . ', "=test")) '
            . "io.write(ok and 'ok:' or 'error:', tostring(a), '\\n') end\n";
    }
    [$status, $output, $errors] = runCommand(['lua5.4', '-'], $script);
    assertSame(0, $status, $errors);
    $expected = explode("\n", rtrim($output, "\n"));
    $sandbox = newEnvironment()->newSandbox();
    foreach (CASES as $index => [$code, $library]) {
        try {
            [$value] = $sandbox->load("local T = require('my$library') $code", '=test')->call();
            $actual = 'ok:' . (\is_float($value) ? LuaObject::numberToString($value) : $value);
        } catch (RuntimeError $error) {
            $actual = 'error:' . $error->getMessage();
        }
        // (a function found through package.loaded is named by its module)
        assertSame(str_replace("'$library.", "'my$library.", $expected[$index]), $actual, $code);
    }
}

function test_parameter_kinds(): void
{
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('h', [
        'defaults' => static fn (int $n = 5, ?string $s = null, ?array $a = null, bool $b = false, float $x = 1.5): string => json_encode([$n, $s, $a, $b, var_export($x, true)]),
        'nullable' => static fn (?int $n, ?bool $b): string => json_encode([$n, $b]),
        'bool' => static fn (bool $b): bool => $b,
        'float' => static fn (float $x): float => $x,
        'string' => static fn (string $s): string => $s,
        'mixed' => static fn (mixed $v): string => get_debug_type($v),
        'untyped' => static fn ($v) => \is_array($v) ? $v : get_debug_type($v),
        'rest' => static fn (string $separator, ...$rest): string => implode($separator, array_map('json_encode', $rest)),
        'ints' => static fn (int ...$numbers): int => array_sum($numbers),
        'mark' => static fn (LuaTable $t): null => $t->set('seen', true),
        'copy' => static function (array $t): int {
            $t['seen'] = true;
            return \count($t);
        },
        'callback' => static fn (LuaFunction $f, mixed $v): array => $f($v, 2),
    ]);
    $run = static fn (string $code): array => $sandbox->load($code, '=test')->call();
    assertSame(['[5,null,null,false,"1.5"]'], $run('return h.defaults()'));
    assertSame(['[5,null,null,false,"1.5"]'], $run('return h.defaults(nil, nil, nil, nil, nil)'));
    assertSame(['[7,"8",["x"],true,"2.0"]'], $run('return h.defaults(7.0, 8, {"x"}, 0, 2)'));
    assertSame(['[null,null]', '[3,true]'], $run('return h.nullable(), h.nullable("3", 0)'));
    assertSame([true, true, false, false, true], $run('return h.bool(0), h.bool(""), h.bool(nil), h.bool(), h.bool({})'));
    assertSame([3.0, 'float'], $run('local x = h.float(3) return x, math.type(x)'));
    assertSame(['1.5', '10', '1e+100', '-0.0'], $run('return h.string(1.5), h.string(10), h.string(1e100), h.string(-0.0)'));
    assertSame(['null', 'int', 'float', 'string', 'bool', 'array', LuaFunction::class, 'LuaPhp\Embed\LuaValue'],
        $run('return h.mixed(), h.mixed(1), h.mixed(1.0), h.mixed(""), h.mixed(false), h.mixed({}), h.mixed(print), h.mixed(coroutine.create(print))'));
    assertSame([['a' => 1], 'null'], $run('return h.untyped({a = 1}), h.untyped()'));
    assertSame(['1-"a"-[true]', ''], $run('return h.rest("-", 1, "a", {true}), h.rest("")'));
    assertSame([6, 'test:1: bad argument #3 to \'ints\' (number expected, got table)'],
        $run('return h.ints(1, 2.0, "3"), select(2, pcall(function () return h.ints(1, 2, {}) end))'));
    // a table handle is the table itself; an array is a copy
    assertSame([true, 2, true], $run('local t = {} h.mark(t) local u = {1} return t.seen, h.copy(u), u.seen == nil'));
    // a Lua function called back from PHP
    assertSame([[42, 'x']], $run('return h.callback(function (a, b) return a * 21, "x" end, 2)'));
    // a table that cannot be converted is a bad argument
    assertSame([false, "test:1: bad argument #1 to 'mixed' (table contains a cycle at self)"],
        $run('local t = {} t.self = t return pcall(function () return h.mixed(t) end)'));
    assertSame([false, "test:1: bad argument #1 to 'copy' (table has both 1 and \"1\" as keys)"],
        $run('return pcall(function () return h.copy({"a", ["1"] = "b"}) end)'));
    assertSame([false, "test:1: bad argument #2 to 'callback' (table contains a cycle at [1][1])"],
        $run('local t = {} t[1] = {t} return pcall(function () return h.callback(print, t) end)'));
}

function test_results(): void
{
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('h', [
        'void' => static function (): void {
        },
        'null' => static fn (): mixed => null,
        'scalar' => static fn (): int => 7,
        'list' => static fn (): array => [1, 2],
        'multiple' => static fn (): \LuaPhp\Embed\Multiple => Lua::multiple(1, 'two', null),
        'functions' => static fn (): array => ['double' => static fn (int $x): int => $x * 2],
        'object' => static fn (): array => ['when' => new \DateTime()],
    ]);
    $run = static fn (string $code): array => $sandbox->load($code, '=test')->call();
    assertSame([0, 1, 7, 2, 3, 'two', 42], $run(
        'return select("#", h.void()), select("#", h.null()), h.scalar(), #h.list(), select("#", h.multiple()), select(2, h.multiple()), h.functions().double(21)'
    ));
    // one Closure is one Lua function in a sandbox
    $callback = static fn (): int => 1;
    $sandbox->setGlobal('first', $callback);
    $sandbox->setGlobal('again', ['nested' => $callback, 'get' => static fn (): \Closure => $callback]);
    assertSame([true, true, false], $run('return rawequal(first, again.nested), rawequal(again.get(), again.get()), rawequal(first, again.get)'));
    // a result that cannot go to Lua is a PHP bug: it stops the run
    $error = assertThrows(ConversionError::class, static fn () => $run('return pcall(h.object)'));
    assertSame("cannot pass object of class DateTime to Lua at h['object']()['when']", $error);
    assertThrows(SandboxClosed::class, static fn () => $run('return 1'));
}

function test_script_errors_are_lua_errors(): void
{
    $sandbox = newEnvironment()->newSandbox();
    $sandbox->setGlobal('fail', static function (): never {
        throw ScriptError::value(['code' => 'OUT_OF_STOCK', 'items' => [3, 4]]);
    });
    // the message gets luaL_error's position (compared with lua5.4's string.rep)
    $reference = runCommand(['lua5.4', '-'], <<<'LUA'
        print(select(2, pcall(load("local x = 1\nreturn string.rep('xx', math.maxinteger)", "=test"))))
        print(select(2, pcall(string.rep, 'xx', math.maxinteger)))
        LUA);
    [$direct, $protected] = explode("\n", rtrim($reference[1]));
    assertSame($direct, assertThrows(RuntimeError::class, static fn () => $sandbox->load("local x = 1\nreturn require('mystring').fail()", '=test')->call()));
    assertSame([$protected], $sandbox->load("return select(2, pcall(require('mystring').fail))", '=test')->call());
    // a value: the script gets it (a table); uncaught, the RuntimeError carries it
    assertSame([false, 'OUT_OF_STOCK', 4], $sandbox->load('local ok, e = pcall(fail) return ok, e.code, e.items[2]')->call());
    $error = null;
    try {
        $sandbox->load('fail()')->call();
    } catch (RuntimeError $error) {
    }
    assertSame(['code' => 'OUT_OF_STOCK', 'items' => [3, 4]], $error?->value);
    assertTrue(str_starts_with($error->luaMessage, 'table: 0x'), $error->luaMessage);
    // the sandbox goes on
    assertSame([1], $sandbox->load('return 1')->call());
}

/** a sandbox whose 'h' functions throw, record what ran in $marks, and call back */
function throwingSandbox(\Throwable $exception, array &$marks): Sandbox
{
    $marks = [];
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('h', [
        'throw' => static function () use ($exception): never {
            throw $exception;
        },
        'mark' => static function (string $what) use (&$marks): void {
            $marks[] = $what;
        },
        'swallow' => static function (LuaFunction $f) use (&$marks): string {
            try {
                $f();
            } catch (\Throwable $caught) {
                $marks[] = 'caught ' . $caught::class;
            }
            return 'went on';
        },
    ]);
    return $sandbox;
}

function test_php_exceptions_stop_the_run(): void
{
    $scripts = [
        'direct' => 'h.throw()',
        'pcall' => 'h.mark(tostring(pcall(h.throw))) h.mark("after pcall")',
        'pcall of a Lua function' => 'pcall(function () local x = h.throw() end) h.mark("after pcall")',
        'xpcall' => 'xpcall(h.throw, function (m) h.mark("handler") return m end) h.mark("after xpcall")',
        'error in error handling' => 'xpcall(error, h.throw)',
        'coroutine.resume' => 'coroutine.resume(coroutine.create(function () pcall(h.throw) end)) h.mark("after resume")',
        'coroutine.wrap' => 'pcall(coroutine.wrap(function () h.throw() end)) h.mark("after wrap")',
        'yielded coroutine' => 'local co = coroutine.wrap(function () coroutine.yield(1) h.throw() end) co() pcall(co) h.mark("after")',
        '__close' => 'do local x <close> = setmetatable({}, {__close = function () h.mark("__close") end}) h.throw() end',
        '__close in pcall' => 'pcall(function () local x <close> = setmetatable({}, {__close = function () h.mark("__close") end}) h.throw() end) h.mark("after")',
        'metamethod' => 'local t = setmetatable({}, {__index = function () return h.throw() end}) pcall(function () return t.x end) h.mark("after")',
        'sort comparator' => 'pcall(table.sort, {3, 2, 1}, function (a, b) h.throw() end) h.mark("after")',
        'caught by PHP' => 'h.swallow(function () h.throw() end) h.mark("after swallow")',
    ];
    $exceptions = [
        'PDOException' => static fn (): \Throwable => new \PDOException('database is down'),
        'RuntimeException' => static fn (): \Throwable => new \RuntimeException('boom'),
        'TypeError' => static fn (): \Throwable => new \TypeError('wrong type'),
        'DivisionByZeroError' => static fn (): \Throwable => new \DivisionByZeroError('Division by zero'),
    ];
    foreach ($exceptions as $class => $newException) {
        foreach ($scripts as $label => $script) {
            $exception = $newException();
            $marks = [];
            $sandbox = throwingSandbox($exception, $marks);
            $handle = $sandbox->getGlobalHandle('h');
            $caught = null;
            try {
                $sandbox->load($script)->call();
            } catch (\Throwable $caught) {
            }
            assertTrue($caught === $exception, "$class, $label: the same exception reaches PHP, got " . ($caught === null ? 'nothing' : $caught::class . ': ' . $caught->getMessage()));
            $expectedMarks = $label === 'caught by PHP' ? ['caught ' . $class] : [];
            assertSame($expectedMarks, $marks, "$class, $label: no Lua code ran after it");
            // the sandbox and its handles are closed
            $closed = assertThrows(SandboxClosed::class, static fn () => $sandbox->load('return 1'));
            assertSame("the sandbox is closed: a $class stopped a run in it", $closed);
            assertThrows(SandboxClosed::class, static fn () => $handle->get('mark'));
            assertTrue($sandbox->isClosed());
        }
    }
}

function test_real_php_errors_in_php_functions_stop_the_run(): void
{
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('h', [
        'length' => static fn (int $n): int => strlen($n),  // strict_types: a TypeError from PHP itself
        'divide' => static fn (int $n): int => intdiv(1, $n),
    ]);
    assertThrows(\TypeError::class, static fn () => $sandbox->load('return pcall(h.length, 1)')->call());
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('divide', static fn (int $n): int => intdiv(1, $n));
    assertSame('Division by zero', assertThrows(\DivisionByZeroError::class, static fn () => $sandbox->load('return pcall(divide, 0)')->call()));
}

function test_lua_errors_pass_through_php_functions(): void
{
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('each', static function (LuaFunction $f): int {
        $f();  // a RuntimeError this does not catch goes on as the Lua error it was
        return 1;
    });
    $sandbox->setGlobal('guard', static function (LuaFunction $f): string {
        try {
            $f();
            return 'no error';
        } catch (RuntimeError $error) {
            return 'caught: ' . $error->luaMessage;
        }
    });
    assertSame([false, true], $sandbox->load('local e = {} local ok, got = pcall(each, function () error(e) end) return ok, rawequal(got, e)')->call());
    assertSame(['caught: test:1: boom', 'still running'], $sandbox->load('return guard(function () error("boom") end), "still running"', '=test')->call());
    // and uncaught, to PHP
    assertSame('test:1: deep', assertThrows(RuntimeError::class, static fn () => $sandbox->load('each(function () error("deep") end)', '=test')->call()));
}

function test_signatures_are_read_when_registered(): void
{
    $closure = static fn (int $n): int => $n;
    assertTrue(HostFunction::of($closure, 'a') === HostFunction::of($closure, 'b'), 'read once');
    $environment = new Environment();
    assertSame('cannot pass a Closure with a parameter of type DateTime ($when) to Lua at h[\'f\']',
        assertThrows(ConversionError::class, static fn () => $environment->addModule('h', ['f' => static fn (\DateTime $when) => 1])));
    assertSame('cannot pass a Closure with a by-reference parameter ($n) to Lua at f',
        assertThrows(ConversionError::class, static fn () => $environment->addGlobal('f', static function (int &$n): void {
        })));
    assertSame('cannot pass a Closure with a parameter of type int|float ($x) to Lua at f',
        assertThrows(ConversionError::class, static fn () => $environment->addGlobal('f', static fn (int|float $x) => $x)));
    assertSame('cannot pass a Closure with a parameter of type RunContext ($context) to Lua at f',
        str_replace('LuaPhp\Embed\\', '', assertThrows(ConversionError::class, static fn () => $environment->addGlobal('f', static fn (RunContext $context) => 1))));
    // first-class callables of PHP functions are Closures too
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('upper', strtoupper(...));
    assertSame(['ABC'], $sandbox->load('return upper("abc")')->call());
}

function test_run_context(): void
{
    $environment = new Environment();
    $calls = 0;
    $environment->addModule('account', static function (RunContext $context) use (&$calls): array {
        $calls++;
        return [
            'owner' => $context->get('user'),
            'has' => static fn (string $key): bool => $context->has($key),
            'expensive' => static function () use ($context): int {
                $context->chargeSteps(1000);
                return 1;
            },
        ];
    });
    $sandbox = $environment->newSandbox(['user' => 'ana', 'tenant' => null]);
    assertSame(['ana', true, false, 1, true], $sandbox->load(
        'local a = require("account") return a.owner, a.has("tenant"), a.has("other"), a.expensive(), rawequal(a, require("account"))'
    )->call());
    assertSame(1, $calls, 'a factory runs once per sandbox');
    $environment->newSandbox(['user' => 'bo'])->load('require("account") require("account")')->call();
    assertSame(2, $calls);
    assertSame('the run context has no value \'user\'', assertThrows(\OutOfBoundsException::class,
        static fn () => $environment->newSandbox()->load('require("account")')->call()));
    $environment->addModule('negative', static fn (RunContext $context): array => ['charge' => static fn () => $context->chargeSteps(-1)]);
    assertSame('steps to charge must be at least 0', assertThrows(\InvalidArgumentException::class,
        static fn () => $environment->newSandbox()->load('require("negative").charge()')->call()));
}

function test_php_warnings_while_lua_runs_are_exceptions(): void
{
    $sandbox = (new Environment())->newSandbox();
    $sandbox->setGlobal('warns', static fn (): int => \intdiv(1, 1) + (int) file_get_contents('/no/such/file'));
    $hostHandlerCalls = 0;
    set_error_handler(static function () use (&$hostHandlerCalls): bool {
        $hostHandlerCalls++;
        return true;
    });
    ob_start();
    try {
        $message = assertThrows(\ErrorException::class, static fn () => $sandbox->load('return pcall(warns)')->call());
        $echoed = ob_get_contents();
        // (outside a run the host's handler is back)
        @trigger_error('host warning', E_USER_WARNING);
        trigger_error('host warning', E_USER_WARNING);
    } finally {
        ob_end_clean();
        restore_error_handler();
    }
    assertTrue(str_contains($message, 'Failed to open stream'), $message);
    assertSame('', $echoed);
    assertSame(2, $hostHandlerCalls, 'the host handler saw only its own warnings');
    assertTrue($sandbox->isClosed(), 'it stopped the run');
}
