<?php

declare(strict_types=1);

namespace Tests\EmbedConversionTest;

use LuaPhp\Embed\ConversionError;
use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Libraries;
use LuaPhp\Embed\LuaFunction;
use LuaPhp\Embed\LuaTable;
use LuaPhp\Embed\LuaValue;
use LuaPhp\Embed\Sandbox;

/*
 * Values between PHP and Lua (LuaPhp\Embed): the brief's tables. Tables a
 * script builds are described by lua5.4 itself (DESCRIBE, which states the
 * conversion rules in Lua: lists 1..n, PHP's integer-like string keys,
 * the errors), and the PHP arrays our conversion makes of the same tables
 * must say the same.
 */

/**
 * describe(v): a canonical text of what v becomes in PHP. Lists (keys
 * exactly 1..n) are L{...}, other tables T{key=value,...} sorted by key,
 * with keys as PHP arrays keep them (a string that is a canonical decimal
 * integer becomes an integer); tables that cannot convert are ERROR:kind.
 */
const DESCRIBE = <<<'LUA'
    local function phpKey(k)
      if math.type(k) == "integer" then return "i:" .. k end
      if type(k) == "string" then
        local n = math.tointeger(tonumber(k))
        if n and string.format("%d", n) == k then return "i:" .. k end
        return "s:" .. k
      end
      return nil
    end
    local describe
    local function scalar(v)
      local t = math.type(v) or type(v)
      if t == "float" then return "f:" .. string.format("%.17g", v) end
      if t == "integer" then return "i:" .. v end
      if t == "string" then return "s:" .. v end
      if t == "boolean" or t == "nil" then return tostring(v) end
      return t
    end
    function describe(v, onPath, depth)
      if type(v) ~= "table" then return scalar(v) end
      onPath, depth = onPath or {}, depth or 0
      if onPath[v] then error("ERROR:cycle", 0) end
      if depth >= 100 then error("ERROR:depth", 0) end
      onPath[v] = true
      local count, n, keys, seen = 0, 0, {}, {}
      for k in next, v do
        count = count + 1
        local key = phpKey(k)
        if key == nil then error("ERROR:key", 0) end
        if seen[key] then error("ERROR:both", 0) end
        seen[key] = true
        keys[#keys + 1] = {key, k}
      end
      while rawget(v, n + 1) ~= nil do n = n + 1 end
      local parts = {}
      if n == count then
        for i = 1, n do parts[i] = describe(rawget(v, i), onPath, depth + 1) end
        onPath[v] = nil
        return "L{" .. table.concat(parts, ",") .. "}"
      end
      table.sort(keys, function (a, b) return a[1] < b[1] end)
      for i, entry in ipairs(keys) do parts[i] = entry[1] .. "=" .. describe(rawget(v, entry[2]), onPath, depth + 1) end
      onPath[v] = nil
      return "T{" .. table.concat(parts, ",") .. "}"
    end
    local function safely(v)
      local ok, text = pcall(describe, v)
      return text
    end
    return safely
    LUA;

/** the tables of the comparison, as a global list 'cases' */
const CASES = <<<'LUA'
    local deep = {}
    local level = deep
    for i = 1, 99 do level[1] = {}; level = level[1] end
    local tooDeep = {deep}
    local cyclic = {name = "loop"}
    cyclic.self = cyclic
    local shared = {"s"}
    local holes = {1, 2, 3}; holes[2] = nil
    local shrunk = {1, 2, 3}; shrunk[3] = nil
    local reversed = {}; reversed[3] = "c"; reversed[2] = "b"; reversed[1] = "a"
    local removed = {"a", "b", "c", "d"}; table.remove(removed, 2); table.insert(removed, 1, "z")
    local cleared = {x = 1, y = 2, 10, 20}; cleared.x = nil; cleared[2] = nil
    local grown = {}; for i = 1, 300 do grown[i] = i * 2 end
    local withMeta = setmetatable({1, 2}, {__index = function () return 5 end, __len = function () return 99 end,
      __pairs = function () error("no __pairs") end})
    cases = {
      {10, 20, 30}, {}, {name = "Ana", tags = {"vip"}}, {[1] = "a", [3] = "c"}, {"a", "b", n = 2},
      {[1] = "a", ["1"] = "b"}, {[1.5] = "x"}, {[true] = "x"}, {[{}] = 1}, {[print] = 1}, cyclic,
      {shared, shared, {shared}}, holes, shrunk, reversed, removed, cleared, grown, withMeta,
      {[2.0] = "two"}, {[1.0] = "one", [2] = "two"}, {["10"] = "a", ["01"] = "b", ["-5"] = "c", ["1e2"] = "d",
      ["9223372036854775808"] = "e", ["-0"] = "f", [" 1"] = "g"}, {[10] = "int", ["10"] = "string"},
      {[0] = "zero", [5] = "five", "one"}, {[-1] = "minus", [math.mininteger] = "min", [math.maxinteger] = "max"},
      {3.0, 0.1, 1e300, -0.0, 1/3, math.huge, -math.huge, 2^53, math.mininteger, true, false, "a\0b", "caf\u{e9}"},
      {f = print, g = function () end, co = coroutine.create(function () end)},
      deep, tooDeep, {a = {b = {c = {cyclic}}}},
    }
    LUA;

function sandbox(): Sandbox
{
    return (new Environment())->newSandbox();
}

/** the PHP counterpart of DESCRIBE, for a value our conversion made */
function describePhp(mixed $value): string
{
    if (\is_array($value)) {
        $parts = [];
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $parts[] = describePhp($item);
            }
            return 'L{' . implode(',', $parts) . '}';
        }
        $keys = [];
        foreach ($value as $key => $item) {
            $keys[(\is_int($key) ? 'i:' : 's:') . $key] = $item;
        }
        ksort($keys, SORT_STRING);
        foreach ($keys as $key => $item) {
            $parts[] = $key . '=' . describePhp($item);
        }
        return 'T{' . implode(',', $parts) . '}';
    }
    return match (true) {
        \is_float($value) => 'f:' . (is_infinite($value) ? ($value > 0 ? 'inf' : '-inf') : sprintf('%.17g', $value)),
        \is_int($value) => "i:$value",
        \is_string($value) => "s:$value",
        \is_bool($value) => $value ? 'true' : 'false',
        $value === null => 'nil',
        $value instanceof LuaFunction => 'function',
        $value instanceof LuaValue => $value->type(),
    };
}

