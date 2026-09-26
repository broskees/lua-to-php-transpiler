<?php

declare(strict_types=1);

namespace Tests\EmbedRuntimeTest;

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Lib\PackageLib;
use LuaPhp\Lib\StandardLibraries;
use LuaPhp\Runtime\Budget;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\LimitReached;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\OutputSink;
use LuaPhp\Runtime\PhpErrors;

/*
 * The runtime half of the embedding API (AGENTS.md "Embedding
 * conventions"): output sinks, the PHP error scope, library profiles,
 * a sandbox's require, budgets (steps, memory, time, output, coroutines,
 * call depth) that no Lua code can catch, and isolation between states.
 * Where lua5.4 can show the same behavior (messages, library results), it
 * is the reference.
 */

/** the libraries of the brief's SAFE profile */
const SAFE = ['base', 'package', 'string', 'table', 'math', 'utf8', 'coroutine', 'os.time', 'os.date', 'os.clock', 'os.difftime'];

final class CollectingSink implements OutputSink
{
    public string $bytes = '';

    public function write(string $bytes): void
    {
        $this->bytes .= $bytes;
    }
}

/**
 * A state for embedded code: $libraries opened (sandboxed unless said
 * otherwise), code compiled with step counting, output collected.
 *
 * @param list<string> $libraries
 */
function embeddedState(array $libraries = SAFE, ?Budget $budget = null, bool $allowLoad = false, bool $sandboxed = true): Coroutine
{
    $L = Coroutine::newState();
    $G = $L->globalState;
    $G->countSteps = true;
    $G->output = new CollectingSink();
    $G->errorOutput = new CollectingSink();
    StandardLibraries::openSelected($L, $libraries, $sandboxed, $allowLoad);
    $G->setBudget($budget);
    $budget?->start();
    return $L;
}

/**
 * Runs $code in $L as embedding code would (protected, PHP warnings as
 * exceptions): [status, results or error value].
 *
 * @return array{int, mixed}
 */
function runIn(Coroutine $L, string $code, string $chunkname = '=stdin'): array
{
    return PhpErrors::call(static function () use ($L, $code, $chunkname): array {
        $main = ChunkLoader::load($L, $code, $chunkname, 't');
        return Calls::protectedCall($L, $main, []);
    });
}

/** what $code printed in $L, which must have run it without error */
function printedBy(Coroutine $L, string $code): string
{
    $before = \strlen($L->globalState->output->bytes);
    [$status, $result] = runIn($L, $code);
    assertSame(Lua::LUA_OK, $status, 'Lua error: ' . (\is_string($result) ? $result : get_debug_type($result)));
    return substr($L->globalState->output->bytes, $before);
}

/** the limit that running $code in $L reaches */
function limitReachedBy(Coroutine $L, string $code, string $chunkname = '=stdin'): string
{
    try {
        [$status, $result] = runIn($L, $code, $chunkname);
    } catch (LimitReached $reached) {
        return $reached->limit;
    }
    throw new \AssertionFailed("no limit reached by $code; status $status: " . (\is_string($result) ? $result : get_debug_type($result)));
}

/**
 * Natives that build big values in PHP, charging nothing: bigString(n, s)
 * is s repeated n times, list(n, v) the list of n values v (without v:
 * n, n - 1, ..., 1).
 */
function addBuilders(Coroutine $L): void
{
    $globals = $L->globalState->globals;
    $globals->hash['bigString'] = new NativeFunction('bigString', static fn (Coroutine $L, array $args): array => [str_repeat($args[1], (int) $args[0])]);
    $globals->hash['list'] = new NativeFunction('list', static function (Coroutine $L, array $args): array {
        $list = new LuaTable();
        for ($i = 1; $i <= $args[0]; $i++) {
            $list->arr[$i] = $args[1] ?? $args[0] - $i + 1;
        }
        return [$list];
    });
}

/** lua5.4's standard output for $code read from stdin (chunk name "=stdin"), after running $prelude */
function referenceOutput(string $code, string $prelude = ''): string
{
    $command = $prelude === '' ? ['lua5.4', '-'] : ['lua5.4', '-e', $prelude, '-'];
    [$status, $stdout, $stderr] = runCommand($command, $code);
    assertSame(0, $status, "lua5.4 failed: $stderr");
    return $stdout;
}

/** the error handler PHP would call now */
function currentErrorHandler(): mixed
{
    $handler = set_error_handler(null);
    restore_error_handler();
    return $handler;
}

// ---------------------------------------------------------------- output

