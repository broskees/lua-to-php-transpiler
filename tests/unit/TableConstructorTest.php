<?php

declare(strict_types=1);

namespace Tests\TableConstructorTest;

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;

/*
 * Constant table constructors are emitted as runs (FunctionEmitter's
 * constructor runs: a fast path of literals and TableConstructor::run)
 * instead of inline code for each instruction. They must behave exactly
 * like that inline code, which the prepend file inline_constructors.php
 * brings back (FunctionEmitter::$inlineConstructors): same output with and
 * without it, also where collections, finalizers and hooks run in the
 * middle of a constructor and look at it or change it.
 */

const INLINE = ['-d', 'auto_prepend_file=' . REPO_ROOT . '/tests/unit/inline_constructors.php'];

/** [status, stdout, stderr] of bin/lua running $source, with $phpOptions */
function runLua(string $name, string $source, array $phpOptions): array
{
    $directory = scratchDirectory() . '/constructors';
    @mkdir($directory);
    file_put_contents("$directory/$name.lua", $source);
    return runCommand(['env', '-u', 'LUAPHP_CACHE_DIR', 'php', ...$phpOptions, REPO_ROOT . '/bin/lua', "$name.lua"], '', $directory);
}

function assertSameAsInline(string $name, string $source): string
{
    $inline = runLua($name, $source, INLINE);
    $runs = runLua($name, $source, []);
    assertSame($inline, $runs, "$name: runs behave like inline code");
    return $runs[1];
}

