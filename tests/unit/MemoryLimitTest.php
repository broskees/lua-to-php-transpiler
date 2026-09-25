<?php

declare(strict_types=1);

namespace Tests\MemoryLimitTest;

/*
 * C Lua fails an allocation it cannot make with a catchable "not enough
 * memory" (status LUA_ERRMEM, no position, no message handler); PHP ends
 * the process with a fatal error when memory_limit is reached. Library
 * functions that can build a large result in one call check its size
 * first (LuaPhp\Runtime\MemoryLimit). Each case below asks for far too
 * much inside pcall (and for a result that fits), then prints "after".
 * It runs under lua5.4 with its address space limited, as bin/lua2php
 * output under memory_limit=128M, and under bin/lua started with
 * memory_limit=64M and max_memory_limit=128M (bin/lua raises the limit to
 * 4G itself, capped by max_memory_limit): all three print the expected
 * output. 160 MB of address space for lua5.4 is about PHP's 128M: in both,
 * a 100 MB string fits and a copy of it does not.
 */

const PRELUDE = <<<'LUA'
local function show(...)  -- long strings as their length
  local values = table.pack(...)
  for i = 1, values.n do
    if type(values[i]) == "string" and #values[i] > 40 then values[i] = "#" .. #values[i] end
  end
  print(table.unpack(values, 1, values.n))
end
local function try(f, ...) show(pcall(f, ...)) end
local mb = ("x"):rep(1e6)
local refs = {}  -- 1000 references to one 1 MB string
for i = 1, 1000 do refs[i] = mb end

LUA;

const NO_MEMORY = "false\tnot enough memory\n";

/** name => [Lua code (after PRELUDE, followed by print("after")), expected stdout] */
function cases(): array
{
    return [
        'rep' => [
            <<<'LUA'
            try(string.rep, "x", 1e9)
            try(string.rep, "x", 5e8, "y")
            try(string.rep, "ab", 1 << 30)  -- Lua's own limit comes first
            try(string.rep, "x", 1e7)
            LUA,
            NO_MEMORY . NO_MEMORY . "false\tresulting string too large\ntrue\t#10000000\n",
        ],
        'table_concat' => [
            <<<'LUA'
            try(table.concat, refs)
            try(table.concat, refs, ",")
            try(table.concat, setmetatable({}, {__index = function() return mb end, __len = function() return 1000 end}))
            try(table.concat, refs, "", 1, 10)
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#10000000\n",
        ],
        'format' => [
            <<<'LUA'
            try(string.format, ("%s"):rep(1000), table.unpack(refs))
            local controls = ("\127"):rep(1e6)  -- each one is quoted as "\127"
            local quoted = {}
            for i = 1, 300 do quoted[i] = controls end
            try(string.format, ("%q"):rep(300), table.unpack(quoted))
            try(string.format, ("%s"):rep(10), table.unpack(refs, 1, 10))
            LUA,
            NO_MEMORY . NO_MEMORY . "true\t#10000000\n",
        ],
        'gsub' => [
            <<<'LUA'
            local k = ("y"):rep(1000)
            try(string.gsub, mb, "x", k)
            try(string.gsub, mb, ".+", ("%0"):rep(1000))
            try(string.gsub, mb, "x", function() return k end)
            try(string.gsub, mb, "x", {x = k})
            try(string.gsub, mb, "x", "yy")
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#2000000\t1000000\n",
        ],
        'concat' => [
            <<<'LUA'
            local ten = ("x"):rep(1e7)
            try(load("local a = ...; return " .. ("a"):rep(150, "..")), ten)
            try(function() return ten .. ten end)
            local hundred = load("local a = ...; return " .. ("a"):rep(100, ".."))(mb)
            show(hundred)
            try(function() return hundred .. hundred end)
            try(function() return hundred .. 1 end)
            LUA,
            NO_MEMORY . "true\t#20000000\n#100000000\n" . NO_MEMORY . NO_MEMORY,
        ],
        'copies' => [
            <<<'LUA'
            local hundred = load("local a = ...; return " .. ("a"):rep(100, ".."))(mb)
            try(string.upper, hundred)
            try(string.lower, hundred)
            try(string.reverse, hundred)
            try(string.sub, hundred, 2)
            try(string.sub, hundred, 1, -2)
            try(string.sub, hundred, 1, 5e6)
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#5000000\n",
        ],
        'captures' => [
            <<<'LUA'
            local ten = ("x"):rep(1e7)
            local nested = ("("):rep(32) .. ".*" .. (")"):rep(32)  -- 32 captures of the whole subject
            try(string.match, ten, nested)
            try(string.find, ten, nested)
            try(string.gmatch(ten, nested))
            try(string.gsub, ten, nested, function() return "" end)
            try(string.match, ten, "(.*)")
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#10000000\n",
        ],
        'pack' => [
            <<<'LUA'
            try(string.pack, "c1000000000", "")
            local empties = {}
            for i = 1, 100 do empties[i] = "" end
            try(string.pack, ("c10000000"):rep(100), table.unpack(empties))
            try(string.pack, ("s"):rep(1000), table.unpack(refs))
            try(string.pack, "c10000000", "")
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#10000000\n",
        ],
        'io_read' => [
            <<<'LUA'
            local zero = assert(io.open("/dev/zero", "rb"))
            try(zero.read, zero, 1e9)
            try(zero.read, zero, "a")
            try(zero.read, zero, "l")
            try(zero.read, zero, "L")
            try(zero:lines(1e9))
            try(zero.read, zero, 1e7)
            zero:close()
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#10000000\n",
        ],
        'os_date' => [
            <<<'LUA'
            try(os.date, ("%c"):rep(2e7))
            try(os.date, ("%c"):rep(1e5))
            LUA,
            NO_MEMORY . "true\t#2400000\n",
        ],
        'searchpath' => [
            <<<'LUA'
            try(package.searchpath, mb, ("?"):rep(1000))
            LUA,
            NO_MEMORY,
        ],
        'load_reader' => [
            <<<'LUA'
            local n = 0
            try(load, function() n = n + 1; if n <= 1000 then return mb end end)
            LUA,
            "true\tnil\tnot enough memory\n",
        ],
        'errors' => [
            <<<'LUA'
            show(xpcall(string.rep, function(m) return "handled: " .. m end, "x", 1e9))
            show(coroutine.resume(coroutine.create(string.rep), "x", 1e9))
            try(coroutine.wrap(string.rep), "x", 1e9)
            show(xpcall(coroutine.wrap(string.rep), function(m) return "handled: " .. m end, "x", 1e9))
            local codes = {}  -- utf8.char is bounded by the number of arguments
            for i = 1, 200000 do codes[i] = 0x7FFFFFFF end
            try(utf8.char, table.unpack(codes))
            LUA,
            NO_MEMORY . NO_MEMORY . NO_MEMORY . NO_MEMORY . "true\t#1200000\n",
        ],
    ];
}