/** @return list<string> the reference's description of every case */
function referenceDescriptions(): array
{
    $script = 'local describe = (function () ' . DESCRIBE . " end)()\n" . CASES . "\nfor i = 1, #cases do io.write(describe(cases[i]), '\\n') end";
    [$status, $output, $errors] = runCommand(['lua5.4', '-'], $script);
    assertSame(0, $status, $errors);
    return explode("\n", rtrim($output, "\n"));
}

function test_tables_a_script_builds_convert_as_lua54_sees_them(): void
{
    $expected = referenceDescriptions();
    $sandbox = sandbox();
    $sandbox->load(CASES, '=cases')->call();
    $cases = $sandbox->getGlobalHandle('cases');
    assertTrue($cases instanceof LuaTable);
    assertSame(\count($expected), $cases->length());
    $kinds = ['a cycle' => 'cycle', 'deeper than' => 'depth', 'keys must be' => 'key', 'has both' => 'both'];
    foreach ($expected as $index => $description) {
        $case = $cases->get($index + 1);
        try {
            $actual = describePhp($case->toArray());
        } catch (ConversionError $error) {
            $actual = 'ERROR:?';
            foreach ($kinds as $words => $kind) {
                if (str_contains($error->reason, $words)) {
                    $actual = "ERROR:$kind";
                }
            }
        }
        assertSame($description, $actual, 'case ' . ($index + 1));
    }
}

