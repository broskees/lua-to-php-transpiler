<?php

declare(strict_types=1);

namespace Tests\SyntaxErrorTest;

use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;

/*
 * Compile errors against lua5.4's load(): for every source in the corpus and
 * several chunk-name styles, Compiler::compile must fail exactly when load()
 * fails, with exactly the same message.
 *
 * lua5.4 runs the corpus from a main chunk (C-call depth 2, as for
 * `lua5.4 script.lua`), which is Compiler::compile's default depth. Errors
 * raised by luaG_runerror inside the parser ("C stack overflow", vector
 * limits) have CompileError code LUA_ERRRUN; lua.c's message handler then
 * appends a traceback, so only the message line before it is compared.
 */

const LUA_ERRRUN = 2;

/** Chunk-name styles: load()'s default (the source itself), '=name', '@file', and empty. */
const CHUNKNAME_STYLES = ['source', '=stdin', '@some/dir/file.lua', ''];

/** @return list<string> sources taken from the official test files */
function officialCases(): array
{
    $cases = [
        // errors.lua
        'function a (... , ...) end', 'function a (, ...) end', 'repeat until 1; a', 'return;;',
        "  local a = {4\n\n", "    ::A:: a = 1\n    ::A::\n  ", "    a = 1\n    goto A\n    do ::A:: end\n  ",
        'x', 'syntax error', '1.000', '[[a]]', "'aa'", 'while << do end', 'for >> do end', "a\1a = 1", "\255a = 1",
        'a=9+', 'a = ', 'a = 4+nil', 'local t={}; t = t[#t] + 1',
        'a = f(x' . str_repeat(',x', 260) . ')',
        // constructs.lua
        'local x <XXX> = 10', 'local xxx <const> = 20; xxx = 10',
        "    local xx;\n    local xxx <const> = 20;\n    local yyy;\n    local function foo ()\n"
            . "      local abc = xx + yyy + xxx;\n      return function () return function () xxx = yyy end end\n    end\n  ",
        "    local x <close> = nil\n    x = io.open()\n  ", 'for x do', 'x:call',
        // goto.lua
        ' goto l1; do ::l1:: end ', ' do ::l1:: end goto l1; ', ' ::l1:: ::l1:: ', ' ::l1:: do ::l1:: end',
        ' goto l1; local aa ::l1:: ::l2:: print(3) ', "do local bb, cc; goto l1; end\nlocal aa\n::l1:: print(3)\n",
        ' do ::l1:: end goto l1 ', ' goto l1 do ::l1:: end ',
        "  repeat\n    if x then goto cont end\n    local xuxu = 10\n    ::cont::\n  until xuxu < x\n",
        // locals.lua
        'local x, y <const>, z = 10, 20, 30; x = 11; y = 12', 'local x <const>, y, z <const> = 10, 20, 30; x = 11',
        'local x <const>, y, z <const> = 10, 20, 30; y = 10; z = 11', 'local foo <const> = 10; function foo() end',
        'local foo <const> = {}; function foo() end',
        "    local a, z <const>, b = 10;\n    function foo() a = 20; z = 32; end\n  ",
        "    local a, var1 <const> = 10;\n    function foo() a = 20; z = function () var1 = 12; end  end\n  ",
        // literals.lua
        "a = 'non-ending string", "a = 'non-ending string\n'", "a = '\\345'", 'a = [=x]',
        'return 0xe-', 'return 0xep-p', 'return 1print()', 'return 4.5.', 'a = (3,4)',
    ];
    // literals.lua lexerror cases (compiled as 'return ' .. s)
    $lexerrors = [
        '"abc\x"', '"abc\x', '"\x', '"\x5"', '"\x5', '"\xr"', '"\xr', '"\x.', '"\x8%"', '"\xAG', '"\g"', '"\g',
        '"\."', '"\999"', '"xyz\300"', '"   \256"', '"abc\u{100000000}"', '"abc\u11r"', '"abc\u"', '"abc\u{11r"',
        '"abc\u{11"', '"abc\u{11', '"abc\u{r"', '[=[alo]]', '[=[alo]=', '[=[alo]', "'alo", "'alo \\z  \n\n",
        "'alo \\z", "'alo \\98",
    ];
    foreach ($lexerrors as $lexerror) {
        $cases[] = 'return ' . $lexerror;
    }
    // literals.lua: valid characters in variable names (ESC would make load() read a binary chunk)
    for ($i = 0; $i <= 255; $i++) {
        if ($i !== 27) {
            $cases[] = chr($i) . '=1';
        }
        $cases[] = 'a' . chr($i) . '1 = 1';
    }
    // errors.lua testrep: 100 levels load, 500 levels overflow
    $repetitions = [
        ['local a; a', ',a', '= 1', ',1'], ['local a; a=', '{', '0', '}'], ['return ', '(', '2', ')'],
        ['local function a (x) return x end; return ', 'a(', '2.2', ')'], ['', 'do ', '', ' end'],
        ['', 'while a do ', '', ' end'], ['local a; ', 'if a then else ', '', ' end'],
        ['', 'function foo () ', '', ' end'], ["local a = ''; return ", 'a..', "'a'", ''],
        ['local a = 1; return ', 'a^', 'a', ''],
    ];
    foreach ($repetitions as [$init, $repeated, $close, $repeatedClose]) {
        $cases[] = $init . str_repeat($repeated, 100) . $close . str_repeat($repeatedClose, 100);
        $cases[] = $init . str_repeat($repeated, 500);
    }
    // errors.lua: too many upvalues, too many local variables
    $source = "local function fooA ()\n  local ";
    for ($j = 1; $j <= 127; $j++) {
        $source .= "a$j, ";
    }
    $source .= "b,c\nlocal function fooB ()\n  local ";
    for ($j = 1; $j <= 127; $j++) {
        $source .= "b$j, ";
    }
    $source .= "b\nfunction fooC () return b+c";
    for ($j = 1; $j <= 127; $j++) {
        $source .= "+a$j+b$j";
    }
    $cases[] = $source . "\nend  end end";
    $source = "\nfunction foo ()\n  local ";
    for ($j = 1; $j <= 300; $j++) {
        $source .= "a$j, ";
    }
    $cases[] = $source . "b\n";
    return $cases;
}

