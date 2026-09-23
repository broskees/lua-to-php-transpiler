<?php

declare(strict_types=1);

namespace Tests\DumpUndumpTest;

use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\Dump;
use LuaPhp\Compiler\Undump;

/**
 * Compile an official test file with the reference compiler, from the
 * file's own directory so the chunk name is "@<basename>".
 */
function luacOutput(string $luaFile, bool $strip): string
{
    $outputFile = scratchDirectory() . '/' . basename($luaFile) . ($strip ? '.stripped' : '') . '.luac';
    $command = $strip
        ? ['luac5.4', '-s', '-o', $outputFile, basename($luaFile)]
        : ['luac5.4', '-o', $outputFile, basename($luaFile)];
    [$exitCode, , $errorOutput] = runCommand($command, '', dirname($luaFile));
    assertSame(0, $exitCode, "luac5.4 failed on $luaFile: $errorOutput");
    return file_get_contents($outputFile);
}

function test_dump_of_undump_is_identity_for_every_official_file(): void
{
    foreach (officialTestFiles() as $luaFile) {
        $bytes = luacOutput($luaFile, false);
        $proto = Undump::undump($bytes, '@' . basename($luaFile));
        assertTrue(Dump::dump($proto, false) === $bytes, basename($luaFile) . ': Dump(Undump(luac -o)) differs');
    }
}

function test_dump_of_undump_is_identity_for_every_stripped_official_file(): void
{
    foreach (officialTestFiles() as $luaFile) {
        $strippedBytes = luacOutput($luaFile, true);
        $proto = Undump::undump($strippedBytes, '@' . basename($luaFile));
        assertTrue(Dump::dump($proto, true) === $strippedBytes, basename($luaFile) . ': stripped Dump(Undump(luac -s -o)) differs');
    }
}

function test_unstripped_dump_of_a_stripped_chunk_matches_the_reference(): void
{
    // ldump.c writes 'sizeupvalues' (null) upvalue names when not stripping,
    // so this is not the identity in C either.
    $script = scratchDirectory() . '/redump.lua';
    file_put_contents($script, <<<'LUA'
        local f = load("local a = 1 return function() return a end", "=x")
        io.open(arg[1], "wb"):write(string.dump(f, true)):close()
        io.open(arg[2], "wb"):write(string.dump(load(string.dump(f, true)), false)):close()
        LUA);
    $strippedFile = scratchDirectory() . '/redump-stripped.bin';
    $redumpedFile = scratchDirectory() . '/redump-redumped.bin';
    [$exitCode, , $errorOutput] = runCommand(['lua5.4', $script, $strippedFile, $redumpedFile]);
    assertSame(0, $exitCode, $errorOutput);

    $proto = Undump::undump(file_get_contents($strippedFile), '=x');
    assertTrue(Dump::dump($proto, false) === file_get_contents($redumpedFile), 'unstripped re-dump differs from string.dump');
}

function test_dump_with_strip_matches_luac_s(): void
{
    foreach (officialTestFiles() as $luaFile) {
        $proto = Undump::undump(luacOutput($luaFile, false), '@' . basename($luaFile));
        assertTrue(Dump::dump($proto, true) === luacOutput($luaFile, true), basename($luaFile) . ': Dump(strip) differs from luac -s');
    }
}

/**
 * What luac's luaL_loadfile hands the parser: a '#' first line (all.lua's
 * "#!../lua") becomes an empty line. compile() takes source as load() does.
 */
function sourceAsLoadfileReadsIt(string $contents): string
{
    if (!str_starts_with($contents, '#')) {
        return $contents;
    }
    $newlinePosition = strpos($contents, "\n");
    return $newlinePosition === false ? "\n" : "\n" . substr($contents, $newlinePosition + 1);
}

function test_compile_then_dump_matches_luac_for_every_official_file(): void
{
    foreach (officialTestFiles() as $luaFile) {
        $proto = Compiler::compile(sourceAsLoadfileReadsIt(file_get_contents($luaFile)), '@' . basename($luaFile));
        assertTrue(Dump::dump($proto, false) === luacOutput($luaFile, false), basename($luaFile) . ': Dump(compile()) differs from luac -o');
    }
}

function test_undumped_proto_has_the_expected_shape(): void
{
    $proto = Compiler::compile("local function f(a, ...)\n  local b = a\n  return b\nend\nreturn f\n", '=shape');
    $proto = Undump::undump(Dump::dump($proto, false), '=shape');

    assertSame('=shape', $proto->source);
    assertSame(0, $proto->linedefined);
    assertSame(true, $proto->is_vararg);
    assertSame(1, count($proto->p));
    $function = $proto->p[0];
    assertSame('=shape', $function->source, 'child inherits the parent source');
    assertSame(1, $function->linedefined);
    assertSame(4, $function->lastlinedefined);
    assertSame(1, $function->numparams);
    assertSame(true, $function->is_vararg);
    assertSame(['a', 'b'], array_map(fn ($locvar) => $locvar->varname, $function->locvars));
    assertSame('_ENV', $proto->upvalues[0]->name);
    assertSame(true, $proto->upvalues[0]->instack);
}

function test_constants_keep_their_types_through_dump_and_undump(): void
{
    $proto = Compiler::compile(
        'local t = {nil, true, false, 1.5, 7, -0x8000000000000000, 1e300, "short", "' . str_repeat('x', 41) . '"} return t',
        '=constants',
    );
    $reloaded = Undump::undump(Dump::dump($proto, false), '=constants');
    assertSame($proto->k, $reloaded->k);
    assertTrue(in_array(PHP_INT_MIN, $reloaded->k, true), 'integer constant PHP_INT_MIN survives');
    assertTrue(in_array(str_repeat('x', 41), $reloaded->k, true), 'long string constant survives');
}
