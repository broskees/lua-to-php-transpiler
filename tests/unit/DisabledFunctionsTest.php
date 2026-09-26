<?php

declare(strict_types=1);

namespace Tests\DisabledFunctionsTest;

use LuaPhp\Lib\Io\Errno;

/*
 * Shared hosts disable PHP functions in php.ini (disable_functions): often
 * ini_set, the process functions (proc_open and its family) and every
 * function of the posix and pcntl extensions. A disabled function is
 * undefined, so calling it would be a PHP fatal error no pcall sees.
 * bin/lua, bin/lua2php and lua2php output must run without them:
 * - without posix and pcntl (fallbacks), and without ini_set (PHP's own
 *   settings stay), programs behave as under lua5.4;
 * - without the process functions, os.execute and io.popen fail as C's
 *   system() and popen() would with ENOSYS ("Function not implemented"),
 *   and os.execute() reports no shell (false). The reference for that is
 *   lua5.4 with PROCESSES_FAIL_INIT, whose results the explicit
 *   PROCESSES_SCRIPT pins; the whole differential corpus runs so under
 *   the typical shared-host list (the lua2php half is in Lua2PhpTest).
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

/**
 * what the settings the runtime applies with ini_set touch: fibers
 * (fiber.stack_size), floats in code load() emits (serialize_precision),
 * errors raised deep in the stack (zend.exception_ignore_args), and in
 * lua2php output the JIT switch
 */
const SETTINGS_SCRIPT = <<<'LUA'
    local co = coroutine.wrap(function (a) local b = coroutine.yield(a + 1) return b * 2 end)
    print(co(1), co(21))
    print(coroutine.resume(coroutine.create(function () error("in a coroutine") end)))
    print(load("return 0.1, 1/3, 2^53 + 1.0, -0.0, 1e308 * 10, 0.30000000000000004, 123456789012345.67, 5e-324")())
    print(string.format("%.17g %a %.3f", 1/3, 0.1, 2/3), 1/3, 100 / 7, 1e15, 2^63)
    local depth = 0
    local function recurse(n) depth = depth + 1; return 1 + recurse(n + 1) end
    print(pcall(recurse, 1))
    print(depth > 1000)
    local t = setmetatable({}, {__index = function (_, k) error("no field " .. k) end})
    print(pcall(function () return t.x end))
    print(select(2, xpcall(error, debug.traceback, "with traceback")))
    error("uncaught")
    LUA;

/**
 * LUA_INIT for lua5.4: its C library cannot start processes, as ours
 * cannot when the host disabled them. system() and popen() fail with
 * ENOSYS: os.execute(command) returns fail, "Function not implemented", 38
 * (lauxlib.c: luaL_execresult's errno path), io.popen(command, mode)
 * returns fail, "<command>: Function not implemented", 38 (luaL_fileresult
 * with the command as file name); system(NULL) reports no shell, so
 * os.execute() returns false. Arguments are checked first, by the real
 * functions (they raise before running anything); the cases call them
 * with bad arguments through pcall, so they name themselves 'io.popen' as
 * the real ones do.
 */
const PROCESSES_FAIL_INIT = <<<'LUA'
    local execute, popen, pcall, error, type, tostring = os.execute, io.popen, pcall, error, type, tostring
    local function cString(value) return (tostring(value):match("^[^\0]*")) end
    local function isString(value) return type(value) == "string" or type(value) == "number" end
    local function raiseArgumentError(library, name, stub, real, ...)
      library[name] = real
      local _, message = pcall(real, ...)
      library[name] = stub
      error(message, 0)
    end
    local executeStub, popenStub
    function executeStub(...)
      local command = ...
      if command == nil then return false end
      if not isString(command) then raiseArgumentError(os, "execute", executeStub, execute, ...) end
      return nil, "Function not implemented", 38
    end
    function popenStub(...)
      local command, mode = ...
      if mode == nil then mode = "r" end
      if not isString(command) or not isString(mode) or (cString(mode) ~= "r" and cString(mode) ~= "w") then
        raiseArgumentError(io, "popen", popenStub, popen, ...)
      end
      return nil, cString(command) .. ": Function not implemented", 38
    end
    os.execute, io.popen = executeStub, popenStub
    LUA;

/** os.execute and io.popen when processes cannot be started, with arguments checked first */
const PROCESSES_SCRIPT = <<<'LUA'
    local function show(...)
      local t = table.pack(...)
      for i = 1, t.n do t[i] = tostring(t[i]) end
      print(table.concat(t, " | ", 1, t.n))
    end

    show(os.execute())
    show(os.execute(nil))
    show(os.execute("echo not run"))
    show(os.execute(42))
    show(pcall(os.execute, {}))
    show(pcall(os.execute, false))
    io.write("buffered before popen | ")
    show(io.popen("echo not run"))
    show(io.popen("cat", "w"))
    show(io.popen(7.5, "r"))
    show(io.popen("echo\0after zero"))
    show(pcall(io.popen, "cat", "rw"))
    show(pcall(io.popen, "cat", {}))
    show(pcall(io.popen))
    show(pcall(io.popen, {}, "w"))
    local p, message = io.popen("echo line")
    show(pcall(function () return p:read("a") end))
    io.write("left in the buffer at exit\n")
    LUA;