function test_lua_values_to_php(): void
{
    $sandbox = (new Environment(libraries: Libraries::ALL))->newSandbox();  // (io, for a userdata)
    [$nil, $true, $integer, $float, $string, $list, $empty, $record] = $sandbox->load(
        'return nil, true, 3, 3.0, "hi", {10, 20, 30}, {}, {name = "Ana", tags = {"vip"}}'
    )->call();
    assertSame(null, $nil);
    assertSame(true, $true);
    assertSame(3, $integer);
    assertSame(3.0, $float);
    assertSame('hi', $string);
    assertSame([10, 20, 30], $list);
    assertSame([], $empty);
    assertSame(['name' => 'Ana', 'tags' => ['vip']], $record);
    assertSame([[1 => 'a', 3 => 'c'], [1 => 'a', 2 => 'b', 'n' => 2]], $sandbox->load('return {[1] = "a", [3] = "c"}, {"a", "b", n = 2}')->call());
    [$function, $thread, $file] = $sandbox->load('return print, coroutine.create(print), io.stdout')->call();
    assertTrue($function instanceof LuaFunction);
    assertSame('thread', $thread->type());
    assertSame('userdata', $file->type());
    assertTrue(!($thread instanceof LuaTable) && !($thread instanceof LuaFunction));
    // keys stay as they are, so a table with keys 0..n-1 looks like a list in PHP
    assertSame([[0 => 'zero', 1 => 'one']], $sandbox->load('return {[0] = "zero", "one"}')->call());
    // trailing nils are values too
    assertSame([null, 2, null], $sandbox->load('return nil, 2, nil')->call());
}

function test_errors_name_the_path_to_the_bad_value(): void
{
    $sandbox = sandbox();
    $cases = [
        'return 1, {name = "x", tags = {"a", "b", {[true] = 1}}}' => 'cannot convert table key true to PHP (keys must be integers or strings) at result[2].tags[3]',
        'return {[1] = "a", ["1"] = "b"}' => 'table has both 1 and "1" as keys at result[1]',
        'return {{}, {[1.5] = "x"}}' => 'cannot convert table key 1.5 to PHP (keys must be integers or strings) at result[1][2]',
        'return {[{}] = 1}' => 'cannot convert a table key of type table to PHP (keys must be integers or strings) at result[1]',
        'local t = {} t.self = t return t' => 'table contains a cycle at result[1].self',
        'local t = {} t["a b"] = {t} return t' => 'table contains a cycle at result[1]["a b"][1]',
        'local t = {} for i = 1, 100 do t = {x = t} end return t' => 'table nested deeper than 100 levels at result[1]' . str_repeat('.x', 100),
    ];
    foreach ($cases as $code => $message) {
        assertSame($message, assertThrows(ConversionError::class, static fn () => $sandbox->load($code)->call()), $code);
    }
    // the path of a global, and of a table handle
    $sandbox->load('config = {db = {hosts = {"a", {}}}}; config.db.hosts[2][false] = 1')->call();
    $error = null;
    try {
        $sandbox->getGlobal('config');
    } catch (ConversionError $error) {
    }
    assertSame('config.db.hosts[2]', $error?->path);
    assertSame('cannot convert table key false to PHP (keys must be integers or strings)', $error->reason);
    assertSame('cannot convert table key false to PHP (keys must be integers or strings) at table.db.hosts[2]',
        assertThrows(ConversionError::class, static fn () => $sandbox->getGlobalHandle('config')->toArray()));
    // 100 levels are fine
    assertSame(100, depthOf($sandbox->load('local t = {} for i = 1, 99 do t = {x = t} end return t')->call()[0]));
}

function depthOf(array $value): int
{
    return $value === [] ? 1 : 1 + depthOf($value['x']);
}

