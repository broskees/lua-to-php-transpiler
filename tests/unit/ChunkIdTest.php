<?php

declare(strict_types=1);

namespace Tests\ChunkIdTest;

use LuaPhp\Compiler\ChunkId;

/*
 * Expected values come from the reference interpreter:
 *     load("function f () end", CHUNKNAME)(); print(debug.getinfo(f).short_src)
 * The first cases are the ones db.lua (lines 61-83) checks.
 */

function test_string_sources_are_quoted_and_cut_at_45_bytes_or_the_first_newline(): void
{
    $functionSource = 'function f () end';
    assertSame('[string "function f () end"]', ChunkId::of($functionSource));
    assertSame(
        '[string "function f () end; pppppppppppppppppppppppppp..."]',
        ChunkId::of($functionSource . '; ' . str_repeat('p', 400) . "\n=1"),
    );
    assertSame(
        '[string "function f () end; pppppppppppppppppppppppppp..."]',
        ChunkId::of($functionSource . '; ' . str_repeat('p', 400) . '=1'),
    );
    assertSame('[string "..."]', ChunkId::of("\n" . $functionSource));
    assertSame('[string ""]', ChunkId::of(''));
    assertSame('[string "ab..."]', ChunkId::of("ab\ncd"));
    assertSame('[string "' . str_repeat('w', 44) . '"]', ChunkId::of(str_repeat('w', 44)));
    assertSame('[string "' . str_repeat('w', 45) . '..."]', ChunkId::of(str_repeat('w', 45)));
    assertSame('[string "' . str_repeat('w', 45) . '..."]', ChunkId::of(str_repeat('w', 46)));
}

function test_file_names_keep_their_tail(): void
{
    assertSame('xuxu', ChunkId::of('@xuxu'));
    assertSame('', ChunkId::of('@'));
    assertSame('...' . str_repeat('p', 55) . 't', ChunkId::of('@' . str_repeat('p', 1000) . 't'));
    assertSame(str_repeat('z', 59), ChunkId::of('@' . str_repeat('z', 59)));
    assertSame('...' . str_repeat('z', 56), ChunkId::of('@' . str_repeat('z', 60)));
}

function test_literal_names_keep_their_head(): void
{
    assertSame('xuxu', ChunkId::of('=xuxu'));
    assertSame('', ChunkId::of('='));
    assertSame(str_repeat('x', 59), ChunkId::of('=' . str_repeat('x', 500)));
    assertSame(str_repeat('y', 59), ChunkId::of('=' . str_repeat('y', 59)));
    assertSame(str_repeat('y', 59), ChunkId::of('=' . str_repeat('y', 60)));
}

function test_result_ends_at_an_embedded_nul_like_a_c_string(): void
{
    // Through load() Lua already cuts the chunk name at '\0'; a source read
    // from a binary chunk can still hold one, and C's short_src ends there.
    // Checked by patching the source of string.dump(load('error"x"', "=base"))
    // to "ab\0cd\nef" and running it: the error reads `[string "ab:1: x`.
    assertSame('ab', ChunkId::of("=ab\0cd"));
    assertSame('ab', ChunkId::of("@ab\0cd"));
    assertSame('[string "ab', ChunkId::of("ab\0cd\nef"));
}
