<?php

declare(strict_types=1);

namespace Tests\DisabledFunctionsTest;

use LuaPhp\Lib\Io\Errno;

/*
 * Shared hosts disable PHP functions in php.ini (disable_functions), often
 * every function of the posix and pcntl extensions; a disabled function is
 * undefined, so calling it would be a PHP fatal error no pcall sees. The io
 * and os libraries use posix_strerror and pcntl's waitpid only when they
 * exist and must behave the same without them: bin/lua and lua2php output
 * are compared with lua5.4, with and without those functions.
 */

/** failing file operations (errno and its strerror text), then commands and pipes */
const IO_AND_OS_SCRIPT = <<<'LUA'
    local function show(...)
      local t = table.pack(...)
      for i = 1, t.n do t[i] = tostring(t[i]) end
      print(table.concat(t, " | ", 1, t.n))
    end

    show(io.open("/no/such/file"))
    show(io.open("/no/such/file", "w"))
    show(io.open("/", "w"))
    show(io.open("/root/x"))
    show(io.open("/"):read())
    show(os.remove("/no/such/file"))
    show(os.remove("/etc/passwd/x"))
    show(os.rename("/no/such/a", "/no/such/b"))
    show(pcall(io.lines, "/no/such/file"))
    local directory = os.tmpname()
    os.remove(directory)
    show(os.execute("mkdir " .. directory))
    io.open(directory .. "/inside", "w"):close()
    local _, message, errno = os.remove(directory)
    show(message:gsub(directory, "DIRECTORY"), errno)
    show(os.remove(directory .. "/inside"), os.remove(directory))

    -- the children below are still running when they are waited for, except the first ones
    show(os.execute())
    show(os.execute("exit 0"))
    show(os.execute("exit 3"))
    show(os.execute("sleep 0.1; exit 3"))
    show(os.execute("sleep 0.1; exit 256"))
    show(os.execute("kill -s TERM $$"))
    show(os.execute("sleep 0.1; kill -s TERM $$"))
    show(io.popen("exit 5"):close())
    show(io.popen("sleep 0.1; exit 5"):close())
    show(io.popen("sleep 0.1"):close())
    show(io.popen("sleep 0.1; kill -s INT $$"):close())
    local p = io.popen("echo line one; sleep 0.1; echo line two")
    show(p:read("a"))
    show(p:close())
    p = io.popen("cat", "w")
    show(p:write("written to cat\n") == p)
    show(p:close())
    for l in io.lines("/no/such/file") do end
    LUA;

/** started with SIGCHLD ignored, children are reaped at once: waiting for them fails (ECHILD) */
const CHILDREN_SCRIPT = <<<'LUA'
    print(os.execute("exit 3"))
    print(os.execute("sleep 0.1; exit 3"))
    print(io.popen("exit 2"):close())
    print(io.popen("sleep 0.1; exit 2"):close())
    LUA;

/** @return list<string> every function of the posix and pcntl extensions */
function posixAndPcntlFunctions(): array
{
    return [...get_extension_funcs('posix'), ...get_extension_funcs('pcntl')];
}

/**
 * Run $script ($name in $directory) under lua5.4, bin/lua and its lua2php
 * output, each PHP run with and without the posix and pcntl functions;
 * exit status, stdout and stderr must match (program names normalized).
 *
 * @param list<string> $prefix command prefix (e.g. env options)
 */
function assertSameAsReference(string $directory, string $name, string $script, array $prefix = []): void
{
    file_put_contents("$directory/$name", $script);
    [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $name], '', $directory);
    assertSame(0, $status, $errorOutput);
    $normalize = static fn (array $result, string $programName): array =>
        [$result[0], $result[1], normalizeDifferentialOutput($result[2], $programName)];
    $expected = $normalize(runCommand([...$prefix, 'lua5.4', $name], '', $directory), 'lua5.4');
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $transpiled = "$directory/" . basename($name, '.lua') . '.php';
    $configurations = [
        'all functions' => [],
        'no posix or pcntl' => ['-d', 'disable_functions=' . implode(',', posixAndPcntlFunctions())],
    ];
    foreach ($configurations as $configuration => $phpOptions) {
        foreach ([[$ourProgram, $name], [$transpiled]] as $arguments) {
            $actual = runCommand([...$prefix, 'php', ...$phpOptions, ...$arguments], '', $directory);
            assertSame($expected, $normalize($actual, $arguments[0]), "$name, " . basename($arguments[0]) . ", $configuration");
        }
    }
}

function test_io_and_os_behave_the_same_without_posix_and_pcntl(): void
{
    $directory = scratchDirectory() . '/disabled-functions';
    @mkdir($directory);
    assertSameAsReference($directory, 'io_and_os.lua', IO_AND_OS_SCRIPT);
    assertSameAsReference($directory, 'children.lua', CHILDREN_SCRIPT, ['env', '--ignore-signal=CHLD']);
}

/** the fallback of strerror() when posix_strerror is disabled is glibc's text */
function test_the_strerror_table_is_glibcs(): void
{
    for ($errno = 1; $errno <= 300; $errno++) {
        assertSame(posix_strerror($errno), Errno::glibcStrerror($errno), "errno $errno");
    }
}
