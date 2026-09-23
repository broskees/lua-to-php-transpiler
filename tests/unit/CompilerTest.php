<?php

declare(strict_types=1);

namespace Tests\CompilerTest;

use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;

function compileError(string $source, string $chunkname): string
{
    return assertThrows(CompileError::class, fn () => Compiler::compile($source, $chunkname));
}

/*
 * Expected messages come from the reference interpreter:
 *     print(select(2, load(SOURCE, CHUNKNAME, "t")))
 * where CHUNKNAME defaults to SOURCE, as load() does.
 */
function test_syntax_errors_carry_luas_message_and_chunk_name(): void
{
    assertSame('[string "x = +"]:1: unexpected symbol near \'+\'', compileError('x = +', 'x = +'));
    assertSame('foo.lua:1: unexpected symbol near \'+\'', compileError('x = +', '@foo.lua'));
    assertSame('stdin:1: unexpected symbol near \'+\'', compileError('x = +', '=stdin'));
    assertSame(
        '[string "local x <const> = 1; x = 2"]:1: attempt to assign to const variable \'x\'',
        compileError('local x <const> = 1; x = 2', 'local x <const> = 1; x = 2'),
    );
    assertSame("multi:3: 'end' expected (to close 'if' at line 1) near <eof>", compileError("if x then\n  y = 1\n", '=multi'));
    assertSame('[string "x = \'unfinished"]:1: unfinished string near <eof>', compileError("x = 'unfinished", "x = 'unfinished"));
    assertSame("goto:1: no visible label 'nowhere' for <goto> at line 1", compileError('goto nowhere', '=goto'));
    $longFileName = '@' . str_repeat('d/', 40) . 'file.lua';
    assertSame(
        '...' . str_repeat('d/', 24) . 'file.lua:4: unexpected symbol near <eof>',
        compileError("x = 1\n\n\n  +", $longFileName),
    );
}

function test_source_is_text_even_when_a_file_reader_would_skip_its_start(): void
{
    // luaL_loadfile skips a '#' first line and a BOM; load() of a string does not.
    assertSame(
        '[string "#!/usr/bin/lua..."]:1: unexpected symbol near \'#\'',
        compileError("#!/usr/bin/lua\nreturn 1", "#!/usr/bin/lua\nreturn 1"),
    );
    assertSame(
        "[string \"\xEF\xBB\xBFreturn 1\"]:1: unexpected symbol near '<\\239>'",
        compileError("\xEF\xBB\xBFreturn 1", "\xEF\xBB\xBFreturn 1"),
    );
}

function test_every_proto_gets_the_chunk_name_as_source(): void
{
    $proto = Compiler::compile("local function f()\n  return function() end\nend\n", '@my/file.lua');
    assertSame('@my/file.lua', $proto->source);
    assertSame('@my/file.lua', $proto->p[0]->source);
    assertSame('@my/file.lua', $proto->p[0]->p[0]->source);
    assertSame(1, $proto->p[0]->linedefined);
    assertSame(2, $proto->p[0]->p[0]->linedefined);
}