/** @return list<string> our own cases: every syntax/semantic error message of llex.c and lparser.c */
function ownCases(): array
{
    $cases = [
        // unexpected tokens and expected tokens
        'x = +', 'x = = 1', 'x', 'x.y', 'f() = 1', '(a) = 1', 'a.b:c = 1', 'return 1 2', 'return return',
        'if x then', "if x then\n  y = 1\n", 'if x y = 1 end', 'if x then else elseif y then end', 'while x y() end',
        'for i = 1 do end', 'for i do end', 'for 1 = 1, 2 do end', 'for i, j = 1, 2 do end', 'for i = 1, 2, 3, 4 do end',
        "do\n\n x = 1", "function f(\n\n", 'function f(a,) end', 'function f(a b) end', 'function f(...,a) end',
        'function f.a:b.c() end', 'function (x) end', 'local function f.x() end', 'local 1 = 2', 'local x <const',
        'local x <const> y', 'local x <close>, y <close> = 1, 2', 'local x <close> = 1, 2', 'local function () end',
        'x = {a = 1 b = 2}', 'x = {a b}', 'x = {[1] 2}', 'x = {1,,2}', 'x = {1;;2}', 'x = {', "x = {\n1,\n2\n",
        'x = function', 'x = function(', 'x = y:z', 'x = y:z.w()', 'x = y:1()', 'x = y.1', 'x = y[1', 'x = (1',
        "x = (1\n\n", 'x = ... ', 'function f() return ... end', 'function f(a) local b = ... end',
        'repeat x = 1', "repeat\n  x = 1\n\nuntil", 'goto', 'goto 1', '::a', '::1::', ':: a ::', 'break',
        'x = 1 break', 'do break end', 'if x then break end', 'function f() break end', 'goto a', "goto a\n::b::",
        'do goto a end ::b::', 'local x; goto a; local y; ::a:: print(y)', 'goto a; local x; ::a:: ; ; ::b:: x = 1',
        'goto a; local x <const> = 1; ::a::', 'goto a; local x <close> = nil; ::a::', "::a::\n::a::",
        "::a:: do ::b:: ::a:: end", 'while true do goto continue; local x = 1; ::continue:: end',
        "repeat goto cont; local x; ::cont:: until x", "repeat goto cont; local x; ::cont:: until true",
        'local x <const> = 1; x = 2', 'local x <close> = nil; x = 2', 'local x <const> = 1; function f() x = 2 end',
        'local x <const> = {}; function f() return function() x = 2 end end', 'local a <const> = 1; a, b = 1, 2',
        'local a <const> = 1; b, a = 1, 2', 'local t <const> = {}; t.x = 1; t = 2', 'local x <foo> = 1',
        'local x <close> = nil; local function f() x = 1 end', 'local _ENV <const> = {}; x = 1',
        'function f() return x.y.z end f() = 1', 'x, y() = 1, 2', 'x, (y) = 1, 2', 'a.b.c:d()  = 1',
        '1 = 2', '"x" = 1', 'nil = 1', 'x = 1 = 2', 'x = not', 'x = #', 'x = 1 + + 1', 'x = 1 ..',
        'x = a and', 'x = [==[ unfinished', 'x = [[ unfinished', '--[[ unfinished comment', "--[==[ unfinished\n\ncomment ]]",
        "x = 'unfinished\nstring'", 'x = "unfinished', "x = 'a\\", "x = 'a\\\n", "x = 'a\\z", 'x = [=a', 'x = [==',
        'x = [=', 'x = "\q"', 'x = "\x"', 'x = "\xg"', 'x = "\x1g"', 'x = "\u"', 'x = "\u{"', 'x = "\u{}"',
        'x = "\u{g}"', 'x = "\u{1"', 'x = "\u{80000000}"', 'x = "\u{7FFFFFFF}"', 'x = "\u{0000000000000041}"',
        'x = "\256"', 'x = "\1234"', 'x = "\999"', 'x = "\25a"', 'x = "abc\zdef\q"', "x = '\\\r\n\\q'",
        'x = 3..2', 'x = 0x', 'x = 1e', 'x = 1e+', 'x = 0xg', 'x = 1.2.3', 'x = 3x', 'x = 0x1p', 'x = .5e',
        'x = 08', 'x = 1_000', 'x = 0x1.8p1x', 'x = 9223372036854775808', 'x = 1e999999', 'x = 0x.p1',
        "x = 1\0", "x = \0", "x = '\0'  +", "x = \"a\0b", "x = [[a\0b", "x = 'a\\0b\\q'", "\0",
        "x = [[a\nb]] +", "x = 'a' 'b'", "x = [[\nlong\nstring]] 1", 'x = 1.5 2', 'x = 0x10 nope', 'x = 1e10 x',
        "x = {a 1}", "t = {x 1.5}", "t = {x = }", "t = {x 'str' y}", "f{} g", "f'x' 1",
        "local function f()\n  return 1\nend\nf() = 2", "x = a:b 1", "x = a:b", 'a:b.c()',
        "\n\n\n\nx = = 1", "x = 1\r\ny = = 2", "x = 1\n\rz = = 2", "x = 1\r\rw = = 2", "x = 1\n\nv = = 2",
        "--[[\n\n\n]] x = = 1", "x = [[\n\n\n]] = 1", "x = 'a\\\n\\\n' = 1", "x = 'a\\z\n\n  ' = 1",
        "\xEF\xBB\xBFx = 1", "#!/bin/lua\nx = 1", "x = 1 -- comment\ny = = 2",
        'local ' . implode(', ', array_map(fn ($i) => "v$i", range(1, 201))),
        'local ' . implode(', ', array_map(fn ($i) => "v$i", range(1, 200))),
        "local function f()\n  local " . implode(', ', array_map(fn ($i) => "v$i", range(1, 201))) . "\nend",
        'function f(' . implode(', ', array_map(fn ($i) => "p$i", range(1, 201))) . ') end',
        'for i = 1, 2 do local ' . implode(', ', array_map(fn ($i) => "v$i", range(1, 197))) . ' end',
        'for a, b, c in x do local ' . implode(', ', array_map(fn ($i) => "v$i", range(1, 194))) . ' end',
        'return ' . str_repeat('(', 196) . '1' . str_repeat(')', 196),
        'return ' . str_repeat('(', 195) . '1' . str_repeat(')', 195),
        'x = ' . str_repeat('{', 197) . str_repeat('}', 197),
        'x = ' . str_repeat('-', 197) . '1', 'x = ' . str_repeat('-', 196) . '1',
        'x = ' . str_repeat('not ', 197) . '1',
        str_repeat('do ', 198) . str_repeat(' end', 198), str_repeat('do ', 197) . str_repeat(' end', 197),
        'x = ' . implode(' .. ', array_fill(0, 200, 'a')), 'x = ' . implode(' .. ', array_fill(0, 197, 'a')),
        'x = ' . implode(' ^ ', array_fill(0, 200, 'a')),
        str_repeat('a.', 300) . 'b = ' . str_repeat('c.', 300) . 'd',
        'a' . str_repeat(', a', 198) . ' = 1', 'a' . str_repeat(', a', 197) . ' = 1',
        'f(' . implode(', ', array_fill(0, 252, 'x')) . ')', 'f(' . implode(', ', array_fill(0, 253, 'x')) . ')',
        'local t = {' . implode(', ', array_fill(0, 253, 'f()')) . '}',
        'local ' . implode(', ', array_map(fn ($i) => "v$i", range(1, 200))) . '; f(' . implode(', ', array_fill(0, 60, 'x')) . ')',
        'x = ' . implode(' + ', array_map(fn ($i) => "f($i)", range(1, 100))),
        'x = ' . str_repeat('f(1) + (', 150) . '1' . str_repeat(')', 150),
    ];
    // too many upvalues: 256 locals of an enclosing function (in two functions, as MAXVARS is 200)
    $outerLocals = implode(', ', array_map(fn ($i) => "u$i", range(1, 130)));
    $innerLocals = implode(', ', array_map(fn ($i) => "w$i", range(1, 130)));
    $uses = implode(' + ', [...array_map(fn ($i) => "u$i", range(1, 130)), ...array_map(fn ($i) => "w$i", range(1, 130))]);
    $cases[] = "local $outerLocals\nlocal function g()\n  local $innerLocals\n  return function()\n    return $uses\n  end\nend";
    $uses255 = implode(' + ', [...array_map(fn ($i) => "u$i", range(1, 130)), ...array_map(fn ($i) => "w$i", range(1, 125))]);
    $cases[] = "local $outerLocals\nlocal function g()\n  local $innerLocals\n  return function()\n    return $uses255\n  end\nend";
    return $cases;
}

