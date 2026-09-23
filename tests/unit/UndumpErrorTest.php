<?php

declare(strict_types=1);

namespace Tests\UndumpErrorTest;

use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Dump;
use LuaPhp\Compiler\OpCodes;
use LuaPhp\Compiler\Proto;
use LuaPhp\Compiler\Undump;
use LuaPhp\Compiler\UpvalDesc;

/*
 * Expected messages come from the reference interpreter: the same bytes were
 * written to a file and loaded with
 *     lua5.4 -e 'local f = io.open(FILE, "rb") print(load(f:read("a"), CHUNKNAME))'
 * (CHUNKNAME omitted means load()'s default: the chunk itself, whose first
 * byte is ESC, so Lua names it "binary string").
 */

/**
 * The chunk of `return "hello"` with chunk name "=base", built by hand so the
 * byte offsets below are fixed:
 *   0-3 signature, 4 version, 5 format, 6-11 LUAC_DATA, 12-14 type sizes,
 *   15-22 LUAC_INT, 23-30 LUAC_NUM, 31 upvalue count, 32-37 source "=base",
 *   38 linedefined, ...
 */
function baseChunk(): string
{
    $proto = new Proto();
    $proto->source = '=base';
    $proto->is_vararg = true;
    $proto->maxstacksize = 2;
    $proto->code = [
        OpCodes::CREATE_ABCk(OpCodes::OP_VARARGPREP, 0, 0, 0, 0),
        OpCodes::CREATE_ABx(OpCodes::OP_LOADK, 0, 0),
        OpCodes::CREATE_ABCk(OpCodes::OP_RETURN, 0, 2, 1, 0),
    ];
    $proto->k = ['hello'];
    $proto->upvalues = [new UpvalDesc('_ENV', true, 0, 0)];
    $proto->lineinfo = [1, 0, 0];
    return Dump::dump($proto, false);
}

function replaceByte(string $bytes, int $offset, string $newByte): string
{
    return substr($bytes, 0, $offset) . $newByte . substr($bytes, $offset + 1);
}

function undumpError(string $bytes, string $chunkname): string
{
    return assertThrows(CompileError::class, fn () => Undump::undump($bytes, $chunkname));
}

function test_the_base_chunk_loads(): void
{
    $proto = Undump::undump(baseChunk(), '=base');
    assertSame('=base', $proto->source);
    assertSame(['hello'], $proto->k);
}

function test_every_truncation_reports_truncated_chunk(): void
{
    $chunk = baseChunk();
    for ($length = 1; $length < strlen($chunk); $length++) {
        assertSame(
            'binary string: bad binary format (truncated chunk)',
            undumpError(substr($chunk, 0, $length), substr($chunk, 0, $length)),
            "prefix of $length bytes",
        );
    }
}

function test_header_checks_report_what_is_wrong(): void
{
    $chunk = baseChunk();
    $cases = [
        ["\x1bLux", 'not a binary chunk'],
        ["\x1bLu", 'truncated chunk'],
        ["\x1b", 'truncated chunk'],
        [replaceByte($chunk, 4, "\x53"), 'version mismatch'],
        [replaceByte($chunk, 5, "\x01"), 'format mismatch'],
        [replaceByte($chunk, 9, "\x00"), 'corrupted chunk'],
        [replaceByte($chunk, 12, "\x05"), 'Instruction size mismatch'],
        [replaceByte($chunk, 13, "\x04"), 'lua_Integer size mismatch'],
        [replaceByte($chunk, 14, "\x04"), 'lua_Number size mismatch'],
        [replaceByte($chunk, 15, "\x79"), 'integer format mismatch'],
        [replaceByte($chunk, 29, "\x00"), 'float format mismatch'],
    ];
    foreach ($cases as [$bytes, $why]) {
        assertSame("binary string: bad binary format ($why)", undumpError($bytes, $bytes));
    }
}

function test_body_checks_report_what_is_wrong(): void
{
    $chunk = baseChunk();
    // linedefined (offset 38) as a varint that exceeds INT_MAX
    $intOverflow = substr($chunk, 0, 38) . "\x7f\x7f\x7f\x7f\x7f\x80" . substr($chunk, 39);
    assertSame('binary string: bad binary format (integer overflow)', undumpError($intOverflow, $intOverflow));

    // source size (offset 32) as a varint that exceeds SIZE_MAX
    $sizeOverflow = substr($chunk, 0, 32) . str_repeat("\x7f", 9) . "\x80" . substr($chunk, 38);
    assertSame('binary string: bad binary format (integer overflow)', undumpError($sizeOverflow, $sizeOverflow));

    // the "hello" constant (tag 4, size 6) replaced by a NULL string (size 0)
    $constantOffset = strpos($chunk, "\x04\x86hello");
    $nullConstant = substr($chunk, 0, $constantOffset + 1) . "\x80" . substr($chunk, $constantOffset + 7);
    assertSame('binary string: bad binary format (bad format for constant string)', undumpError($nullConstant, $nullConstant));
}

function test_error_prefix_comes_from_the_chunk_name(): void
{
    $badFormat = replaceByte(baseChunk(), 5, "\x01");
    assertSame('foo: bad binary format (format mismatch)', undumpError($badFormat, '=foo'));
    assertSame('foo.luac: bad binary format (format mismatch)', undumpError($badFormat, '@foo.luac'));
    assertSame('plain: bad binary format (format mismatch)', undumpError($badFormat, 'plain'));
    assertSame(': bad binary format (format mismatch)', undumpError($badFormat, ''));
}
