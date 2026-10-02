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
    'flush_on_exit' => <<<'LUA'
        io.write("stdout")
        local f = assert(io.open("/dev/stdout", "a"))
        f:write("file")
        os.exit(0, false)
        LUA,
    'flush_on_popen' => <<<'LUA'
        io.write("stdout")
        local f = assert(io.open("/dev/stdout", "a"))
        f:write("file")
        assert(io.popen("true")):close()
        LUA,
    'flush_at_the_end' => <<<'LUA'
        io.write("stdout")
        local f = assert(io.open("/dev/stdout", "a"))
        f:write("file")
        LUA,
    'flush_on_exit_closing_the_state' => <<<'LUA'
        io.write("stdout")
        local f = assert(io.open("/dev/stdout", "a"))
        f:write("file")
        os.exit(0, true)
        LUA,
    'flush_newest_first' => <<<'LUA'
        local first = assert(io.open("/dev/stdout", "a"))
        io.write("stdout")
        first:write("first")
        local second = assert(io.open("/dev/stdout", "a"))
        second:write("second")
        local closed = assert(io.open("/dev/stdout", "a"))
        closed:write("closed")
        closed:close()
        os.exit(0, false)
        LUA,
    'seek_on_a_shared_file' => <<<'LUA'
        io.write("abcdef")
        io.stderr:write(tostring(io.stdout:seek("set", 0)), "\n")
        io.write("X")
        io.stderr:write(tostring(io.stdout:seek()), "\n")
        LUA,
    'seek_after_other_writers' => <<<'LUA'
        io.write("abc")
        os.execute("printf XY")
        io.stderr:write("[", tostring(io.stdout:seek("cur")), "]")
        io.write("d")
        os.execute("printf Z")
        io.stderr:write("[", tostring(io.stdout:seek("cur")), "]")
        io.write("e")
        io.stderr:write("[", tostring(io.stdout:seek("end", -1)), "]")
        io.write("f")
        io.stderr:write("[", tostring(io.stdout:seek("cur", -2)), "]")
        LUA,
    'debug_debug' => <<<'LUA'
        io.write("before")
        debug.debug()
        io.write("after")
        LUA,
    'buffered_at_the_end' => <<<'LUA'
        io.write("written when the program ends")
        LUA,
    'endless_print' => <<<'LUA'
        while true do print("y") end
        LUA,
    'endless_write' => <<<'LUA'
        while true do io.write("y\n") end
        LUA,
    'endless_stderr' => <<<'LUA'
        while true do io.stderr:write("e\n") end
        LUA,
    'endless_warnings' => <<<'LUA'
        warn("@on")
        while true do warn("w") end
        LUA,
    'pipe_reader_gone' => <<<'LUA'
        local pipe = io.popen("exit 0", "w")
        io.write("left in the buffer")
        local closing <close> = setmetatable({}, {__close = function () io.stderr:write("closed\n") end})
        local finalized = setmetatable({}, {__gc = function () io.stderr:write("collected\n") end})
        while true do pipe:write(string.rep("x", 4096)) pipe:flush() end
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
    $names = [
        'full_buffering', 'line_buffering', 'no_buffering', 'os_exit', 'os_exit_closing_the_state', 'error_at_the_end',
    ];
    foreach ($names as $name) {
        assertSameAsReference($name, '> /dev/full');
    }
}

/**
 * The commands that run case $name as from a shell, whose SIGPIPE has its
 * default disposition (the caller starts them under env
 * --default-signal=PIPE: this process ignores SIGPIPE, as PHP does, and its
 * children inherit that): lua5.4, bin/lua and the transpiled script, the
 * last two also on hosts without pcntl, where they emulate the signal (a
 * shared host's, which cannot start processes, unless $startsProcesses)
 *
 * @return array<string, list<string>>
 */
function programsAsFromAShell(string $name, bool $startsProcesses = false): array
{
    $hosts = ['without pcntl' => \Tests\DisabledFunctionsTest\disabling(get_extension_funcs('pcntl'))];
    if (!$startsProcesses) {
        $hosts['on a shared host'] = \Tests\DisabledFunctionsTest\disabling(
            \Tests\DisabledFunctionsTest\sharedHostDisabledFunctions()
        );
    }
    $programs = [];
    foreach (programsFor($name) as $program => $command) {
        $programs[$program] = $command;
        if ($command[0] === 'lua5.4') {
            continue;
        }
        foreach ($hosts as $host => $phpOptions) {
            $programs["$program $host"] = ['php', ...$phpOptions, ...\array_slice($command, 1)];
        }
    }
    return $programs;
}

