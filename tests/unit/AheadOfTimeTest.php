<?php

declare(strict_types=1);

namespace Tests\AheadOfTimeTest;

/*
 * bin/lua2php <dir>: a whole project is transpiled ahead of time, and the
 * generated PHP never compiles or emits code at run time: require, dofile
 * and loadfile load the precompiled PHP where the .lua would be. Runs use
 * tests/aot/guard.php, which exits with status 97 when a transpiler class
 * (Compiler, Lexer, Parser, CodeGen, anything in LuaPhp\Emitter) is loaded.
 */

const PROJECT = REPO_ROOT . '/tests/aot/project';
const GUARD = REPO_ROOT . '/tests/aot/guard.php';

/** @return array{int, string, string} */
function lua2php(array $arguments, ?string $workingDirectory = null): array
{
    return runCommand(['php', REPO_ROOT . '/bin/lua2php', ...$arguments], '', $workingDirectory);
}

/**
 * Run a generated script under the guard, with its load() cache in $cacheDirectory.
 *
 * @return array{int, string, string}
 */
function runGuarded(string $phpFile, string $workingDirectory, string $cacheDirectory, array $phpOptions = []): array
{
    return runCommand(
        ['env', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', ...$phpOptions, '-d', 'auto_prepend_file=' . GUARD, $phpFile],
        '',
        $workingDirectory,
    );
}

function copyDirectory(string $from, string $to): void
{
    exec('cp -R ' . escapeshellarg($from) . ' ' . escapeshellarg($to), $output, $status);
    assertSame(0, $status, "cp -R $from $to");
}

/** @return list<string> paths of the .lua files under $directory, relative to it */
function luaFilesUnder(string $directory): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_ends_with($file->getPathname(), '.lua')) {
            $files[] = substr($file->getPathname(), \strlen($directory) + 1);
        }
    }
    sort($files);
    return $files;
}

/** [status, stdout, stderr] with the program name at the start of stderr lines normalized */
function normalized(array $result, string $programName): array
{
    return [$result[0], normalizeDifferentialOutput($result[1], $programName), normalizeDifferentialOutput($result[2], $programName)];
}

function test_project_transpiled_to_another_directory_behaves_like_lua(): void
{
    $outputDirectory = scratchDirectory() . '/aot-out';
    [$status, $stdout, $stderr] = lua2php([PROJECT, '-o', $outputDirectory]);
    assertSame(1, $status, 'a syntax error makes the exit status non-zero');
    assertSame('', $stdout);
    assertSame(REPO_ROOT . "/bin/lua2php: broken.lua:2: unexpected symbol near '='\n", $stderr);
    foreach (luaFilesUnder(PROJECT) as $luaFile) {
        assertTrue(is_file($outputDirectory . '/' . substr($luaFile, 0, -4) . '.php'), "$luaFile was transpiled");
    }
    assertSame([], glob($outputDirectory . '/*.lua'), 'no Lua file is copied');

    $reference = normalized(runCommand(['lua5.4', 'main.lua'], '', PROJECT), 'lua5.4');
    assertSame(1, $reference[0]);
    $ours = normalized(runGuarded('main.php', $outputDirectory, scratchDirectory() . '/aot-out-cache'), 'main.php');
    assertSame($reference, $ours);
    assertSame(
        normalized(runCommand(['lua5.4', 'paths.lua'], '', PROJECT), 'lua5.4'),
        normalized(runGuarded('paths.php', $outputDirectory, scratchDirectory() . '/aot-out-cache'), 'paths.php'),
    );
}

