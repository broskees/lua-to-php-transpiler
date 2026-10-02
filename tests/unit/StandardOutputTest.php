<?php

declare(strict_types=1);

namespace Tests\StandardOutputTest;

/*
 * C's stdout as glibc buffers it: print and io.write share one stdout
 * buffer (fully buffered on a file or pipe, line by line on a terminal,
 * flushed before a terminal's stdin reads), write errors come back as
 * Lua's results where C reports them (liolib.c: g_write, f_flush,
 * f_setvbuf, g_read, luaL_fileresult) and print ignores them
 * (lua_writestring), and the exit status stays the script's. lua5.4,
 * bin/lua and the script transpiled by bin/lua2php run each case; their
 * exit status and output must match (standard output is /dev/full, a
 * closed pipe or a terminal here, which diff cases cannot set up).
 */

// what every case starts with: report(label, ...) writes the values to stderr
const REPORT = <<<'LUA'
    local function report(label, ...)
      local values = table.pack(...)
      for i = 1, values.n do
        values[i] = values[i] == io.stdout and "io.stdout" or tostring(values[i])
      end
      io.stderr:write(label, ": ", table.concat(values, " ", 1, values.n), "\n")
    end

    LUA;

const CASES = [
    'full_buffering' => <<<'LUA'
        print("print ignores errors")
        report("after print", true)
        report("io.write", io.write("x"))
        report("io.stdout:flush", io.stdout:flush())
        report("io.flush", io.flush())
        report("io.stdout:write", io.stdout:write("y", 1, 2.5))
        report("io.stdout:close", io.stdout:close())
        report("io.close", io.close())
        report("io.stdout:read", io.stdout:read())
        report("popen flushes everything", io.popen("true"):close())
        report("io.write after", io.write("z"))
        for i = 1, 20000 do
          local ok, message, code = io.write("z")
          if not ok then report("io.write fails at " .. i, ok, message, code) break end
        end
        io.write("left in the buffer at exit")
        LUA,
    'line_buffering' => <<<'LUA'
        report("setvbuf line", io.stdout:setvbuf("line"))
        report("no newline", io.write("abc"))
        report("newline", io.write("abc\n"))
        report("numbers", io.write(12, 3.5))
        report("setvbuf full", io.stdout:setvbuf("full"))
        report("bigger than the buffer", io.write(string.rep("y", 10000)))
        report("io.flush", io.flush())
        LUA,
    'no_buffering' => <<<'LUA'
        io.write("pending")
        report("setvbuf no with output pending", io.stdout:setvbuf("no"))
        print("a", "b")
        report("unbuffered", io.write("z"))
        report("unbuffered file:write", io.stdout:write("q", 1))
        report("nothing to write", io.write(""))
        report("io.flush", io.flush())
        report("setvbuf line", io.stdout:setvbuf("line"))
        report("line buffered", io.write("x\n"))
        LUA,
    'os_exit' => <<<'LUA'
        io.write("pending")
        report("exiting", true)
        os.exit(3)
        LUA,
    'os_exit_closing_the_state' => <<<'LUA'
        io.write("pending")
        local closing <close> = setmetatable({}, {__close = function () report("closed", io.write("more")) end})
        os.exit(true, true)
        LUA,
    'error_at_the_end' => <<<'LUA'
        io.write("pending")
        error("fails")
        LUA,
];

/**
 * The case $name as a Lua script in the scratch directory and as bin/lua2php
 * transpiled it; returns the commands that run it: lua5.4, bin/lua and the
 * transpiled script.
 *
 * @return array<string, list<string>>
 */
function programsFor(string $name): array
{
    $directory = scratchDirectory() . '/standard-output';
    @mkdir($directory);
    $script = "$directory/$name.lua";
    if (!is_file($script)) {
        file_put_contents($script, REPORT . CASES[$name]);
        [$status, , $errorOutput] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $script]);
        assertSame(0, $status, "lua2php $name.lua: $errorOutput");
    }
    return [
        'lua5.4' => ['lua5.4', $script],
        'bin/lua' => ['php', realpath(REPO_ROOT . '/bin/lua'), $script],
        'lua2php' => ['php', ...OPCACHE_ON_NEW_FILES, "$directory/$name.php"],
    ];
}

/**
 * $text with the program name (lua.c's progname, argv[0]) at the start of
 * its lines as "lua"
 *
 * @param list<string> $command
 */
function withProgramName(string $text, array $command): string
{
    $programName = match (true) {
        $command[0] === 'lua5.4' => 'lua5.4',
        $command[1] === realpath(REPO_ROOT . '/bin/lua') => $command[1],
        default => end($command),  // a transpiled script
    };
    return preg_replace('/^' . preg_quote($programName, '/') . ':/m', 'lua:', $text);
}

/**
 * Runs case $name under each program with $shellRedirection applied to it
 * (sh syntax); exit status, standard output and standard error must be
 * lua5.4's.
 */