function test_php_values_to_lua(): void
{
    $describe = static fn (Sandbox $sandbox, mixed $value): string => $sandbox->load(DESCRIBE, '=describe')->call()[0]->call($value)[0];
    $sandbox = sandbox();
    $cases = [
        [null, 'nil'], [true, 'true'], [42, 'i:42'], [4.2, 'f:4.2000000000000002'], ['hi', 's:hi'],
        [['a', 'b'], 'L{s:a,s:b}'],
        [['name' => 'Ana', 10 => 'x'], 'T{i:10=s:x,s:name=s:Ana}'],
        [['10' => 'x'], 'T{i:10=s:x}'],
        [[], 'L{}'],
        [[1 => 'a', 2 => 'b'], 'L{s:a,s:b}'],  // not a PHP list, but its keys are 1..n in Lua too
        [[0 => 'a', 2 => 'c'], 'T{i:0=s:a,i:2=s:c}'],
        [['a', null, 'c'], 'T{i:1=s:a,i:3=s:c}'],
        [['x' => null, 'y' => 1], 'T{s:y=i:1}'],
        [['nested' => [[1, 2], ['k' => [true]]]], 'T{s:nested=L{L{i:1,i:2},T{s:k=L{true}}}}'],
        [[1.0, -0.0, INF, PHP_INT_MIN], 'L{f:1,f:-0,f:inf,i:-9223372036854775808}'],
        ['system', 's:system'],
    ];
    foreach ($cases as [$value, $description]) {
        assertSame($description, $describe($sandbox, $value), var_export($value, true));
    }
    // types in Lua; list sizes; a string is never a function
    $sandbox->setGlobal('v', ['10' => 'x', 'list' => ['a', 'b'], 'int' => 42, 'float' => 4.2, 'call' => 'system']);
    assertSame(['x', true, 2, 'integer', 'float', 'string'], $sandbox->load(
        'return v[10], v["10"] == nil, #v.list, math.type(v.int), math.type(v.float), type(v.call)'
    )->call());
    assertThrows(\LuaPhp\Embed\RuntimeError::class, static fn () => $sandbox->load('return v.call()')->call());
}

function test_php_values_that_cannot_go_to_lua(): void
{
    $sandbox = sandbox();
    assertSame('cannot pass object of class DateTime to Lua at when',
        assertThrows(ConversionError::class, static fn () => $sandbox->setGlobal('when', new \DateTime())));
    assertSame("cannot pass object of class ArrayObject to Lua at config['items'][1]",
        assertThrows(ConversionError::class, static fn () => $sandbox->setGlobal('config', ['items' => [1, new \ArrayObject()]])));
    assertSame('cannot pass object of class stdClass to Lua at args[1]',
        assertThrows(ConversionError::class, static fn () => $sandbox->load('return ...')->call(1, new \stdClass())));
    $resource = fopen('php://memory', 'r');
    assertSame('cannot pass a resource (stream) to Lua at file',
        assertThrows(ConversionError::class, static fn () => $sandbox->setGlobal('file', $resource)));
    fclose($resource);
    // an invokable object is not a Closure: not a function either
    $invokable = new class () {
        public function __invoke(): int
        {
            return 1;
        }
    };
    assertTrue(str_starts_with(assertThrows(ConversionError::class, static fn () => $sandbox->setGlobal('f', $invokable)), 'cannot pass object of class '));
    // nesting: 100 levels, not 101; a reference loop is infinitely deep
    $deep = [];
    for ($i = 1; $i < 100; $i++) {
        $deep = [$deep];
    }
    $sandbox->setGlobal('deep', $deep);
    assertSame('array nested deeper than 100 levels at deep' . str_repeat('[0]', 100),
        assertThrows(ConversionError::class, static fn () => $sandbox->setGlobal('deep', [$deep])));
    $loop = ['name' => 'loop'];
    $loop['self'] = &$loop;
    assertSame("array nested deeper than 100 levels at loop" . str_repeat("['self']", 100),
        assertThrows(ConversionError::class, static fn () => $sandbox->setGlobal('loop', $loop)));
    // nothing was set by a failed conversion
    assertSame(null, $sandbox->getGlobal('config'));
    assertSame(null, $sandbox->getGlobal('loop'));
}