function test_project_transpiled_in_place_behaves_like_lua(): void
{
    $directory = scratchDirectory() . '/aot-in-place';
    copyDirectory(PROJECT, $directory);
    [$status, , $stderr] = lua2php([$directory]);
    assertSame(1, $status);
    assertSame(REPO_ROOT . "/bin/lua2php: broken.lua:2: unexpected symbol near '='\n", $stderr);
    foreach (luaFilesUnder(PROJECT) as $luaFile) {
        assertTrue(is_file($directory . '/' . substr($luaFile, 0, -4) . '.php'), "$luaFile was transpiled next to itself");
    }
    $reference = normalized(runCommand(['lua5.4', 'main.lua'], '', PROJECT), 'lua5.4');
    $ours = normalized(runGuarded('main.php', $directory, scratchDirectory() . '/aot-in-place-cache'), 'main.php');
    assertSame($reference, $ours);
    assertSame(
        normalized(runCommand(['lua5.4', 'paths.lua'], '', PROJECT), 'lua5.4'),
        normalized(runGuarded('paths.php', $directory, scratchDirectory() . '/aot-in-place-cache'), 'paths.php'),
    );
    // every generated file also runs as a script, as `lua5.4 file.lua` does
    assertSame(
        normalized(runCommand(['lua5.4', 'broken.lua'], '', PROJECT), 'lua5.4'),
        normalized(runGuarded('broken.php', $directory, scratchDirectory() . '/aot-in-place-cache'), 'broken.php'),
    );
    assertSame(
        normalized(runCommand(['lua5.4', 'lib/shapes/init.lua', 'a'], '', PROJECT), 'lua5.4'),
        normalized(runCommand(['env', 'LUAPHP_CACHE_DIR=' . scratchDirectory() . '/aot-in-place-cache', 'php', 'lib/shapes/init.php', 'a'], '', $directory), 'lib/shapes/init.php'),
    );
}

function test_a_file_that_was_not_transpiled_is_a_lua_error_and_never_read(): void
{
    $directory = scratchDirectory() . '/aot-missing';
    copyDirectory(PROJECT, $directory);
    file_put_contents("$directory/writer.lua", <<<'LUA'
        local file = assert(io.open("generated.lua", "w"))
        file:write("print('generated code ran')\nreturn 1\n")
        file:close()
        print(pcall(dofile, "generated.lua"))
        print(loadfile("generated.lua"))
        print(pcall(require, "generated"))
        print(package.searchpath("generated", package.path))
        os.remove("generated.lua")
        print(pcall(require, "lib.util"))
        print(loadfile("secret.lua"))
        print(loadfile())
        print(pcall(dofile))
        print(loadfile("lib"))
        print(loadfile("foreign.lua"))
        print(pcall(require, "foreign"))
        LUA);
    lua2php([$directory]);
    unlink("$directory/lib/util.php");
    // a Lua file that exists but cannot be read: reading it would fail differently
    file_put_contents("$directory/secret.lua", "print('secret')\n");
    // a PHP file lua2php did not generate where foreign.lua's would be
    file_put_contents("$directory/foreign.lua", "print('foreign Lua')\n");
    file_put_contents("$directory/foreign.php", "<?php echo 'FOREIGN PHP RAN';\n");
    chmod("$directory/secret.lua", 0);
    $result = runGuarded('writer.php', $directory, "$directory/cache");
    chmod("$directory/secret.lua", 0600);
    assertSame([0, <<<'OUT'
        false	cannot load generated.lua: not transpiled ahead of time (no generated.php)
        nil	cannot load generated.lua: not transpiled ahead of time (no generated.php)
        false	error loading module 'generated' from file './generated.lua':
        	cannot load ./generated.lua: not transpiled ahead of time (no ./generated.php)
        ./generated.lua
        false	error loading module 'lib.util' from file './lib/util.lua':
        	cannot load ./lib/util.lua: not transpiled ahead of time (no ./lib/util.php)
        nil	cannot load secret.lua: not transpiled ahead of time (no secret.php)
        nil	cannot load stdin: standard input cannot be transpiled ahead of time
        false	cannot load stdin: standard input cannot be transpiled ahead of time
        nil	cannot read lib: Is a directory
        nil	cannot load foreign.lua: not transpiled ahead of time (foreign.php was not generated by lua2php)
        false	error loading module 'foreign' from file './foreign.lua':
        	cannot load ./foreign.lua: not transpiled ahead of time (./foreign.php was not generated by lua2php)

        OUT, ''], $result);
}

