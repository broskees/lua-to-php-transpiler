<?php

declare(strict_types=1);

namespace Tests\FrontEndTest;

use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\Dump;
use LuaPhp\Compiler\OpCodes;

/*
 * Front-end behavior that needs sources too big for tests/bytecode, or a
 * caller context other than a main chunk. tests/bytecode.sh covers listings
 * and dumps of the official files and our corpus.
 */

/** The dump lua5.4 makes of load($source, $chunkname), or its error message. */
function referenceDump(string $source, string $chunkname, bool $strip): string
{
    $sourceFile = scratchDirectory() . '/front-end-source.lua';
    file_put_contents($sourceFile, $source);
    $scriptFile = scratchDirectory() . '/front-end-dump.lua';
    file_put_contents($scriptFile, <<<'LUA'
        local file = assert(io.open(arg[1], 'rb'))
        local source = file:read('a')
        file:close()
        local f, message = load(source, arg[2])
        io.write(f and string.dump(f, arg[3] == 'strip') or message)
        LUA);
    [$exitCode, $output, $errorOutput] = runCommand(['lua5.4', $scriptFile, $sourceFile, $chunkname, $strip ? 'strip' : 'keep']);
    assertSame(0, $exitCode, $errorOutput);
    return $output;
}

function test_more_than_MAXARG_Bx_constants_use_LOADKX_and_EXTRAARG(): void
{
    $values = array_map(fn (int $i) => (string) (1000000 + $i), range(0, 131100));
    $source = "local t = {\n" . implode(",\n", $values) . "}\nlocal last = 'the last constant'\nreturn t, last";
    $proto = Compiler::compile($source, '=big');
    $opcodes = array_map([OpCodes::class, 'GET_OPCODE'], $proto->code);
    assertTrue(in_array(OpCodes::OP_LOADKX, $opcodes, true), 'LOADKX is used');
    foreach ([false, true] as $strip) {
        assertTrue(referenceDump($source, '=big', $strip) === Dump::dump($proto, $strip), 'dump differs, strip: ' . var_export($strip, true));
    }
}

function test_vector_limits_are_runtime_errors(): void
{
    // lparser.c registerlocalvar: at most SHRT_MAX debug entries per function
    $source = str_repeat("do local a end\n", 32767);
    assertTrue(referenceDump($source, '=x', false) === Dump::dump(Compiler::compile($source, '=x'), false), '32767 locals compile');
    $source .= "do local a end\n";
    $error = null;
    try {
        Compiler::compile($source, '=x');
    } catch (CompileError $caught) {
        $error = $caught;
    }
    assertTrue($error !== null, 'the 32768th local fails');
    assertSame(2, $error->getCode(), 'LUA_ERRRUN');
    assertSame('too many local variables (limit is 32767)', $error->getMessage());
    assertTrue(str_starts_with(referenceDump($source, '=x', false), "too many local variables (limit is 32767)\nstack traceback:"));
}

function test_nesting_limit_counts_from_the_callers_C_call_depth(): void
{
    // From a main chunk (depth 2) 195 parentheses load and 196 overflow;
    // inside pcall(load, ...) (depth 3) the limit is one lower.
    $nested = fn (int $depth) => 'return ' . str_repeat('(', $depth) . '1' . str_repeat(')', $depth);
    Compiler::compile($nested(195), '=x');
    assertSame('C stack overflow', assertThrows(CompileError::class, fn () => Compiler::compile($nested(196), '=x')));
    Compiler::compile($nested(194), '=x', 3);
    assertSame('C stack overflow', assertThrows(CompileError::class, fn () => Compiler::compile($nested(195), '=x', 3)));

    [$exitCode, $output] = runCommand(['lua5.4', '-e', 'for n = 190, 200 do if not select(2, pcall(load, '
        . '"return " .. string.rep("(", n) .. "1" .. string.rep(")", n))) then print(n) break end end']);
    assertSame(0, $exitCode);
    assertSame("195\n", $output, 'first depth that fails under pcall in lua5.4');
}

function test_past_the_nesting_limit_only_error_handling_may_parse(): void
{
    // lstate.c luaE_checkcstack: depths 201..219 are room for handling a
    // "C stack overflow"; 220 raises "error in error handling" (LUA_ERRERR).
    // In lua5.4, load() called by the message handler of that overflow runs
    // at depth 201.
    $script = scratchDirectory() . '/error-in-error-handling.lua';
    file_put_contents($script, <<<'LUA'
        local failure
        local function handler(m)
          for n = 0, 30 do
            local f, message = load("return " .. string.rep("(", n) .. "1" .. string.rep(")", n), "=h")
            if not f then failure = n .. ": " .. message; break end
          end
          return m
        end
        local function recurse() return string.gsub("x", "x", recurse) end
        print(xpcall(recurse, handler))
        print(failure)
        LUA);
    [$exitCode, $output] = runCommand(['lua5.4', $script]);
    assertSame(0, $exitCode);
    assertSame("false\tC stack overflow\n17: error in error handling\n", $output);

    $nested = fn (int $depth) => 'return ' . str_repeat('(', $depth) . '1' . str_repeat(')', $depth);
    Compiler::compile($nested(16), '=h', 201);
    $error = null;
    try {
        Compiler::compile($nested(17), '=h', 201);
    } catch (CompileError $caught) {
        $error = $caught;
    }
    assertTrue($error !== null, 'depth 220 fails');
    assertSame('error in error handling', $error->getMessage());
    assertSame(5, $error->getCode(), 'LUA_ERRERR');
}

function test_chunk_name_is_a_C_string(): void
{
    // lua_load receives the chunk name as 'const char *'
    $proto = Compiler::compile("return function() end", "=name\0ignored");
    assertSame('=name', $proto->source);
    assertSame('=name', $proto->p[0]->source);
    assertSame('name:1: unexpected symbol near <eof>', assertThrows(CompileError::class, fn () => Compiler::compile('x =', "=name\0ignored")));
}