function test_handles_go_back_only_into_their_own_sandbox(): void
{
    $first = sandbox();
    $second = sandbox();
    [$table, $function] = $first->load('t = {1, 2} f = function () return t end return t, f')->call();
    $tableHandle = $first->getGlobalHandle('t');
    assertTrue($tableHandle instanceof LuaTable);
    assertTrue($function instanceof LuaFunction);
    // the same Lua value, not a copy
    $first->setGlobal('back', $tableHandle);
    assertSame([true, true], $first->load('return rawequal(back, t), rawequal(..., f)')->call($function));
    assertSame('cannot pass a value of another sandbox to Lua at x',
        assertThrows(ConversionError::class, static fn () => $second->setGlobal('x', $tableHandle)));
    assertSame('cannot pass a value of another sandbox to Lua at args[0]',
        assertThrows(ConversionError::class, static fn () => $second->load('return ...')->call($function)));
    assertSame('cannot register a value of a sandbox in an Environment at x',
        assertThrows(ConversionError::class, static fn () => (new Environment())->addGlobal('x', $tableHandle)));
}

function test_metatables_are_ignored(): void
{
    $sandbox = sandbox();
    $values = $sandbox->load(<<<'LUA'
        local proxy = setmetatable({}, {__index = {hidden = 1}, __len = function () return 5 end})
        local object = setmetatable({x = 1}, {__pairs = function () error("pairs must not run") end,
          __tostring = function () return "object" end})
        return proxy, object
        LUA)->call();
    assertSame([[], ['x' => 1]], $values);
}

function test_table_handles(): void
{
    $sandbox = sandbox();
    $sandbox->load('config = setmetatable({tax = 0.08, list = {"a", "b"}, [true] = "yes", [2.5] = "float"}, {__index = function () return "meta" end})')->call();
    $config = $sandbox->getGlobalHandle('config');
    assertSame(0.08, $config->get('tax'));
    assertSame(null, $config->get('missing'), 'raw: no __index');
    assertSame('yes', $config->get(true));
    assertSame('float', $config->get(2.5));
    $list = $config->get('list');
    assertTrue($list instanceof LuaTable);
    assertSame(2, $list->length());
    assertSame(['a', 'b'], $list->toArray());
    $config->set('tax', 0.1);
    $config->set('added', ['x' => 1]);
    $config->set(3, 'three');
    $config->set(3.0, 'three again');  // the same key as 3
    $config->set('tax', 0.1);
    assertSame([0.1, 1, 'three again'], $sandbox->load('return config.tax, config.added.x, config[3]')->call());
    $config->set('added', null);
    assertSame([true], $sandbox->load('return rawget(config, "added") == nil')->call());
    assertSame('a table index cannot be nil', assertThrows(ConversionError::class, static fn () => $config->set(null, 1)));
    assertSame('a table index cannot be NaN', assertThrows(ConversionError::class, static fn () => $config->set(NAN, 1)));
    // pairs: every key, of any type, as get gives it; entries may be cleared while iterating
    $seen = [];
    foreach ($config->pairs() as $key => $value) {
        $seen[] = [\is_object($key) ? 'handle' : $key, \is_object($value) ? $value::class : $value];
        $config->set($key, null);
    }
    usort($seen, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
    assertSame([['list', LuaTable::class], ['tax', 0.1], [2.5, 'float'], [3, 'three again'], [true, 'yes']], $seen);
    assertSame(0, iterator_count($config->pairs()));
    $sandbox->load('keyed = {[{}] = "table key"}')->call();
    foreach ($sandbox->getGlobalHandle('keyed')->pairs() as $key => $value) {
        assertTrue($key instanceof LuaTable);
        assertSame('table key', $value);
    }
}