const PROCESSES_EXPECTED_OUTPUT = <<<'TEXT'
    false
    false
    nil | Function not implemented | 38
    nil | Function not implemented | 38
    false | bad argument #1 to 'os.execute' (string expected, got table)
    false | bad argument #1 to 'os.execute' (string expected, got boolean)
    buffered before popen | nil | echo not run: Function not implemented | 38
    nil | cat: Function not implemented | 38
    nil | 7.5: Function not implemented | 38
    nil | echo: Function not implemented | 38
    false | bad argument #2 to 'io.popen' (invalid mode)
    false | bad argument #2 to 'io.popen' (string expected, got table)
    false | bad argument #1 to 'io.popen' (string expected, got no value)
    false | bad argument #1 to 'io.popen' (string expected, got table)
    false | processes.lua:23: attempt to index a nil value (upvalue 'p')
    left in the buffer at exit

    TEXT;

/** @return list<string> every function of the posix and pcntl extensions */
function posixAndPcntlFunctions(): array
{
    return [...get_extension_funcs('posix'), ...get_extension_funcs('pcntl')];
}

/** @return list<string> PHP's functions for running processes, which hosts disable together */
function processFunctions(): array
{
    return ['proc_open', 'proc_close', 'proc_get_status', 'proc_terminate', 'proc_nice'];
}

/** @return list<string> a typical shared host's disable_functions, wildcards expanded */
function sharedHostDisabledFunctions(): array
{
    return [
        'exec', 'passthru', 'shell_exec', 'system', 'popen', ...processFunctions(), ...posixAndPcntlFunctions(),
        'symlink', 'link', 'putenv', 'getmypid', 'dl', 'ini_set', 'set_time_limit',
    ];
}

/** @param list<string> $functions @return list<string> php options disabling $functions */
function disabling(array $functions): array
{
    return ['-d', 'disable_functions=' . implode(',', $functions)];
}

/**
 * php options of a host that disabled ini_set and whose own settings differ
 * from those the runtime would apply (zend.exception_ignore_args,
 * serialize_precision; the JIT available but off, which lua2php output
 * would switch on)
 *
 * @return list<string>
 */
function withoutIniSet(): array
{
    return [
        ...disabling(['ini_set']), '-d', 'zend.exception_ignore_args=0', '-d', 'serialize_precision=5',
        '-d', 'opcache.jit=off', '-d', 'opcache.jit_buffer_size=8M',
    ];
}

/**
 * Write $script to $name in $directory and transpile it with bin/lua2php
 * (run with $phpOptions); returns the generated file.
 *
 * @param list<string> $phpOptions
 */
function writeAndTranspile(string $directory, string $name, string $script, array $phpOptions = []): string
{
    file_put_contents("$directory/$name", $script);
    [$status, , $errorOutput] = runCommand(['php', ...$phpOptions, REPO_ROOT . '/bin/lua2php', $name], '', $directory);
    assertSame(0, $status, "lua2php $name: $errorOutput");
    return "$directory/" . basename($name, '.lua') . '.php';
}

/**
 * Run $script ($name in $directory) under lua5.4, bin/lua and its lua2php
 * output, each PHP run in every one of $configurations (php options);
 * exit status, stdout and stderr must match (program names normalized).
 *
 * @param array<string, list<string>> $configurations
 * @param list<string> $prefix command prefix (e.g. env options)
 */
function assertSameAsReference(string $directory, string $name, string $script, array $configurations, array $prefix = []): void
{
    $transpiled = writeAndTranspile($directory, $name, $script);
    $normalize = static fn (array $result, string $programName): array =>
        [$result[0], $result[1], normalizeDifferentialOutput($result[2], $programName)];
    $expected = $normalize(runCommand([...$prefix, 'lua5.4', $name], '', $directory), 'lua5.4');
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    foreach ($configurations as $configuration => $phpOptions) {
        foreach ([[$ourProgram, $name], [$transpiled]] as $arguments) {
            $actual = runCommand([...$prefix, 'php', ...OPCACHE_ON_NEW_FILES, ...$phpOptions, ...$arguments], '', $directory);
            assertSame($expected, $normalize($actual, $arguments[0]), "$name, " . basename($arguments[0]) . ", $configuration");
        }
    }
}

