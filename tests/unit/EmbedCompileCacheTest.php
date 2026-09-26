<?php

declare(strict_types=1);

namespace Tests\EmbedCompileCacheTest;

/*
 * The embedding API's compile cache on disk (Environment's cacheDir):
 * scripts compiled once are included from generated PHP files by later
 * processes (so opcache keeps them), Environment::compile fills it ahead
 * of time, the key is the chunk name and the exact bytes, and the
 * directory must be private, as load()'s cache (CacheDirectory).
 * tests/aot/guard.php exits with status 97 when a compiler class loads:
 * a guarded run that succeeds compiled nothing.
 */

const GUARD = REPO_ROOT . '/tests/aot/guard.php';

/** runs $script/main.lua (or compiles the given names) through the embedding API; prints JSON */
const DRIVER = <<<'PHP'
    <?php
    declare(strict_types=1);
    require $argv[1] . '/src/autoload.php';
    [, , $root, $cacheDirectory, $mode] = $argv;
    $limits = $mode === 'unlimited' ? LuaPhp\Embed\Limits::none() : new LuaPhp\Embed\Limits();
    $environment = new LuaPhp\Embed\Environment(loader: new LuaPhp\Embed\Loader\FilesystemLoader($root), cacheDir: $cacheDirectory, limits: $limits);
    $environment->addGlobal('record', static function (string $what) use ($root): void {
        file_put_contents("$root/ran.txt", "$what\n", FILE_APPEND);
    });
    if ($mode === 'compile') {
        echo json_encode(array_map(static fn ($error) => $error->getMessage(), $environment->compile(array_slice($argv, 5))));
        exit(0);
    }
    echo json_encode($environment->run('main.lua')->values);
    $real = realpath($cacheDirectory);
    $cached = 0;
    foreach (array_keys((function_exists('opcache_get_status') ? opcache_get_status(true) : false)['scripts'] ?? []) as $file) {
        $cached += $real !== false && str_starts_with($file, "$real/") ? 1 : 0;
    }
    fwrite(STDERR, "opcache: $cached\n");
    PHP;

function project(string $name): string
{
    $directory = scratchDirectory() . "/embed-cache-$name";
    mkdir("$directory/root", 0700, true);
    file_put_contents("$directory/driver.php", DRIVER);
    file_put_contents("$directory/root/main.lua", 'record("main") local long = string.rep("y", 50) return require("lib").value, require("dup1"), require("dup2"), long');
    file_put_contents("$directory/root/lib.lua", 'return {value = 42}');
    file_put_contents("$directory/root/dup1.lua", 'return "same code"');
    file_put_contents("$directory/root/dup2.lua", 'return "same code"');
    file_put_contents("$directory/root/bad.lua", 'return return');
    return $directory;
}

/** @return array{int, string, string} */
function drive(string $directory, string $cacheDirectory, bool $guarded = false, string $mode = 'run', array $names = []): array
{
    $options = $guarded ? ['-d', 'auto_prepend_file=' . GUARD] : [];
    return runCommand(['php', ...OPCACHE_ON_NEW_FILES, ...$options, "$directory/driver.php", REPO_ROOT, "$directory/root", $cacheDirectory, $mode, ...$names], '', $directory);
}

/** @return list<string> */
function entries(string $cacheDirectory): array
{
    return glob("$cacheDirectory/*.php");
}

const OUTPUT = '[42,"same code","same code","yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"]';

function test_later_processes_include_what_was_compiled(): void
{
    $directory = project('warm');
    $cache = "$directory/cache";
    [$status, $output, $errors] = drive($directory, $cache);
    assertSame([0, OUTPUT], [$status, $output], $errors);
    assertSame(4, \count(entries($cache)), 'one entry per script: the same code under two names is two');
    assertSame(040700, fileperms($cache) & 0777777, 'created private');
    [$status, $output, $errors] = drive($directory, $cache, guarded: true);
    assertSame([0, OUTPUT], [$status, $output], "a warm run compiles nothing: $errors");
    if (\function_exists('opcache_get_status')) {
        assertSame("opcache: 4\n", $errors, 'the entries are included, so opcache keeps them');
    }
    // other bytes are another script
    file_put_contents("$directory/root/lib.lua", 'return {value = 43}');
    assertSame(97, drive($directory, $cache, guarded: true)[0]);
    assertSame(0, drive($directory, $cache)[0]);
    [$status, $output] = drive($directory, $cache, guarded: true);
    assertSame([0, str_replace('42', '43', OUTPUT)], [$status, $output]);
    assertSame(5, \count(entries($cache)));
    // code that counts steps (limits) and code that does not (Limits::none()) are cached apart
    assertSame(97, drive($directory, $cache, guarded: true, mode: 'unlimited')[0]);
    assertSame([0, str_replace('42', '43', OUTPUT)], \array_slice(drive($directory, $cache, mode: 'unlimited'), 0, 2));
    assertSame(9, \count(entries($cache)));
    assertSame(0, drive($directory, $cache, guarded: true, mode: 'unlimited')[0]);
    assertSame(0, drive($directory, $cache, guarded: true)[0]);
}

function test_compiling_ahead_of_time(): void
{
    $directory = project('compile');
    $cache = "$directory/cache";
    [$status, $output, $errors] = drive($directory, $cache, mode: 'compile', names: ['main.lua', 'lib.lua', 'dup1.lua', 'dup2.lua', 'bad.lua']);
    assertSame([0, '{"bad.lua":"bad.lua:1: unexpected symbol near \'return\'"}'], [$status, $output], $errors);
    assertTrue(!file_exists("$directory/root/ran.txt"), 'nothing ran');
    assertSame(4, \count(entries($cache)), 'a script that does not compile is not cached');
    [$status, $output, $errors] = drive($directory, $cache, guarded: true);
    assertSame([0, OUTPUT], [$status, $output], $errors);
    assertSame("main\n", file_get_contents("$directory/root/ran.txt"));
}

function test_a_directory_others_can_use_is_not_trusted(): void
{
    $directory = project('shared');
    mkdir("$directory/cache", 0755);
    chmod("$directory/cache", 0755);
    [$status, $output, $errors] = drive($directory, "$directory/cache");
    assertSame([0, OUTPUT], [$status, $output], $errors);
    assertSame([], entries("$directory/cache"));
    symlink("$directory/private", "$directory/link");
    mkdir("$directory/private", 0700);
    assertSame(0, drive($directory, "$directory/link")[0]);
    assertSame([], entries("$directory/private"), 'nor a symbolic link');
}