/**
 * Runs each of programsAsFromAShell($name) under bash running $shellLine
 * ("$@" is the program, ${PIPESTATUS[0]} its status: 141 is 128 + SIGPIPE,
 * killed by the signal) as from a shell (SIGPIPE at its default), bounded
 * by a timeout (137: it never ended). Every result must be lua5.4's, which
 * it returns: [exit status, stdout, stderr].
 *
 * @return array{int, string, string}
 */
function assertSameAsFromAShell(string $name, string $shellLine, bool $startsProcesses = false): array
{
    $expected = null;
    foreach (programsAsFromAShell($name, $startsProcesses) as $program => $command) {  // (lua5.4 first)
        $asFromAShell = ['env', '--default-signal=PIPE', 'bash', '-c', $shellLine, 'bash'];
        $result = runCommand([...$asFromAShell, 'timeout', '-s', 'KILL', '20', ...$command]);
        $result = [$result[0], withProgramName($result[1], $command), withProgramName($result[2], $command)];
        $expected ??= $result;
        assertSame($expected, $result, "$name, $program");
    }
    return $expected;
}

function test_a_program_writing_to_a_closed_pipe_dies_of_sigpipe(): void
{
    // the pipe's reader is gone before the program writes anything
    foreach (['full_buffering', 'no_buffering', 'os_exit', 'buffered_at_the_end'] as $name) {
        $results = [];
        foreach (programsAsFromAShell($name) as $program => $command) {
            $process = proc_open(
                ['env', '--default-signal=PIPE', 'sh', '-c', '"$@"; echo "exit status $?" >&2', 'sh', ...$command],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            fclose($pipes[1]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $results[$program] = [proc_close($process), withProgramName($errorOutput, $command)];
        }
        assertTrue(str_ends_with($results['lua5.4'][1], "exit status 141\n"), "$name: SIGPIPE killed lua5.4");
        foreach ($results as $program => $result) {
            assertSame($results['lua5.4'], $result, "$name on a closed pipe: $program");
        }
    }
}

function test_a_program_writing_into_head_ends_with_status_141(): void
{
    $stdoutIntoHead = '"$@" | head -1; echo "status ${PIPESTATUS[0]}"';
    $stderrIntoHead = '"$@" 2>&1 >/dev/null | head -1; echo "status ${PIPESTATUS[0]}"';
    assertSame([0, "y\nstatus 141\n", ''], assertSameAsFromAShell('endless_print', $stdoutIntoHead));
    assertSame([0, "y\nstatus 141\n", ''], assertSameAsFromAShell('endless_write', $stdoutIntoHead));
    assertSame([0, "e\nstatus 141\n", ''], assertSameAsFromAShell('endless_stderr', $stderrIntoHead));
    assertSame(
        [0, "Lua warning: w\nstatus 141\n", ''],
        assertSameAsFromAShell('endless_warnings', $stderrIntoHead),
    );
}

function test_sigpipe_ends_the_program_at_once(): void
{
    // a pipe to a command that has ended: nothing more is written (the
    // buffered stdout is lost), no __close and no __gc runs
    assertSame([0, "status 141\n", ''], assertSameAsFromAShell('pipe_reader_gone', '"$@"; echo "status $?"', true));
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

/**
 * What lua5.4, bin/lua and the transpiled script leave in a regular file
 * that is their stdout, or stdout and stderr ($shellRedirection names it
 * "$0"): [exit status, the file, stdout, stderr]; all must be lua5.4's
 */
function assertSameInARegularFile(string $name, string $shellRedirection): void
{
    $results = [];
    foreach (programsFor($name) as $program => $command) {
        $file = scratchDirectory() . "/standard-output/$name-" . str_replace('/', '-', $program) . '.out';
        file_put_contents($file, '');
        $result = runCommand(['sh', '-c', 'exec "$@" ' . $shellRedirection, $file, ...$command]);
        $results[$program] = [
            $result[0],
            withProgramName((string) file_get_contents($file), $command),
            $result[1],
            withProgramName($result[2], $command),
        ];
    }
    assertSame($results['lua5.4'], $results['bin/lua'], "$name in a regular file: bin/lua");
    assertSame($results['lua5.4'], $results['lua2php'], "$name in a regular file: lua2php output");
}

function test_flushing_every_stream_takes_the_newest_first(): void
{
    // glibc's _IO_flush_all (exit, os.exit, io.popen's fflush(NULL)) walks its list of
    // streams: the newest first, stdout (there from the start) last. On a pipe or a
    // terminal "file" comes first; with stdout a regular file, stdout writes at offset 0
    // after the appended "file" and wins. At the end lua_close closes the file first.
    $names = [
        'flush_on_exit', 'flush_on_popen', 'flush_at_the_end', 'flush_on_exit_closing_the_state', 'flush_newest_first',
    ];
    foreach ($names as $name) {
        assertSameAsReference($name, '');
        assertSameInARegularFile($name, '> "$0"');
        $shown = shownOnATerminal("{$name}_on_a_terminal", REPORT . CASES[$name]);
        assertSame($shown['lua5.4'], $shown['bin/lua'], "$name on a terminal: bin/lua");
        assertSame($shown['lua5.4'], $shown['lua2php'], "$name on a terminal: lua2php output");
    }
    $results = runCommand(['lua5.4', scratchDirectory() . '/standard-output/flush_on_exit.lua']);
    assertSame([0, 'filestdout', ''], $results, 'lua5.4 on a pipe');
}

function test_reads_of_a_terminal_flush_a_prompt_as_glibc_does(): void
{
    // glibc flushes a line buffered stdout when a terminal's stdin underflows: a
    // count smaller than the buffer (1024 bytes) does, one at least as big reads
    // directly and does not, "a" does from its second piece on
    $reads = [
        'count' => 'io.read(1)', 'big_count' => 'io.read(5000)', 'counts' => 'io.read(1, 1)', 'zero' => 'io.read(0)',
        'all' => 'io.read("a")', 'line' => 'io.read("L")',
        'after_a_line' => 'io.read("l") io.write("again?") io.read(1)',
        'lines' => 'for line in io.lines() do break end',
    ];
    $lua54 = [];
    foreach ($reads as $name => $read) {
        // (script(1) waits 2 seconds for a child that leaves typed lines unread)
        $input = $name === 'after_a_line' ? "42\nb\n" : "42\n";
        $shown = shownOnATerminal("read_$name", "io.write(\"prompt?\") $read io.stderr:write(\"READ\\n\")\n", $input);
        assertSame($shown['lua5.4'], $shown['bin/lua'], "$read: bin/lua");
        assertSame($shown['lua5.4'], $shown['lua2php'], "$read: lua2php output");
        $lua54[$name] = $shown['lua5.4'][1];
    }
    assertSame(['count' => "prompt?READ\r\n", 'big_count' => "READ\r\nprompt?"], [
        'count' => $lua54['count'],
        'big_count' => $lua54['big_count'],
    ], 'lua5.4');
}

function test_debug_debug_writes_its_prompt_to_stderr_without_flushing_stdout(): void
{
    $input = "print(42)\nerror('e')\ncont\n";
    $results = [];
    foreach (programsFor('debug_debug') as $program => $command) {
        $result = runCommand(['sh', '-c', 'exec "$@" 2>&1', 'sh', ...$command], $input);
        $results[$program] = [$result[0], withProgramName($result[1], $command), $result[2]];
    }
    assertSame($results['lua5.4'], $results['bin/lua'], 'debug.debug: bin/lua');
    assertSame($results['lua5.4'], $results['lua2php'], 'debug.debug: lua2php output');
    $shown = shownOnATerminal('debug_debug_on_a_terminal', CASES['debug_debug'], $input);
    assertSame($shown['lua5.4'], $shown['bin/lua'], 'debug.debug on a terminal: bin/lua');
    assertSame($shown['lua5.4'], $shown['lua2php'], 'debug.debug on a terminal: lua2php output');
}

function test_stdout_keeps_its_own_position_in_a_shared_file(): void
{
    // glibc keeps the stream's offset once a seek told it, and asks the descriptor
    // only while it does not know it; other writers (stderr on the same file,
    // child processes) move the descriptor's offset, not the stream's
    assertSameInARegularFile('seek_on_a_shared_file', '> "$0" 2>&1');
    assertSameInARegularFile('seek_after_other_writers', '> "$0"');
}

function test_standard_descriptors_open_on_pipes(): void
{
    // tests/diff runs this case with stdout and stderr as files; here they are pipes,
    // which have no path PHP could open: Linux opens /proc/self/fd/N itself
    $script = realpath(REPO_ROOT . '/tests/diff/io_open_standard_descriptors.lua');
    $normalize = static fn (string $text): string => preg_replace('/0x[0-9a-f]+/', '0x?', $text);
    $reference = runCommand(['lua5.4', $script]);
    $ours = runCommand(['php', realpath(REPO_ROOT . '/bin/lua'), $script]);
    assertSame(
        [$reference[0], $normalize($reference[1]), $reference[2]],
        [$ours[0], $normalize($ours[1]), $ours[2]],
    );
    assertTrue(str_contains($reference[1], "[written to /dev/stdout]\n"), 'lua5.4 opened /dev/stdout');
}