/** @return list<array{string, string}> [source, chunkname] pairs */
function corpus(): array
{
    $pairs = [];
    foreach ([...officialCases(), ...ownCases()] as $source) {
        foreach (CHUNKNAME_STYLES as $style) {
            $pairs[] = [$source, $style === 'source' ? $source : $style];
        }
    }
    // chunk names: embedded '\0' ends them (C strings); long ones are truncated by luaO_chunkid
    foreach (["=na\0me", "@fi\0le.lua", "sou\0rce", '=' . str_repeat('n', 100), '@' . str_repeat('d/', 50) . 'f.lua',
        str_repeat('s', 100), "first line\nsecond line", "\0", '='] as $chunkname) {
        $pairs[] = ['x = = 1', $chunkname];
        $pairs[] = ["x = 1\n\ny = 'a\0b", $chunkname];
    }
    return $pairs;
}

/** @param list<array{string, string}> $pairs */
function referenceMessages(array $pairs): array
{
    $casesFile = scratchDirectory() . '/syntax-error-cases.txt';
    $lines = array_map(fn (array $pair) => bin2hex($pair[0]) . ' ' . bin2hex($pair[1]), $pairs);
    file_put_contents($casesFile, implode("\n", $lines) . "\n");
    $scriptFile = scratchDirectory() . '/syntax-errors.lua';
    file_put_contents($scriptFile, <<<'LUA'
        local function unhex(h) return (h:gsub('..', function(x) return string.char(tonumber(x, 16)) end)) end
        local function hex(s) return (s:gsub('.', function(c) return string.format('%02x', c:byte()) end)) end
        for line in io.lines(arg[1]) do
          local source, chunkname = line:match('^(%x*) (%x*)$')
          local f, message = load(unhex(source), unhex(chunkname))
          print(f and 'OK' or ('ERR ' .. hex(message)))
        end
        LUA);
    [$exitCode, $output, $errorOutput] = runCommand(['lua5.4', $scriptFile, $casesFile]);
    assertSame(0, $exitCode, $errorOutput);
    $results = explode("\n", rtrim($output, "\n"));
    assertSame(count($pairs), count($results), 'one lua5.4 answer per case');
    return array_map(fn (string $result) => $result === 'OK' ? null : hex2bin(substr($result, 4)), $results);
}