function test_output_goes_to_the_sinks_and_host_output_buffers_stay_as_they_were(): void
{
    ob_start();
    ob_start();
    $level = ob_get_level();
    try {
        $L = embeddedState([...SAFE, 'io']);
        $printed = printedBy($L, <<<'LUA'
            print("a", 1, 2.5, nil, true)
            io.write("b", 3, 4.5, "\n")
            io.stdout:write("c\n")
            io.stdout:setvbuf("line")
            io.write("d\n")
            io.stderr:write("e\n")
            warn("not shown")
            warn("@on")
            warn("w", "x")
            print(io.stdout:seek())
            print(io.stderr:seek())
            io.flush()
            io.stdout:flush()
            LUA);
        assertSame($level, ob_get_level(), 'output buffers');
        assertSame('', ob_get_contents(), 'echoed');
    } finally {
        ob_end_clean();
        ob_end_clean();
    }
    assertSame("a\t1\t2.5\tnil\ttrue\nb34.5\nc\nd\nnil\tIllegal seek\t29\nnil\tIllegal seek\t29\n", $printed);
    assertSame("e\nLua warning: wx\n", $L->globalState->errorOutput->bytes);
}

function test_the_command_line_writes_standard_output_and_error_as_before(): void
{
    $script = <<<'LUA'
        io.write("buffered ")
        io.stderr:write("to stderr\n")
        print("printed", 1, 2.5)
        io.stdout:setvbuf("line")
        io.write("line one\nline")
        io.stderr:write("again\n")
        io.write(" two\n")
        io.stdout:setvbuf("no")
        io.write("unbuffered")
        warn("@on")
        warn("a ", "warning")
        io.write(" end\n")
        print(io.stderr:write("x\n") == io.stderr, io.stdout:seek() ~= nil)
        LUA;
    $file = scratchDirectory() . '/cli_output.lua';
    file_put_contents($file, $script);
    $reference = runCommand(['lua5.4', $file]);
    $ours = runCommand(['php', REPO_ROOT . '/bin/lua', $file]);
    assertSame($reference, $ours);
}

function test_output_and_warnings_are_charged_to_the_budget_before_they_are_written(): void
{
    $L = embeddedState(SAFE, new Budget(outputBytes: 1024 * 1024));
    assertSame('output', limitReachedBy($L, 'print("small") print(("x"):rep(2e6))'));
    assertSame("small\n", $L->globalState->output->bytes);  // nothing of the big line
    assertSame(6, $L->globalState->budget->usage()['outputBytes']);

    $L = embeddedState(SAFE, new Budget(outputBytes: 100));
    assertSame('output', limitReachedBy($L, 'warn("@on") for i = 1, 100 do warn("warning ", i) end'));
    assertTrue(\strlen($L->globalState->errorOutput->bytes) <= 100, 'warnings over the limit were written');
}

// ---------------------------------------------------------------- PHP error scope

function test_the_php_error_scope_turns_warnings_into_exceptions_and_restores_the_host_handler(): void
{
    $hostHandler = static fn (): bool => true;
    set_error_handler($hostHandler);
    try {
        $message = assertThrows(\ErrorException::class, static fn () => PhpErrors::call(static fn () => trigger_error('inside', E_USER_WARNING)));
        assertSame('inside', $message);
        assertSame($hostHandler, currentErrorHandler(), 'after an exception');
        assertSame(true, PhpErrors::call(static fn (): bool => @trigger_error('silenced', E_USER_WARNING)));
        assertSame($hostHandler, currentErrorHandler(), 'after a return');
        $result = PhpErrors::call(static function () use ($hostHandler): string {
            $scopeHandler = currentErrorHandler();
            assertTrue($scopeHandler !== $hostHandler, 'the scope installs its handler');
            assertThrows(\RuntimeException::class, static fn () => PhpErrors::call(static fn () => throw new \RuntimeException('nested')));
            assertSame($scopeHandler, currentErrorHandler(), 'a nested scope restores the outer one');
            return PhpErrors::call(static fn (): string => 'nested result');
        });
        assertSame('nested result', $result);
        assertSame($hostHandler, currentErrorHandler(), 'after nested scopes');
    } finally {
        restore_error_handler();
    }
}

