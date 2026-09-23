<?php

declare(strict_types=1);

namespace Tests\LuaCliTest;

/*
 * bin/lua against the reference lua5.4: same arguments, same working
 * directory, same stdin; exit status, stdout and stderr must match once
 * 0x... addresses and the program name are normalized.
 */

function workingDirectory(): string
{
    $directory = scratchDirectory() . '/lua-cli';
    if (!is_dir($directory)) {
        mkdir($directory);
        file_put_contents($directory . '/args.lua', "#!/usr/bin/env lua\nprint(select('#', ...), ...)\nfor i = -1, #arg do print(i, arg[i]) end\n");
        file_put_contents($directory . '/fails.lua', "local t = nil\nprint('partial output')\nreturn t.x\n");
        file_put_contents($directory . '/syntax.lua', "x = = 1\n");
        file_put_contents($directory . '/returns.lua', "return 1, 2, 3\n");
        file_put_contents($directory . '/bom.lua', "\xEF\xBB\xBFprint('bom ok')\n");
        file_put_contents($directory . '/errorobject.lua', "error(setmetatable({}, {__tostring = function () return 'as string' end}))\n");
        file_put_contents($directory . '/errornil.lua', "error()\n");
    }
    return $directory;
}

/**
 * @param list<string> $arguments
 * @param array<string, string> $environment
 */
function assertSameAsReference(array $arguments, string $standardInput = '', array $environment = []): void
{
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $prefix = [];
    foreach ($environment as $name => $value) {
        $prefix[] = "$name=$value";
    }
    $envCommand = $prefix === [] ? [] : ['env', ...$prefix];
    $ours = runCommand([...$envCommand, 'php', $ourProgram, ...$arguments], $standardInput, workingDirectory());
    $reference = runCommand([...$envCommand, 'lua5.4', ...$arguments], $standardInput, workingDirectory());
    $normalize = static fn (string $text): string => preg_replace('/0x[0-9a-f]+/', '0x?', $text);
    $label = 'lua ' . implode(' ', $arguments);
    assertSame($reference[0], $ours[0], "$label: exit status");
    // the interpreter's name shows up in 'arg' (index -1, or 0 without a script)
    assertSame(
        $normalize(str_replace('lua5.4', 'lua', $reference[1])),
        $normalize(str_replace($ourProgram, 'lua', $ours[1])),
        "$label: stdout",
    );
    assertSame(
        $normalize(str_replace('lua5.4', 'lua', $reference[2])),
        $normalize(str_replace($ourProgram, 'lua', $ours[2])),
        "$label: stderr",
    );
}

function test_script_arguments_and_arg_table(): void
{
    assertSameAsReference(['args.lua']);
    assertSameAsReference(['args.lua', 'a', 'b c', '']);
    assertSameAsReference(['-e', 'x = 1', 'args.lua', '1', '2']);
    assertSameAsReference(['--', 'args.lua', '-v']);
}

function test_execute_strings_and_stdin(): void
{
    assertSameAsReference(['-e', 'print(1 + 1)']);
    assertSameAsReference(['-eprint(2 + 2)', '-e', 'print(3)']);
    assertSameAsReference(['-'], "print('from stdin', ...)\n");
    assertSameAsReference(['-', 'x', 'y'], "print('from stdin', ...)\n");
    assertSameAsReference([], "print('stdin without arguments')\n");
    assertSameAsReference(['-e', 'print(arg[0], arg[1], arg[-1] ~= nil)']);
    assertSameAsReference(['-W', '-e', 'warn("on by option")']);
    assertSameAsReference(['-e', 'warn("off by default")']);
    assertSameAsReference(['bom.lua']);
    assertSameAsReference(['returns.lua']);
}

function test_errors_and_usage(): void
{
    assertSameAsReference(['fails.lua']);
    assertSameAsReference(['syntax.lua']);
    assertSameAsReference(['missing.lua']);
    assertSameAsReference(['errorobject.lua']);
    assertSameAsReference(['errornil.lua']);
    assertSameAsReference(['-e', 'error("in -e")']);
    assertSameAsReference(['-e', 'x = = 1']);
    assertSameAsReference(['-e']);
    assertSameAsReference(['-x']);
    assertSameAsReference(['-vx']);
    assertSameAsReference(['--x']);
    assertSameAsReference(['-e', 'print(1)', '-l']);
}

function test_version_and_environment(): void
{
    assertSameAsReference(['-v']);
    assertSameAsReference(['-v', '-e', 'print(1)']);
    assertSameAsReference(['-e', 'print(1)'], '', ['LUA_INIT' => 'print("init ran")']);
    assertSameAsReference(['-e', 'print(1)'], '', ['LUA_INIT_5_4' => 'print("5.4 init ran")', 'LUA_INIT' => 'print("not this one")']);
    assertSameAsReference(['-E', '-e', 'print(1)'], '', ['LUA_INIT' => 'print("ignored")']);
    assertSameAsReference(['-e', 'print(1)'], '', ['LUA_INIT' => 'error("init failed")']);
    assertSameAsReference(['-e', 'print(1)'], '', ['LUA_INIT' => '@args.lua']);
}