function test_compile_errors_match_lua_load(): void
{
    $pairs = corpus();
    $expectedMessages = referenceMessages($pairs);
    $failures = [];
    foreach ($pairs as $index => [$source, $chunkname]) {
        $expected = $expectedMessages[$index];
        try {
            Compiler::compile($source, $chunkname);
            $actual = null;
        } catch (CompileError $error) {
            $actual = $error->getMessage();
            if ($error->getCode() === LUA_ERRRUN && $expected !== null) {
                // lua.c's message handler added a traceback to this runtime error
                $tracebackPosition = strpos($expected, "\nstack traceback:\n");
                assertTrue($tracebackPosition !== false, 'runtime error without traceback: ' . var_export($expected, true));
                $expected = substr($expected, 0, $tracebackPosition);
            }
        }
        if ($expected !== $actual) {
            $failures[] = 'load(' . var_export(strlen($source) > 120 ? substr($source, 0, 120) . '...' : $source, true)
                . ', ' . var_export($chunkname === $source ? '<source>' : $chunkname, true) . "):\n       expected "
                . var_export($expected, true) . "\n       got      " . var_export($actual, true);
        }
    }
    assertTrue($failures === [], count($failures) . ' of ' . count($pairs) . " cases differ:\n     "
        . implode("\n     ", array_slice($failures, 0, 15)));
}

function test_control_structure_too_long(): void
{
    // fixforjump: a loop body longer than MAXARG_Bx instructions
    $body = str_repeat("a = 1\n", 131072);
    foreach (["for i = 1, 2 do\n$body end", "for k in pairs(t) do\n$body end"] as $source) {
        $expected = referenceMessages([[$source, '=big']])[0];
        assertSame($expected, assertThrows(CompileError::class, fn () => Compiler::compile($source, '=big')));
    }
    // just below the limit it compiles
    $source = "for i = 1, 2 do\n" . str_repeat("a = 1\n", 131000) . ' end';
    assertSame(null, referenceMessages([[$source, '=big']])[0]);
    Compiler::compile($source, '=big');
}