function test_php_exceptions_from_a_native_function_escape_pcall_coroutines_and_close(): void
{
    foreach ([
        'pcall(boom)',
        'xpcall(boom, function() handled = true end)',
        'coroutine.resume(coroutine.create(boom))',
        'coroutine.wrap(function() pcall(boom) end)()',
        'do local x <close> = setmetatable({}, {__close = function() closed = true end}) boom() end',
        'local t = setmetatable({}, {__index = function() return boom() end}) print(pcall(function() return t.x end))',
    ] as $code) {
        $L = embeddedState();
        $G = $L->globalState;
        $G->globals->hash['boom'] = new NativeFunction('boom', static fn (): array => throw new \DomainException('from PHP'));
        $message = assertThrows(\DomainException::class, static fn () => runIn($L, $code));
        assertSame('from PHP', $message, $code);
        assertSame(null, $G->globals->hash['handled'] ?? null, "$code: message handler ran");
        assertSame(null, $G->globals->hash['closed'] ?? null, "$code: __close ran");
        assertSame('', $G->output->bytes, $code);
    }
}

// ---------------------------------------------------------------- library profiles

function test_safe_leaves_out_what_the_brief_lists_with_lua_messages(): void
{
    $code = <<<'LUA'
        print(pcall(function() return io.open("/etc/passwd") end))
        print(pcall(function() return os.execute("id") end))
        print(pcall(function() return debug.getinfo(1) end))
        print(pcall(function() return load("return 1") end))
        print(pcall(function() return dofile("x") end))
        print(pcall(function() return string.dump(print) end))
        print(pcall(function() return ("x"):dump() end))
        print(pcall(collectgarbage, "bogus"))
        print(type(collectgarbage("count")), collectgarbage(), type(collectgarbage("step")), collectgarbage("collect"))
        print(os.time{year = 2020, month = 1, day = 1, hour = 12} - os.time{year = 2020, month = 1, day = 1, hour = 0}, os.date("!%Y", 0), type(os.clock()), os.difftime(10, 4))
        print(math.max(3, 7), string.format("%5.2f", 3.14159), table.concat({1, 2}, ","), utf8.char(72, 228), coroutine.isyieldable())
        LUA;
    $reference = referenceOutput($code, 'io = nil debug = nil load = nil dofile = nil string.dump = nil for k in pairs(os) do if k ~= "time" and k ~= "date" and k ~= "clock" and k ~= "difftime" then os[k] = nil end end');
    assertSame($reference, printedBy(embeddedState(), $code));
    foreach ([
        "stdin:1: attempt to index a nil value (global 'io')",
        "stdin:2: attempt to call a nil value (field 'execute')",
        "stdin:3: attempt to index a nil value (global 'debug')",
        "stdin:4: attempt to call a nil value (global 'load')",
    ] as $message) {
        assertTrue(str_contains($reference, "false\t$message\n"), $message);
    }
    $L = embeddedState();
    assertSame("false\tbad argument #1 to 'collectgarbage' (invalid option 'stop')\n", printedBy($L, 'print(pcall(collectgarbage, "stop"))'));
}