function test_io_and_os_behave_the_same_without_posix_and_pcntl(): void
{
    $directory = scratchDirectory() . '/disabled-functions';
    @mkdir($directory);
    $configurations = ['all functions' => [], 'no posix or pcntl' => disabling(posixAndPcntlFunctions())];
    assertSameAsReference($directory, 'io_and_os.lua', IO_AND_OS_SCRIPT, $configurations);
    assertSameAsReference($directory, 'children.lua', CHILDREN_SCRIPT, $configurations, ['env', '--ignore-signal=CHLD']);
}

function test_everything_runs_the_same_without_ini_set(): void
{
    $directory = scratchDirectory() . '/disabled-ini-set';
    @mkdir($directory);
    $configurations = ['no ini_set' => withoutIniSet()];
    assertSameAsReference($directory, 'settings.lua', SETTINGS_SCRIPT, $configurations);
    assertSameAsReference($directory, 'io_and_os.lua', IO_AND_OS_SCRIPT, $configurations);
}

function test_os_execute_and_io_popen_fail_without_the_process_functions(): void
{
    $directory = scratchDirectory() . '/disabled-processes';
    @mkdir($directory);
    $sharedHost = disabling(sharedHostDisabledFunctions());
    $transpiled = writeAndTranspile($directory, 'processes.lua', PROCESSES_SCRIPT, $sharedHost);
    // the reference model of a C library that cannot start processes gives exactly these results
    $expected = [0, PROCESSES_EXPECTED_OUTPUT, ''];
    assertSame($expected, runCommand(['env', 'LUA_INIT_5_4=' . PROCESSES_FAIL_INIT, 'lua5.4', 'processes.lua'], '', $directory), 'lua5.4 with PROCESSES_FAIL_INIT');
    $configurations = [
        'no proc_open' => disabling(['proc_open']),
        'no proc_get_status' => disabling(['proc_get_status']),
        'no proc_close' => disabling(['proc_close']),
        'no process functions' => disabling(processFunctions()),
        'shared host' => $sharedHost,
    ];
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    foreach ($configurations as $configuration => $phpOptions) {
        foreach ([[$ourProgram, 'processes.lua'], [$transpiled]] as $arguments) {
            $actual = runCommand(['php', ...OPCACHE_ON_NEW_FILES, ...$phpOptions, ...$arguments], '', $directory);
            assertSame($expected, $actual, basename($arguments[0]) . ", $configuration");
        }
    }
}

/**
 * The differential corpus under bin/lua on a typical shared host: every
 * case must behave as under lua5.4 whose C library cannot start processes
 * (PROCESSES_FAIL_INIT); only the cases that start processes differ from
 * plain lua5.4. memory_limit is the host's own setting, not a disabled
 * function: the cases get the 4G bin/lua sets itself where it can.
 */
function test_the_differential_corpus_runs_on_a_shared_host(): void
{
    $casesThatStartProcesses = ['os_library.lua', 'print_flush.lua'];
    $diffDirectory = REPO_ROOT . '/tests/diff';
    $files = glob("$diffDirectory/*.lua");
    sort($files);
    $ourProgram = realpath(REPO_ROOT . '/bin/lua');
    $differingCases = [];
    foreach (array_chunk($files, 8) as $batch) {
        $running = [];
        foreach ($batch as $file) {
            $name = basename($file);
            $running[$name] = [
                startProcess(['lua5.4', $name], $diffDirectory),
                startProcess(['env', 'LUA_INIT_5_4=' . PROCESSES_FAIL_INIT, 'lua5.4', $name], $diffDirectory),
                startProcess(['php', ...disabling(sharedHostDisabledFunctions()), '-d', 'memory_limit=4G', $ourProgram, $name], $diffDirectory),
            ];
        }
        foreach ($running as $name => [$plainProcess, $referenceProcess, $ourProcess]) {
            $normalize = static fn (array $result, string $programName): array =>
                [$result[0], normalizeDifferentialOutput($result[1], 'lua5.4'), normalizeDifferentialOutput($result[2], $programName)];
            $plain = $normalize(finishProcess($plainProcess), 'lua5.4');
            $reference = $normalize(finishProcess($referenceProcess), 'lua5.4');
            $ours = $normalize(finishProcess($ourProcess), $ourProgram);
            if ($plain !== $reference) {
                $differingCases[] = $name;
            }
            assertSame($reference[0], $ours[0], "$name: exit status");
            assertTrue($reference[1] === $ours[1], "$name: stdout differs" . firstDifference($reference[1], $ours[1]));
            assertTrue($reference[2] === $ours[2], "$name: stderr differs" . firstDifference($reference[2], $ours[2]));
        }
    }
    assertSame($casesThatStartProcesses, $differingCases, 'the cases whose results depend on starting processes');
}

/** the fallback of strerror() when posix_strerror is disabled is glibc's text */
function test_the_strerror_table_is_glibcs(): void
{
    for ($errno = 1; $errno <= 300; $errno++) {
        assertSame(posix_strerror($errno), Errno::glibcStrerror($errno), "errno $errno");
    }
}
