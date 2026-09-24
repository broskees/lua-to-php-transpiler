<?php

declare(strict_types=1);

namespace Tests\IoLibTest;

/*
 * liolib.c behaviour on the standard files that tests/diff cases cannot
 * show (they run with stdin from /dev/null and stdout into a file):
 * reading piped standard input, and standard files that are pipes.
 */

/**
 * bin/lua and lua5.4 run $script (-e) with $standardInput on a pipe; exit
 * status, stdout and stderr must match (program name and 0x... addresses
 * normalized).
 */
function assertSameAsReference(string $script, string $standardInput): void
{
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $ours = runCommand(['php', $ourProgram, '-e', $script], $standardInput);
    $reference = runCommand(['lua5.4', '-e', $script], $standardInput);
    $normalize = static fn (string $text, string $programName): string =>
        preg_replace('/0x[0-9a-f]+/', '0x?', str_replace($programName . ':', 'lua:', $text));
    assertSame($reference[0], $ours[0], "$script: exit status");
    assertSame($normalize($reference[1], 'lua5.4'), $normalize($ours[1], $ourProgram), "$script: stdout");
    assertSame($normalize($reference[2], 'lua5.4'), $normalize($ours[2], $ourProgram), "$script: stderr");
}

function test_reading_standard_input(): void
{
    $input = "first line\nsecond line\n  42 3.5 0x10 nan\nrest\nof the input";
    assertSameAsReference('print(io.read()) print(io.read("L")) print(io.read("n", "n", "n", "n")) print(io.read("a")) print(io.read(), io.read(0), io.read("a"))', $input);
    assertSameAsReference('for l in io.lines() do print("[" .. l .. "]") end print(io.read())', $input);
    assertSameAsReference('for a, b in io.stdin:lines(3, "l") do print(a, b) end', $input);
    assertSameAsReference('print(io.read(5), io.stdin:read(7)) print(io.stdin:seek("set", 0))', $input);
    assertSameAsReference('print(io.read("a")) print(io.read("l"), io.read("a"), io.read(1))', '');
}

function test_standard_output_and_error_on_pipes(): void
{
    assertSameAsReference('io.write("x") print(io.stdout:seek()) print(io.stderr:seek("end"))', '');
    assertSameAsReference('print(io.stdout:setvbuf("no"), io.stdout:write("a", 1, 2.5, "\n"), io.stderr:write("to stderr\n"))', '');
    assertSameAsReference('print(io.stdin:write("x")) print(io.stdout:read()) print(io.stderr:read("a"))', 'input');
    assertSameAsReference('io.output():setvbuf("line") io.write("no newline yet") io.stderr:write("err\n") io.write(" then newline\n")', '');
}