function test_safe_is_lua_with_exactly_the_brief_s_removals(): void
{
    $code = <<<'LUA'
        local names = {}
        for name, value in pairs(_G) do
          if type(value) == "table" and name ~= "_G" and name ~= "arg" then
            for field in pairs(value) do names[#names + 1] = name .. "." .. field end
          elseif name ~= "arg" then
            names[#names + 1] = name
          end
        end
        table.sort(names)
        print(table.concat(names, " "))
        LUA;
    $ours = printedBy(embeddedState(), $code);
    $reference = referenceOutput($code, 'io = nil debug = nil load = nil dofile = nil loadfile = nil require = nil string.dump = nil '
        . 'for k in pairs(os) do if k ~= "time" and k ~= "date" and k ~= "clock" and k ~= "difftime" then os[k] = nil end end '
        . 'for k in pairs(package) do if k ~= "loaded" then package[k] = nil end end');
    assertSame($reference, $ours);
    foreach (['assert', 'collectgarbage', 'coroutine.wrap', 'math.random', 'os.date', 'package.loaded', 'string.rep', 'table.sort', 'utf8.char', 'warn'] as $name) {
        assertTrue(str_contains($ours, " $name "), $name);
    }
}

function test_load_in_a_sandbox_takes_text_chunks_only_and_counts_steps(): void
{
    $code = <<<'LUA'
        print(load("return 1 + 1")())
        print(load(string.dump(function() end)))
        print(load("return 2", "=chunk", "b"))
        print(type(load(function() return nil end)))
        LUA;
    $L = embeddedState(SAFE, null, true);
    $L->globalState->globals->hash['string']->hash['dump'] = new NativeFunction('dump', static fn (): array => ["\x1bLua fake binary chunk"]);
    $ours = printedBy($L, $code);
    // lua5.4 with mode "t" (what the sandbox leaves of any mode)
    $reference = referenceOutput(str_replace(['load(string.dump(function() end))', '"=chunk", "b"'], ['load(string.dump(function() end), nil, "t")', '"=chunk", ""'], $code));
    assertSame($reference, $ours);

    $L = embeddedState(SAFE, new Budget(steps: 10000), true);
    assertSame('steps', limitReachedBy($L, 'load("while true do end")()'));
}

function test_single_functions_and_the_string_metatable_follow_the_selection(): void
{
    $L = embeddedState(['base.print', 'base.pcall', 'string.upper', 'os.time'], null, false, false);
    $printed = printedBy($L, <<<'LUA'
        print(("a"):upper(), string.rep, os.date, os.time() > 0, tostring, _VERSION)
        print(pcall(function() return ("a"):rep(2) end))
        LUA);
    assertSame("A\tnil\tnil\ttrue\tnil\tLua 5.4\nfalse\tstdin:2: attempt to call a nil value (method 'rep')\n", $printed);

    assertThrows(\InvalidArgumentException::class, static fn () => embeddedState(['base', 'sockets']));
    assertThrows(\InvalidArgumentException::class, static fn () => embeddedState(['string.nosuch']));
    embeddedState(['base.dofile', 'string.dump', 'package.path']);  // what a sandbox takes out may be named
}

function test_all_libraries_unsandboxed_are_the_command_line_s(): void
{
    $L = embeddedState(['base', 'package', 'coroutine', 'table', 'io', 'os', 'string', 'math', 'utf8', 'debug'], null, false, false);
    $code = 'local n = 0 for name, lib in pairs(package.loaded) do if name ~= "_G" then n = n + 1 for _ in pairs(lib) do n = n + 1 end end end print(n, type(load), type(dofile), type(string.dump), type(require), #package.searchers)';
    assertSame(referenceOutput($code), printedBy($L, $code));
}

// ---------------------------------------------------------------- sandbox require

function test_sandbox_require_uses_php_searchers_like_ll_require(): void
{
    $L = embeddedState();
    $calls = [];
    $moduleLoader = new NativeFunction('loader', static function (Coroutine $L, array $args) use (&$calls): array {
        $calls[] = $args;
        $module = new LuaTable();
        $module->hash['answer'] = 42;
        return [$module];
    });
    $fileLoader = ChunkLoader::load($L, 'local name, extra = ... return {name = name, extra = extra}', '@lib/mod.lua', 't');
    PackageLib::openSandboxRequire($L, [
        static fn (Coroutine $L, string $name): array => $name === 'host' ? [$moduleLoader, ':host:'] : [null, "no host module '$name'"],
        static fn (Coroutine $L, string $name): array => $name === 'nothing' ? [null, null] : [null, null],
        static fn (Coroutine $L, string $name): array => $name === 'lib.mod' ? [$fileLoader, 'lib/mod.lua'] : [null, "no file '" . str_replace('.', '/', $name) . ".lua'"],
    ]);
    $printed = printedBy($L, <<<'LUA'
        local host, data = require("host")
        print(host.answer, data, require("host") == host, package.loaded.host == host)
        local mod, where = require("lib.mod")
        print(mod.name, mod.extra, where)
        package.loaded.host = nil
        print(require("host") ~= host)
        LUA);
    assertSame("42\t:host:\ttrue\ttrue\nlib.mod\tlib/mod.lua\tlib/mod.lua\ntrue\n", $printed);
    assertSame(2, \count($calls), 'the loader runs once per load');
    assertSame(['host', ':host:'], $calls[0]);
    $notFound = printedBy($L, 'print(pcall(require, "a.b"))');

    // lua5.4's message for the same searchers
    $reference = referenceOutput(<<<'LUA'
        package.searchers = {
          function(name) return "no host module '" .. name .. "'" end,
          function(name) return nil end,
          function(name) return "no file '" .. name:gsub("%.", "/") .. ".lua'" end,
        }
        print(pcall(require, "a.b"))
        LUA);
    assertSame($reference, $notFound);
}

function test_sandbox_require_stores_true_for_a_loader_that_returns_nothing(): void
{
    $L = embeddedState();
    $loader = ChunkLoader::load($L, 'loaded_with = ...', '=mod', 't');
    PackageLib::openSandboxRequire($L, [static fn (Coroutine $L, string $name): array => [$loader, 'data']]);
    $ours = printedBy($L, 'print(require("mod")) print(loaded_with, package.loaded.mod)');
    $reference = referenceOutput('package.preload.mod = function(...) loaded_with = ... end print(require("mod")) print(loaded_with, package.loaded.mod)');
    assertSame(str_replace(':preload:', 'data', $reference), $ours);
}

// ---------------------------------------------------------------- limits

function test_steps_stop_endless_loops_and_nothing_in_lua_catches_it(): void
{
    $cases = [
        'while true do end',
        'repeat until false',
        '::again:: goto again',
        'for i = 1, math.maxinteger do end',
        'for _ in function() return 1 end do end',
        'pcall(function() while true do end end)',
        'xpcall(function() while true do end end, function() handled = true end)',
        'coroutine.resume(coroutine.create(function() while true do end end))',
        'coroutine.wrap(function() pcall(function() while true do end end) end)()',
        'do local x <close> = setmetatable({}, {__close = function() closed = true end}) while true do end end',
        'local t = setmetatable({}, {__index = function() while true do end end}) print(pcall(function() return t.x end))',
        'local t = setmetatable({}, {__add = function() while true do end end}) print(pcall(function() return t + 1 end))',
        'local function f() return f() end f()',
        'string.gsub(("a"):rep(1000), ".", function() while true do end end)',
        'table.sort({3, 2, 1}, function() while true do end end)',
    ];
    foreach ($cases as $code) {
        $L = embeddedState(SAFE, new Budget(steps: 100000));
        assertSame('steps', limitReachedBy($L, $code), $code);
        $G = $L->globalState;
        assertSame(null, $G->globals->hash['handled'] ?? null, "$code: message handler ran");
        assertSame(null, $G->globals->hash['closed'] ?? null, "$code: __close ran");
        $used = $G->budget->usage()['steps'];
        assertTrue($used > 100000 && $used < 100000 + 100, "$code: $used steps");
    }
}

function test_a_budget_replaced_between_runs_is_charged_by_suspended_code_too(): void
{
    $L = embeddedState(SAFE, new Budget(steps: 1000000));
    printedBy($L, 'co = coroutine.wrap(function() local n = 0 while true do n = n + 1 coroutine.yield(n) end end) co()');
    $budget = new Budget(steps: 50000);
    $L->globalState->setBudget($budget);
    assertSame("101\n", printedBy($L, 'local n for i = 1, 100 do n = co() end print(n)'));
    $used = $budget->usage()['steps'];
    assertTrue($used > 1000 && $used < 5000, "$used steps");
    $budget->start();
    assertSame('steps', limitReachedBy($L, 'while true do co() end'));
}

function test_steps_are_at_least_the_instructions_run(): void
{
    $L = embeddedState(SAFE, new Budget());
    runIn($L, 'local s = 0 for i = 1, 1000 do s = s + i end return s');
    $steps = $L->globalState->budget->usage()['steps'];
    // the main function's size when it starts, the loop's 3 instructions (ADD, MMBIN, FORLOOP) at each of its 999 jumps back
    assertTrue($steps >= 3000 && $steps < 3100, "$steps steps");
}

function test_library_functions_charge_in_proportion_to_their_work(): void
{
    // each takes far fewer than 15000 steps of Lua instructions (big values come from PHP)
    foreach ([
        'string.rep' => 'local s = ("x"):rep(1e6)',
        'table.concat' => 'local s = table.concat(list(20000, "x"))',
        'table.concat with i, j' => 'local s = table.concat(list(20000, "x"), "", 1, 20000)',
        'string.gsub' => 'local r = bigString(50000, "ab"):gsub("a", "c")',
        'string.find' => 'local s = bigString(2^20, "a") .. "b" local i = s:find("b", 1, true)',
        'string.match backtracking' => 'local i = bigString(40, "a"):match(("a*"):rep(12) .. "b")',
        'string.gmatch' => 'local w = bigString(1e6, "a"):gmatch("%a+")()',
        'table.sort' => 'table.sort(list(20000))',
        'table.move' => 'local t = table.move({}, 1, 1e6, 1)',
        'table.insert' => 'local t = setmetatable({}, {__len = function() return 1e6 end}) table.insert(t, 1, "x")',
        'table.remove' => 'local t = setmetatable({}, {__len = function() return 1e6 end}) table.remove(t, 1)',
        'table.unpack' => 'local n = select("#", table.unpack(list(20000, 1)))',
        'utf8.len' => 'local n = utf8.len(bigString(50000, "é"))',
        'utf8.codepoint' => 'local n = select("#", utf8.codepoint(bigString(20000, "a"), 1, -1))',
        'utf8.char' => 'local s = utf8.char(table.unpack(list(10000, 233)))',
        'string.char' => 'local s = string.char(table.unpack(list(10000, 65)))',
        'string.byte' => 'local n = select("#", string.byte(bigString(20000, "x"), 1, -1))',
        'string.format' => 'local s = string.format("%s", bigString(1e6, "x"))',
        'string.upper' => 'local s = bigString(1e6, "x"):upper()',
        'string.pack' => 'local s = string.pack("z", bigString(1e6, "x"))',
        'load' => 'load(bigString(20000, " "))',
    ] as $name => $code) {
        $L = embeddedState(SAFE, new Budget(steps: 15000), true);
        addBuilders($L);
        assertSame('steps', limitReachedBy($L, $code), $name);
    }
}

function test_memory_stops_big_allocations_and_slow_growth(): void
{
    // (room enough below memory_limit: the budget, not the host's limit, is what these reach)
    $memoryLimit = ini_set('memory_limit', '1G');
    try {
        assertMemoryLimitsReached();
    } finally {
        ini_set('memory_limit', $memoryLimit);
    }
}

function assertMemoryLimitsReached(): void
{
    foreach ([
        'return ("x"):rep(1e9)',
        'local s = ("x"):rep(2^20) for i = 1, 20 do s = s .. s end',
        'local t = {} for i = 1, 1e9 do t[i] = ("x"):rep(100) end',
        'local t = {} for i = 1, 100 do t[i] = ("x"):rep(2^20) end local s = table.concat(t)',
        'return string.pack("s4", ("x"):rep(2^20)):rep(40)',
        'local s = ("ab"):rep(2^20) return (s:gsub("a", ("a"):rep(32)))',
        'return pcall(string.rep, "x", 1e9)',
        'local t = {} for i = 1, 1e9 do t[i] = {} end',
    ] as $code) {
        $L = embeddedState(SAFE, new Budget(memoryBytes: 32 * 1024 * 1024));
        assertSame('memory', limitReachedBy($L, $code), $code);
        assertTrue($L->globalState->budget->usage()['peakMemoryBytes'] > 0, $code);
    }
}

function test_the_host_memory_limit_stays_a_lua_memory_error(): void
{
    $script = scratchDirectory() . '/host_memory.php';
    file_put_contents($script, '<?php
        require ' . var_export(REPO_ROOT . '/src/autoload.php', true) . ';
        use LuaPhp\Runtime\{Budget, Calls, ChunkLoader, Coroutine};
        use LuaPhp\Lib\StandardLibraries;
        $L = Coroutine::newState();
        StandardLibraries::openSelected($L, ["base", "string"], true, false);
        $L->globalState->countSteps = true;
        $budget = new Budget(memoryBytes: 1 << 30);
        $L->globalState->setBudget($budget);
        $budget->start();
        [$status, $result] = Calls::protectedCall($L, ChunkLoader::load($L, "return pcall(string.rep, \"x\", 2^28)", "=host", "t"), []);
        echo json_encode([$status, $result]), "\n";
        ');
    [$status, $stdout, $stderr] = runCommand(['php', '-d', 'memory_limit=128M', $script]);
    assertSame([0, "[0,[false,\"not enough memory\"]]\n", ''], [$status, $stdout, $stderr]);
}

function test_time_counts_php_functions_and_is_checked_as_steps_go(): void
{
    $L = embeddedState(SAFE, new Budget(seconds: 0.2));
    $L->globalState->globals->hash['sleep'] = new NativeFunction('sleep', static function (): array {
        usleep(300000);
        return [];
    });
    $started = hrtime(true);
    assertSame('seconds', limitReachedBy($L, 'sleep() while true do end'));
    assertTrue((hrtime(true) - $started) / 1e9 < 1.0, 'stopped late');

    $L = embeddedState(SAFE, new Budget(seconds: 0.05));
    assertSame('seconds', limitReachedBy($L, 'while true do end'));

    $budget = new Budget(seconds: 0.01);
    usleep(20000);
    assertThrows(LimitReached::class, static fn () => $budget->checkLimits());
}

function test_coroutines_alive_at_once_are_limited_and_finished_ones_do_not_count(): void
{
    $L = embeddedState(SAFE, new Budget(coroutines: 1000));
    assertSame('coroutines', limitReachedBy($L, 'local t = {} for i = 1, 100000 do t[i] = coroutine.create(function() coroutine.yield() end) coroutine.resume(t[i]) end'));

    $L = embeddedState(SAFE, new Budget(coroutines: 100));
    $printed = printedBy($L, <<<'LUA'
        for i = 1, 5000 do  -- finished, dead with an error, closed, dropped while suspended
          local co = coroutine.create(function() coroutine.yield() end)
          coroutine.resume(co)
          if i % 4 == 0 then coroutine.resume(co) end
          if i % 4 == 1 then coroutine.close(co) end
          if i % 4 == 2 then pcall(coroutine.wrap(function() error("x") end)) end
        end
        LUA . "\n");
    assertSame('', $printed);
}

function test_call_depth_is_lua_s_catchable_stack_overflow(): void
{
    $code = <<<'LUA'
        local function f() return f() + 1 end
        print(pcall(f))
        print(xpcall(f, function(m) return "handled: " .. m end))
        print(xpcall(f, function(m) local function g() return g() + 1 end return g() end))
        print(pcall(f))
        local depth = 0
        local function g() depth = depth + 1 return g() + 1 end
        pcall(g)
        print(depth)
        LUA;
    $L = embeddedState(SAFE, new Budget(callDepth: 1000));
    $printed = printedBy($L, $code);
    $reference = referenceOutput($code);
    $expectedLines = explode("\n", $reference);
    $expectedLines[4] = '999';  // levels: the main chunk and g (lua5.4: until its stack of 1000000 slots is full)
    assertSame(implode("\n", $expectedLines), $printed);
}

function test_call_depth_counts_the_lua_levels_below_native_calls_too(): void
{
    $L = embeddedState(SAFE, new Budget(callDepth: 100));
    $printed = printedBy($L, <<<'LUA'
        local function deep(n) if n == 0 then return 0 end return 1 + deep(n - 1) end
        print(pcall(deep, 98))
        print(pcall(deep, 99))
        print(select(3, pcall(pcall, deep, 99)))
        print(coroutine.wrap(function() return deep(98) end)())
        LUA);
    // the main chunk is a level; each coroutine has its levels, as each C thread has its stack
    assertSame("true\t98\nfalse\tstdin:1: stack overflow\nstdin:1: stack overflow\n98\n", $printed);
}

function test_limits_of_a_budget_without_limits_and_a_state_without_budget_cost_nothing(): void
{
    $code = 'local t = {} for i = 1, 1000 do t[i] = ("x"):rep(i % 7) .. i end table.sort(t) return #table.concat(t), select("#", string.find(t[1], "x"))';
    // plain code without a budget: nothing is charged
    $L = Coroutine::newState();
    StandardLibraries::openAll($L);
    [$status] = Calls::protectedCall($L, ChunkLoader::load($L, $code, '=plain', 't'), []);
    assertSame(Lua::LUA_OK, $status);
    assertSame(PHP_INT_MAX, $L->globalState->stepsLeft);
    // code counting steps without a budget: counts down from a number no run reaches, library functions charge nothing
    $L = embeddedState();
    assertSame(Lua::LUA_OK, runIn($L, $code)[0]);
    $counted = PHP_INT_MAX - $L->globalState->stepsLeft;
    assertTrue($counted > 3000 && $counted < 20000, "$counted steps");
}

function test_code_without_step_counting_is_what_it_always_was(): void
{
    foreach (officialTestFiles() as $file) {
        $source = preg_replace('/^#[^\n]*/', '', file_get_contents($file));  // (a first line starting with '#', as loadfile skips it)
        $proto = Compiler::compile($source, '@' . basename($file));
        $plain = Emitter::emitChunk($proto);
        assertSame($plain, Emitter::emitChunk($proto, false), basename($file));
        foreach (['stepsLeft', 'Budget', '$steps', 'depth'] as $marker) {
            assertTrue(!str_contains($plain, $marker), basename($file) . ": $marker");
        }
        $counted = Emitter::emitChunk($proto, true);
        assertTrue(str_contains($counted, 'Budget::stepsUsedUp'), basename($file));
    }
}

function test_loaded_code_is_cached_apart_with_and_without_step_counting(): void
{
    $code = 'local n = ... or 0 while n < 100000 do n = n + 1 end';
    $plain = Coroutine::newState();
    StandardLibraries::openAll($plain);
    $runPlain = static function () use ($plain, $code): void {
        [$status] = Calls::protectedCall($plain, ChunkLoader::load($plain, $code, '=same', 't'), [0]);
        assertSame(Lua::LUA_OK, $status);
        assertSame(PHP_INT_MAX, $plain->globalState->stepsLeft, 'plain code counted steps');
    };
    $runPlain();
    assertSame('steps', limitReachedBy(embeddedState(SAFE, new Budget(steps: 1000)), $code, '=same'));
    $runPlain();
}

// ---------------------------------------------------------------- isolation

function test_states_do_not_see_each_other(): void
{
    $a = embeddedState();
    $b = embeddedState();
    printedBy($a, 'string.upper = nil x = 1 getmetatable("").__index = {} math.randomseed(42)');
    assertSame("A\tnil\tA\n", printedBy($b, 'print(string.upper("a"), x, ("a"):upper())'));

    // interleaved: A, B, A, with coroutines suspended in both
    printedBy($a, 'co = coroutine.wrap(function() for i = 1, 3 do coroutine.yield("a" .. i) end end)');
    printedBy($b, 'co = coroutine.wrap(function() for i = 1, 3 do coroutine.yield("b" .. i) end end)');
    assertSame("a1\n", printedBy($a, 'print(co())'));
    assertSame("b1\n", printedBy($b, 'print(co())'));
    assertSame("a2\n", printedBy($a, 'print(co())'));
    assertSame("b2\n", printedBy($b, 'print(co())'));

    // random seeds are per state
    $fresh = embeddedState();
    printedBy($b, 'math.randomseed(7)');
    printedBy($fresh, 'math.randomseed(7)');
    printedBy($a, 'math.random() math.randomseed(1)');
    assertSame(printedBy($fresh, 'print(math.random(1000), math.random(1000))'), printedBy($b, 'print(math.random(1000), math.random(1000))'));

    // '%p' of long strings: each state numbers its own
    $long = 'string.format("%p", string.rep("x", 50))';
    $first = printedBy($a, "keep = {} for i = 1, 5 do keep[i] = string.rep('y', 60) .. i end for i = 1, 5 do string.format('%p', keep[i]) end print($long)");
    assertSame($first, printedBy(embeddedState(), "keep = {} for i = 1, 5 do keep[i] = string.rep('y', 60) .. i end for i = 1, 5 do string.format('%p', keep[i]) end print($long)"));
    assertSame(printedBy(embeddedState(), "print($long)"), printedBy(embeddedState(), "print($long)"));
}

function test_a_state_run_from_inside_another_keeps_its_own_limits(): void
{
    $outer = embeddedState(SAFE, new Budget(steps: 1000000, outputBytes: 1000));
    $outer->globalState->globals->hash['inner'] = new NativeFunction('inner', static function (Coroutine $L, array $args): array {
        $inner = embeddedState(SAFE, new Budget(steps: 10000));
        [$status, $results] = runIn($inner, 'print("inner output") return ' . $args[0]);
        try {
            runIn($inner, 'while true do end');
        } catch (LimitReached $reached) {
            $results[] = $reached->limit;
        }
        $results[] = $inner->globalState->output->bytes;
        return $results;
    });
    $printed = printedBy($outer, <<<'LUA'
        print(inner("1 + 1"))
        print(coroutine.wrap(function() return inner("'from a coroutine'") end)())
        local n = 0 for i = 1, 50000 do n = n + 1 end
        print(n)
        LUA);
    assertSame("2\tsteps\tinner output\n\nfrom a coroutine\tsteps\tinner output\n\n50000\n", $printed);
    $usage = $outer->globalState->budget->usage();
    assertTrue($usage['steps'] > 150000 && $usage['steps'] < 160000, "the inner states' steps were charged to the outer: " . $usage['steps']);
}

// ---------------------------------------------------------------- closing

function test_an_abandoned_state_runs_no_finalizer(): void
{
    $L = embeddedState(SAFE, new Budget(steps: 100000));
    $finalized = false;
    $L->globalState->globals->hash['mark'] = new NativeFunction('mark', static function () use (&$finalized): array {
        $finalized = true;
        return [];
    });
    assertSame('steps', limitReachedBy($L, 'keep = setmetatable({}, {__gc = function() mark() end}) setmetatable({}, {__gc = function() mark() end}) while true do end'));
    Collector::abandonState($L->globalState);
    unset($L);
    gc_collect_cycles();
    assertSame(false, $finalized);

    // a finalizer that reaches a limit stops the collection that runs it
    $L = embeddedState(SAFE, new Budget(steps: 100000));
    assertSame('steps', limitReachedBy($L, 'warn("@on") setmetatable({}, {__gc = function() while true do end end}) collectgarbage() print("after")'));
    assertSame('', $L->globalState->output->bytes);
    assertSame('', $L->globalState->errorOutput->bytes);
}
