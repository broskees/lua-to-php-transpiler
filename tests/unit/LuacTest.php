<?php

declare(strict_types=1);

namespace Tests\LuacTest;

/*
 * bin/luac against the reference luac5.4: same arguments, same working
 * directory; exit status, stdout and stderr must match once 0x... addresses
 * and the program name are normalized.
 */

function workingDirectory(): string
{
    $directory = scratchDirectory() . '/luac';
    if (!is_dir($directory)) {
        mkdir($directory);
        file_put_contents($directory . '/sample.lua', "#!/usr/bin/env lua\nlocal t = {1, 2.5, 'x\\0y'}\nfor i, v in ipairs(t) do\n  print(i, v)\nend\nreturn function(...) return ... end\n");
        file_put_contents($directory . '/broken.lua', "local x = \n\n  = 3\n");
        file_put_contents($directory . '/bom.lua', "\xEF\xBB\xBFreturn 42\n");
    }
    return $directory;
}

/**
 * @param list<string> $arguments
 * @return array{array{int, string, string}, array{int, string, string}} [bin/luac result, luac5.4 result]
 */
function runBoth(array $arguments, string $standardInput = ''): array
{
    $ourProgram = realpath(REPO_ROOT . '/bin/luac');
    $ours = runCommand(['php', $ourProgram, ...$arguments], $standardInput, workingDirectory());
    $reference = runCommand(['luac5.4', ...$arguments], $standardInput, workingDirectory());
    $normalize = fn (string $text) => preg_replace('/0x[0-9a-f]+/', '0x?', $text);
    $ours[1] = $normalize($ours[1]);
    $ours[2] = $normalize(str_replace($ourProgram, 'luac5.4', $ours[2]));
    $reference[1] = $normalize($reference[1]);
    $reference[2] = $normalize($reference[2]);
    return [$ours, $reference];
}

/** @param list<string> $arguments */
function assertSameAsReference(array $arguments, string $standardInput = ''): void
{
    [$ours, $reference] = runBoth($arguments, $standardInput);
    $label = 'luac ' . implode(' ', $arguments);
    assertSame($reference[0], $ours[0], "$label: exit status");
    assertSame($reference[2], $ours[2], "$label: stderr");
    assertTrue($reference[1] === $ours[1], "$label: stdout differs");
}

function test_output_files_are_byte_identical(): void
{
    $directory = workingDirectory();
    foreach ([[], ['-s']] as $extraOptions) {
        runCommand(['luac5.4', ...$extraOptions, '-o', 'reference.out', 'sample.lua'], '', $directory);
        [$exitCode, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/luac', ...$extraOptions, '-o', 'ours.out', 'sample.lua'], '', $directory);
        assertSame(0, $exitCode, $errorOutput);
        assertTrue(
            file_get_contents("$directory/reference.out") === file_get_contents("$directory/ours.out"),
            'luac ' . implode(' ', $extraOptions) . ' -o: output files differ',
        );
    }
    // default output name
    runCommand(['php', REPO_ROOT . '/bin/luac', 'sample.lua'], '', $directory);
    runCommand(['luac5.4', '-o', 'reference.out', 'sample.lua'], '', $directory);
    assertTrue(file_get_contents("$directory/luac.out") === file_get_contents("$directory/reference.out"), 'luac.out differs');
}

function test_listings_match(): void
{
    assertSameAsReference(['-l', '-p', 'sample.lua']);
    assertSameAsReference(['-l', '-l', '-p', 'sample.lua']);
    assertSameAsReference(['-l', '-l', '-p', 'bom.lua']);
    assertSameAsReference(['-l', '-o', '-', 'sample.lua']);  // listing then chunk on stdout
    assertSameAsReference(['-l', '-l', '-p', '-'], "local a = 1\nreturn a + 0.5\n");  // stdin
}

function test_binary_input_is_listed_and_redumped(): void
{
    $directory = workingDirectory();
    runCommand(['luac5.4', '-o', 'full.luac', 'sample.lua'], '', $directory);
    runCommand(['luac5.4', '-s', '-o', 'stripped.luac', 'sample.lua'], '', $directory);
    assertSameAsReference(['-l', '-l', '-p', 'full.luac']);
    assertSameAsReference(['-l', '-l', '-p', 'stripped.luac']);  // no line info: "[-]"
    assertSameAsReference(['-s', '-o', '-', 'full.luac']);
}

function test_errors_and_usage_match(): void
{
    assertSameAsReference(['-p', 'broken.lua']);
    assertSameAsReference(['-p', 'missing.lua']);
    assertSameAsReference(['-p', '.']);
    assertSameAsReference([]);
    assertSameAsReference(['-x']);
    assertSameAsReference(['-o']);
    assertSameAsReference(['-o', '-l', 'sample.lua']);
    assertSameAsReference(['-v']);
    assertSameAsReference(['-v', '-p', 'sample.lua']);
    assertSameAsReference(['-p', '--', 'sample.lua']);
}

// luac.c parses at C-call depth 1 (load() from a main chunk is depth 2), so
// luac5.4 accepts 196 nested parentheses and rejects 197.
function test_nesting_limit_matches_luac(): void
{
    foreach ([196, 197] as $depth) {
        $source = 'return ' . str_repeat('(', $depth) . '1' . str_repeat(')', $depth) . "\n";
        file_put_contents(workingDirectory() . '/nested.lua', $source);
        [$ours, $reference] = runBoth(['-p', 'nested.lua']);
        assertSame($reference, $ours, "nesting depth $depth");
    }
}