/** [lua5.4, bin/lua2php output, bin/lua] commands for $name.lua, run from its directory */
function commands(string $name): array
{
    return [
        'lua5.4' => ['sh', '-c', 'ulimit -v 163840 && exec lua5.4 "$1"', 'sh', "$name.lua"],
        'lua2php' => ['php', ...OPCACHE_ON_NEW_FILES, '-d', 'memory_limit=128M', "php/$name.php"],
        'bin/lua' => ['php', '-d', 'memory_limit=64M', '-d', 'max_memory_limit=128M', REPO_ROOT . '/bin/lua', "$name.lua"],
    ];
}

function test_huge_results_are_a_catchable_not_enough_memory_error(): void
{
    $directory = scratchDirectory() . '/memory-limit';
    mkdir($directory);
    foreach (cases() as $name => [$code]) {
        file_put_contents("$directory/$name.lua", PRELUDE . $code . "\nprint(\"after\")\n");
    }
    file_put_contents("$directory/uncaught.lua", "print(\"before\")\nstring.rep(\"x\", 1e9)\nprint(\"not reached\")\n");
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $directory, '-o', "$directory/php"]);
    assertSame(0, $status, $errorOutput);
    $expected = array_map(static fn (array $case): array => [0, $case[1] . "after\n", ''], cases());
    $expected['uncaught'] = [1, "before\n", "lua: not enough memory\n"];
    $failures = [];
    foreach (array_keys($expected) as $name) {
        // one case at a time: each process may use its whole limit
        $running = array_map(static fn (array $command): array => startProcess($command, $directory), commands($name));
        foreach ($running as $label => $process) {
            [$status, $stdout, $stderr] = finishProcess($process);
            $programName = ['lua5.4' => 'lua5.4', 'lua2php' => "php/$name.php", 'bin/lua' => REPO_ROOT . '/bin/lua'][$label];
            $actual = [$status, $stdout, normalizeDifferentialOutput(substr($stderr, 0, 2000), $programName)];
            if ($actual !== $expected[$name]) {
                $failures[] = "$name under $label: expected " . var_export($expected[$name], true) . ', got ' . var_export($actual, true);
            }
        }
    }
    assertSame([], $failures);
}