function test_load_compiles_only_on_a_cache_miss(): void
{
    $directory = scratchDirectory() . '/aot-load';
    mkdir($directory);
    file_put_contents("$directory/loader.lua", "print(load('return 6 * 7')())\n");
    assertSame(0, lua2php(["$directory/loader.lua"])[0]);
    $cacheDirectory = "$directory/cache";
    // a new string: load() needs the compiler (the one exception to ahead of time)
    [$status, $stdout, $stderr] = runGuarded('loader.php', $directory, $cacheDirectory);
    assertSame([97, '', "GUARD: LuaPhp\\Compiler\\Compiler loaded at run time\n"], [$status, $stdout, $stderr]);
    assertSame([0, "42\n", ''], runCommand(['env', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', 'loader.php'], '', $directory));
    // with the disk cache warm, the same load() compiles nothing
    assertSame([0, "42\n", ''], runGuarded('loader.php', $directory, $cacheDirectory));
}

/**
 * A string LUA_INIT is code that exists only at run time, like debug.debug()'s
 * commands: it goes through load() and its cache. LUA_INIT=@file loads the
 * precompiled file.
 */
function test_lua_init_code_is_loaded_like_load(): void
{
    $directory = scratchDirectory() . '/aot-init';
    mkdir($directory);
    file_put_contents("$directory/hello.lua", "print('hello')\n");
    file_put_contents("$directory/init.lua", "print('init file')\n");
    lua2php([$directory]);
    $cacheDirectory = "$directory/cache";
    $withInit = static fn (string $init, bool $guarded): array => runCommand(
        ['env', "LUA_INIT=$init", "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', ...($guarded ? ['-d', 'auto_prepend_file=' . GUARD] : []), 'hello.php'],
        '',
        $directory,
    );
    assertSame([97, '', "GUARD: LuaPhp\\Compiler\\Compiler loaded at run time\n"], $withInit("print('init code')", true));
    assertSame([0, "init code\nhello\n", ''], $withInit("print('init code')", false));
    assertSame([0, "init code\nhello\n", ''], $withInit("print('init code')", true));
    assertSame([0, "init file\nhello\n", ''], $withInit('@init.lua', true));
}

function test_jit_is_switched_on_only_when_the_host_left_it_available(): void
{
    $directory = scratchDirectory() . '/aot-jit';
    mkdir($directory);
    file_put_contents("$directory/hello.lua", "print('hello')\n");
    assertSame(0, lua2php(["$directory/hello.lua"])[0]);
    $jitStatus = REPO_ROOT . '/tests/aot/jit_status.php';
    $run = static fn (array $options): array => runCommand(
        ['php', '-d', 'opcache.enable=1', ...$options, '-d', "auto_prepend_file=$jitStatus", 'hello.php'],
        '',
        $directory,
    );
    $cases = [
        // [php options, the JIT's state at the end: opcache.jit, on]
        [['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=off', '-d', 'opcache.jit_buffer_size=16M'], "jit tracing on\n"],
        [['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=16M'], "jit tracing on\n"],
        [['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=disable', '-d', 'opcache.jit_buffer_size=16M'], "jit disable off\n"],
        [['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=off', '-d', 'opcache.jit_buffer_size=0'], "jit  off\n"],
        [['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=function', '-d', 'opcache.jit_buffer_size=16M'], "jit function on\n"],
        [['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=1205', '-d', 'opcache.jit_buffer_size=16M'], "jit 1205 on\n"],
        [['-d', 'opcache.enable_cli=0', '-d', 'opcache.jit=off', '-d', 'opcache.jit_buffer_size=16M'], "jit  off\n"],
    ];
    foreach ($cases as [$options, $expectedStatus]) {
        assertSame([0, "hello\n", $expectedStatus], $run($options), implode(' ', $options));
    }
}