function test_collections_in_the_middle_of_constructors_see_the_same_state(): void
{
    // a chain of finalizers: each collection calls one, which records the
    // line and registers of the function whose allocation started it
    $source = <<<'LUA'
        local log = {}
        local probe
        local function newProbe() setmetatable({}, {__gc = probe}) end
        local function describe(v)
          if type(v) == "table" then
            local n = 0
            for _ in pairs(v) do n = n + 1 end
            return "table#" .. #v .. "/" .. n
          end
          return tostring(v)
        end
        probe = function()
          local info = debug.getinfo(2, "Slf")
          local registers = {}
          for i = 1, 250 do
            local name, value = debug.getlocal(2, i)
            if name == nil then break end
            registers[#registers + 1] = describe(value)
          end
          log[#log + 1] = {info.short_src .. ":" .. info.currentline .. " " .. table.concat(registers, " "), info.func, info.currentline}
          newProbe()
        end
        newProbe()
        -- small constructors (with literals) in a loop cross the debt limit
        local function medium()
          local t = {
            {1, 2}, {3, 4}, {5, 6}, {7, 8}, {9, 10},
            {a = 1}, {b = 2}, {c = 3}, {d = 4}, {e = 5},
            x = {1, 2, 3, 4, 5, 6, 7, 8}, y = {z = {w = {}}},
          }
          return t
        end
        for i = 1, 20000 do medium() end
        -- a constructor too big for literals, charging a lot at each call
        local rows = {}
        for i = 1, 1500 do rows[#rows + 1] = string.format("{%d, 'v%d', x = %d}", i, i, i) end
        local big = load("return function() local t = {" .. table.concat(rows, ",\n") .. "} return t end", "=big")()
        for i = 1, 40 do big() end
        local inside = 0  -- collections that started inside a constructor
        for _, entry in ipairs(log) do
          local f, line = entry[2], entry[3]
          if f == big or (f == medium and line > debug.getinfo(medium, "S").linedefined and line < debug.getinfo(medium, "S").lastlinedefined - 1) then
            inside = inside + 1
          end
        end
        print(#log, inside)
        for _, entry in ipairs(log) do print(entry[1]) end
        LUA;
    $stdout = assertSameAsInline('collections', $source);
    [$probes, $inside] = array_map('intval', explode("\t", strtok($stdout, "\n")));
    assertTrue($probes >= 10 && $inside >= 5, "collections ran inside constructors: $probes probes, $inside inside");
}

function test_hooks_in_the_middle_of_constructors_see_and_change_the_same_state(): void
{
    $source = <<<'LUA'
        local function build()
          local t = {
            1, 2,
            x = "a",
            {3, 4},
            y = "b",
            [true] = 1, [1.5] = 2,
            z = {w = "c"},
          }
          return t
        end
        local function describe(v)
          if type(v) ~= "table" then return tostring(v) end
          local keys = {}
          -- (the hook table's keys are threads: addresses differ between the runs)
          for k in pairs(v) do keys[#keys + 1] = (tostring(k):gsub("0x%x+", "0x?")) end
          table.sort(keys)
          return "{" .. table.concat(keys, ",") .. "}"
        end
        for _, count in ipairs({1, 2, 3, 5}) do
          local steps = {}
          debug.sethook(function(event, line)
            if debug.getinfo(2, "f").func ~= build then return end
            local registers = {}
            for i = 1, 20 do
              local name, value = debug.getlocal(2, i)
              if not name then break end
              registers[#registers + 1] = describe(value)
            end
            steps[#steps + 1] = event .. ":" .. tostring(line) .. " " .. table.concat(registers, " ")
            if #steps == 4 then debug.setlocal(2, 2, "changed") end  -- a temporary the constructor stores later
            if #steps == 6 then
              local _, t = debug.getlocal(2, 1)
              if type(t) == "table" then
                setmetatable(t, {__newindex = function(t, k, v) rawset(t, k, "via __newindex") end})
              end
            end
          end, "l", count)
          local t = build()
          debug.sethook()
          print(count, describe(t), t[1], t[2], t.x, t.y, t[3][1])
          for _, step in ipairs(steps) do print("", step) end
        end
        print(pcall(load("local t = {1, 2, [nil] = 3}")))
        LUA;
    assertSameAsInline('hooks', $source);
}

function test_a_data_module_is_one_call_without_literals(): void
{
    $rows = [];
    for ($i = 0; $i < 5000; $i++) {
        $rows[] = sprintf('  {zip = "%05d", city = "City%d", state = "NY"},', $i, $i % 700);
    }
    $code = Emitter::emitChunk(Compiler::compile("return {\n" . implode("\n", $rows) . "\n}\n", '@zips.lua'));
    assertSame(1, substr_count($code, 'TableConstructor::run($L, $ci, $R, 1, '), 'one call for the whole constructor');
    assertSame(0, substr_count($code, 'new LuaTable('), 'no literals: too many instructions');
    assertTrue(\strlen($code) < 10000, 'emitted PHP of 5000 rows: ' . \strlen($code) . ' bytes');
}

function test_small_constructors_get_a_fast_path_of_literals(): void
{
    $long = str_repeat('long string ', 5);
    $code = Emitter::emitChunk(Compiler::compile("local e = {}\nprint(e)\nlocal t = {x = 1, y = 's', 1, 2.5, {true}, '$long'}\nreturn e, t", '=small'));
    assertTrue(str_contains($code, "// [1] NEWTABLE 0 0 0 ; line 1\n"), '{} stays inline');
    assertTrue(str_contains($code, "// [6..17] constant table constructor: 12 instructions ; lines 3-3\n"), 'a run');
    assertTrue(preg_match('/if \(!\$trap && \$L->globalState->gcDebt \+ (\d+) <= 0\) \{/', $code, $match) === 1, 'fast path when no hook or collection can run');
    assertTrue(str_contains($code, "\$L->globalState->gcDebt += {$match[1]};"), 'charges the same debt');
    assertTrue(str_contains($code, "\$table0->arr = [1 => 1, 2 => 2.5, 3 => \$table1, 4 => \$cl->proto->k[6]];"), 'array part, long strings from the Proto');
    assertTrue(str_contains($code, "\$table0->hash = ['x' => 1, 'y' => 's'];"), 'hash part');
    assertTrue(str_contains($code, "\$table1->arr = [1 => true];"), 'nested table');
    assertTrue(str_contains($code, 'TableConstructor::run($L, $ci, $R, 6, 17);'), 'steps otherwise');
}