/**
 * Scripts bin/lua2php generates keep the memory_limit PHP was started
 * with; bin/lua raises it to 4G, or to max_memory_limit (PHP 8.5) when
 * that is lower, without the warning ini_set gives above the cap.
 */
function test_memory_limit_policy(): void
{
    $directory = scratchDirectory() . '/memory-limit-policy';
    mkdir($directory);
    file_put_contents("$directory/big.lua", "print(pcall(function () return #string.rep('x', 1.5e8) end))\n");
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', 'big.lua'], '', $directory);
    assertSame(0, $status, $errorOutput);
    $cases = [
        'lua2php, memory_limit=128M, capped at 256M' => [['-d', 'memory_limit=128M', '-d', 'max_memory_limit=256M', 'big.php'], NO_MEMORY],
        'lua2php, memory_limit=512M' => [['-d', 'memory_limit=512M', 'big.php'], "true\t150000000\n"],
        'bin/lua, memory_limit=128M, capped at 256M' => [['-d', 'memory_limit=128M', '-d', 'max_memory_limit=256M', REPO_ROOT . '/bin/lua', 'big.lua'], "true\t150000000\n"],
        'bin/lua, memory_limit=128M, capped at 128M' => [['-d', 'memory_limit=128M', '-d', 'max_memory_limit=128M', REPO_ROOT . '/bin/lua', 'big.lua'], NO_MEMORY],
        'bin/lua, memory_limit=128M' => [['-d', 'memory_limit=128M', REPO_ROOT . '/bin/lua', 'big.lua'], "true\t150000000\n"],
    ];
    foreach ($cases as $label => [$phpArguments, $expectedOutput]) {
        assertSame([0, $expectedOutput, ''], runCommand(['php', ...OPCACHE_ON_NEW_FILES, ...$phpArguments], '', $directory), $label);
    }
}

/**
 * The frame budget of a thread (Calls::MAX_FRAME_BYTES) shrinks with
 * memory_limit, so deep recursion is still Lua's "stack overflow" (and a
 * second one in the handler "error in error handling") before PHP runs out
 * of memory, also inside a coroutine; a deep recursion that ends still
 * fits. Runs without opcache: uncached code has the largest frames.
 */
function test_deep_recursion_is_a_stack_overflow_under_a_low_memory_limit(): void
{
    $directory = scratchDirectory() . '/memory-limit-recursion';
    mkdir($directory);
    file_put_contents("$directory/recursion.lua", <<<'LUA'
        local function r() return 1 + r() end
        print(pcall(r))
        print(xpcall(r, r))
        print(coroutine.wrap(function() return pcall(r) end)())
        local function sum(n) if n == 0 then return 0 end return n + sum(n - 1) end
        print(pcall(sum, tonumber(arg[1])))
        print("after")

        LUA);
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', 'recursion.lua'], '', $directory);
    assertSame(0, $status, $errorOutput);
    $overflow = "false\trecursion.lua:1: stack overflow\n";
    foreach (['64M' => 5000, '128M' => 10000] as $limit => $depth) {
        $expected = [0, $overflow . "false\terror in error handling\n" . $overflow . "true\t" . intdiv($depth * ($depth + 1), 2) . "\nafter\n", ''];
        $commands = [
            'lua5.4' => ['lua5.4', 'recursion.lua', (string) $depth],
            'lua2php' => ['php', '-d', 'opcache.enable_cli=0', '-d', "memory_limit=$limit", 'recursion.php', (string) $depth],
            'bin/lua' => ['php', '-d', "memory_limit=$limit", '-d', "max_memory_limit=$limit", REPO_ROOT . '/bin/lua', 'recursion.lua', (string) $depth],
        ];
        foreach ($commands as $label => $command) {
            // (through files: PHP's fatal error would print a trace too long for a pipe)
            $result = finishProcess(startProcess($command, $directory));
            $result[2] = substr($result[2], 0, 2000);
            assertSame($expected, $result, "$label, memory_limit=$limit");
        }
    }
}