function assertSameAsReference(string $name, string $shellRedirection): void
{
    $results = [];
    foreach (programsFor($name) as $program => $command) {
        $result = runCommand(['sh', '-c', 'exec "$@" ' . $shellRedirection, 'sh', ...$command]);
        $result[1] = withProgramName($result[1], $command);
        $result[2] = withProgramName($result[2], $command);
        $results[$program] = $result;
    }
    assertSame($results['lua5.4'], $results['bin/lua'], "$name with $shellRedirection: bin/lua");
    assertSame($results['lua5.4'], $results['lua2php'], "$name with $shellRedirection: lua2php output");
}

function test_write_errors_on_a_full_device(): void
{
    foreach (array_keys(CASES) as $name) {
        assertSameAsReference($name, '> /dev/full');
    }
}

function test_write_errors_on_a_pipe_nobody_reads(): void
{
    // SIGPIPE is ignored (PHP ignores it, and lua5.4 inherits that from this
    // process): writing to the pipe fails with EPIPE
    foreach (['full_buffering', 'no_buffering', 'os_exit'] as $name) {
        $results = [];
        foreach (programsFor($name) as $program => $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[1]);  // before the program writes anything
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $results[$program] = [proc_close($process), withProgramName($errorOutput, $command)];
        }
        assertSame($results['lua5.4'], $results['bin/lua'], "$name on a closed pipe: bin/lua");
        assertSame($results['lua5.4'], $results['lua2php'], "$name on a closed pipe: lua2php output");
    }
}

function test_standard_output_and_error_to_one_file_keep_their_order(): void
{
    // lua.c's messages and io.stderr do not flush stdout; print does
    foreach (['error_at_the_end', 'os_exit', 'line_buffering'] as $name) {
        assertSameAsReference($name, '2>&1');
    }
}

/**
 * What a terminal shows when lua5.4, bin/lua and the transpiled script run
 * $script on it (script(1) runs a program on a pseudo-terminal, copies
 * $input to it and what it shows to its own output); the terminal's echo
 * of $input, which may come before or after the program starts, is left
 * out. Returns [exit status, what was shown, standard error] by program.
 *
 * @return array<string, array{int, string, string}>
 */
function shownOnATerminal(string $name, string $script, string $input = ''): array
{
    $directory = scratchDirectory() . '/standard-output';
    @mkdir($directory);
    file_put_contents("$directory/$name.lua", $script);
    runCommand(['php', REPO_ROOT . '/bin/lua2php', "$directory/$name.lua"]);
    $programs = [
        'lua5.4' => "lua5.4 $name.lua",
        'bin/lua' => 'php ' . escapeshellarg(realpath(REPO_ROOT . '/bin/lua')) . " $name.lua",
        'lua2php' => 'php ' . implode(' ', OPCACHE_ON_NEW_FILES) . " $name.php",
    ];
    $shown = [];
    foreach ($programs as $program => $commandLine) {
        $result = runCommand(['env', 'SHELL=/bin/sh', 'script', '-qec', $commandLine, '/dev/null'], $input, $directory);
        foreach (explode("\n", rtrim($input, "\n")) as $line) {
            $echo = "$line\r\n";
            $position = $line === '' ? false : strpos($result[1], $echo);
            if ($position !== false) {
                $result[1] = substr_replace($result[1], '', $position, \strlen($echo));
            }
        }
        $shown[$program] = $result;
    }
    return $shown;
}

function test_standard_output_is_line_buffered_on_a_terminal(): void
{
    $shown = shownOnATerminal('terminal', <<<'LUA'
        io.write("one\n") io.stderr:write("two\n") io.write("three") io.stderr:write("four\n")
        io.write("five\n") io.stdout:setvbuf("full") io.write("six\n") io.stderr:write("seven\n")
        LUA);
    assertSame([0, "one\r\ntwo\r\nfour\r\nthreefive\r\nseven\r\nsix\r\n", ''], $shown['lua5.4'], 'lua5.4');
    assertSame($shown['lua5.4'], $shown['bin/lua'], 'bin/lua');
    assertSame($shown['lua5.4'], $shown['lua2php'], 'lua2php output');
}

function test_a_prompt_shows_before_the_program_reads_a_terminal(): void
{
    // glibc flushes a line buffered stdout before a terminal's stdin reads
    $shown = shownOnATerminal('prompt', <<<'LUA'
        io.write("name? ") local line = io.read() io.stderr:write("[read ", tostring(line), "]\n")
        io.write("number? ") line = io.read("n") io.stderr:write("[read ", tostring(line), "]\n")
        LUA, "joe\n42\n");
    assertSame([0, "name? [read joe]\r\nnumber? [read 42]\r\n", ''], $shown['lua5.4'], 'lua5.4');
    assertSame($shown['lua5.4'], $shown['bin/lua'], 'bin/lua');
    assertSame($shown['lua5.4'], $shown['lua2php'], 'lua2php output');
}
