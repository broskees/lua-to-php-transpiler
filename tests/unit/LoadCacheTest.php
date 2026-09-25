<?php

declare(strict_types=1);

namespace Tests\LoadCacheTest;

use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\LoadCache;
use LuaPhp\Runtime\Standalone;

/*
 * The byte cache of load() (LoadCache): chunks are looked up by their exact
 * bytes and chunk name, in memory and in a disk cache of generated PHP
 * files. bin/lua uses the disk cache only when LUAPHP_CACHE_DIR is set.
 * tests/aot/guard.php exits with status 97 when a transpiler class loads,
 * so a guarded run that succeeds compiled nothing.
 */

const GUARD = REPO_ROOT . '/tests/aot/guard.php';

/** a script whose loads all succeed, so a warm cache serves every one of them */
const HITS_SCRIPT = <<<'LUA'
    local long = string.rep("y", 60)
    local f = load("local s = '" .. long .. "'\nreturn function () return s, '" .. long .. "' end", "=hits")
    local a, b = f()()
    print(a == long, string.format("%p", a) == string.format("%p", b))
    local sum = 0
    for i = 1, 20 do sum = sum + load("return " .. i .. " + 1", "=n")() end
    print(sum, select(2, pcall(load("error('boom')", "@hits.lua"))))
    LUA;

/** @return array{int, string, string} */
function runLua(string $script, string $workingDirectory, ?string $cacheDirectory, bool $guarded = false, string $program = REPO_ROOT . '/bin/lua'): array
{
    $environment = $cacheDirectory === null ? ['-u', 'LUAPHP_CACHE_DIR'] : ["LUAPHP_CACHE_DIR=$cacheDirectory"];
    $phpOptions = $guarded ? ['-d', 'auto_prepend_file=' . GUARD] : [];
    return runCommand(['env', ...$environment, 'php', ...$phpOptions, $program, $script], '', $workingDirectory);
}

/** @return list<string> the cache entries (generated PHP files) in $directory */
function entries(string $directory): array
{
    $files = glob("$directory/*.php");
    sort($files);
    return $files;
}

function newDirectory(string $name): string
{
    $directory = scratchDirectory() . "/$name";
    mkdir($directory, 0700);
    return $directory;
}

function test_cached_loads_behave_like_fresh_ones(): void
{
    $diffDirectory = REPO_ROOT . '/tests/diff';
    foreach (['load_cache.lua', 'load_cache_depth.lua', 'load_chunks.lua', 'load_nesting.lua', 'string_dump.lua', 'string_pointer.lua'] as $script) {
        $cacheDirectory = newDirectory("cache-behaves-$script");
        $reference = runCommand(['lua5.4', $script], '', $diffDirectory);
        $expected = [$reference[0], normalizeDifferentialOutput($reference[1], 'lua5.4'), normalizeDifferentialOutput($reference[2], 'lua5.4')];
        foreach (['cold', 'warm'] as $run) {
            $result = runLua($script, $diffDirectory, $cacheDirectory);
            $actual = [$result[0], normalizeDifferentialOutput($result[1], 'lua5.4'), normalizeDifferentialOutput($result[2], REPO_ROOT . '/bin/lua')];
            assertSame($expected, $actual, "$script, $run cache");
            assertTrue(entries($cacheDirectory) !== [], "$script: entries written");
        }
    }
}

function test_a_warm_cache_compiles_nothing(): void
{
    $directory = newDirectory('cache-warm');
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    $expected = [0, "true\ttrue\n230\thits.lua:1: boom\n", ''];
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache"));
    // bin/lua loads its script through load() too: a warm run compiles nothing at all
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache", guarded: true));
    // without LUAPHP_CACHE_DIR, bin/lua uses no disk cache
    [$status] = runCommand(['env', '-u', 'LUAPHP_CACHE_DIR', 'TMPDIR=' . newDirectory('cache-warm-tmp'), 'php', '-d', 'auto_prepend_file=' . GUARD, REPO_ROOT . '/bin/lua', 'hits.lua'], '', $directory);
    assertSame(97, $status);
    assertSame([], glob(scratchDirectory() . '/cache-warm-tmp/*'), 'bin/lua writes no cache without LUAPHP_CACHE_DIR');
}

function test_corrupt_entries_are_misses(): void
{
    $directory = newDirectory('cache-corrupt');
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    $expected = [0, "true\ttrue\n230\thits.lua:1: boom\n", ''];
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache"));
    $corruptions = [
        'empty' => static fn (string $contents): string => '',
        'truncated' => static fn (string $contents): string => substr($contents, 0, intdiv(\strlen($contents), 2)),
        'garbage' => static fn (string $contents): string => "<?php \x01\x02 garbage (\n",
        'not PHP' => static fn (string $contents): string => random_bytes(200),
        'another array' => static fn (string $contents): string => "<?php return [1, 2, 3];\n",
        'another key' => static fn (string $contents): string => preg_replace("/'key' => '[0-9a-f]+'/", "'key' => 'x'", $contents),
        'throws' => static fn (string $contents): string => "<?php throw new \\RuntimeException('corrupt');\n",
    ];
    foreach ($corruptions as $label => $corrupt) {
        $entries = entries("$directory/cache");
        assertSame(23, \count($entries), "entries before '$label' (the script and 22 loads)");
        foreach ($entries as $entry) {
            file_put_contents($entry, $corrupt(file_get_contents($entry)));
        }
        assertSame($expected, runLua('hits.lua', $directory, "$directory/cache"), "run with '$label' entries");
        // the misses rewrote the entries
        assertSame($expected, runLua('hits.lua', $directory, "$directory/cache", guarded: true), "guarded run after '$label'");
    }
    // an unreadable entry is a miss too
    foreach (entries("$directory/cache") as $entry) {
        chmod($entry, 0);
    }
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache"), 'unreadable entries');
}

function test_concurrent_writers(): void
{
    $directory = newDirectory('cache-concurrent');
    $script = <<<'LUA'
        local sum = 0
        for i = 1, 200 do sum = sum + load("return " .. i .. " * 3", "=w")() end
        local big = {}
        for i = 1, 3000 do big[i] = "x" .. i .. " = " .. i end
        load(table.concat(big, "\n"), "=big")()
        print(sum, x3000)
        LUA;
    file_put_contents("$directory/writers.lua", $script);
    $cacheDirectory = newDirectory('cache-concurrent/cache');
    $processes = [];
    for ($i = 0; $i < 3; $i++) {
        $processes[] = startProcess(['env', "LUAPHP_CACHE_DIR=$cacheDirectory", 'php', REPO_ROOT . '/bin/lua', 'writers.lua'], $directory);
    }
    foreach ($processes as $process) {
        assertSame([0, "60300\t3000\n", ''], finishProcess($process));
    }
    assertSame(202, \count(entries($cacheDirectory)), 'one entry per chunk (the script, 200 small ones, the big one)');
    $others = array_diff(scandir($cacheDirectory), ['.', '..', 'usage'], array_map('basename', entries($cacheDirectory)));
    assertSame([], array_values($others), 'no temporary files left');
    assertSame([0, "60300\t3000\n", ''], runLua('writers.lua', $directory, $cacheDirectory, guarded: true));
}

function test_entries_of_another_runtime_version_are_not_used(): void
{
    $directory = newDirectory('cache-version');
    exec('cp -R ' . escapeshellarg(REPO_ROOT . '/src') . ' ' . escapeshellarg(REPO_ROOT . '/bin') . ' ' . escapeshellarg($directory), $output, $status);
    assertSame(0, $status);
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    $expected = [0, "true\ttrue\n230\thits.lua:1: boom\n", ''];
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache", program: "$directory/bin/lua"));
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache", guarded: true, program: "$directory/bin/lua"));
    // any change to the runtime or the transpiler changes the fingerprint
    file_put_contents("$directory/src/Runtime/Lua.php", "\n// another version\n", FILE_APPEND);
    [$status] = runLua('hits.lua', $directory, "$directory/cache", guarded: true, program: "$directory/bin/lua");
    assertSame(97, $status, 'entries of the other version are misses');
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache", program: "$directory/bin/lua"));
    assertSame($expected, runLua('hits.lua', $directory, "$directory/cache", guarded: true, program: "$directory/bin/lua"));
}

function test_the_disk_cache_is_bounded(): void
{
    $directory = newDirectory('cache-bound');
    $entryLimit = LoadCache::$diskEntryLimit;
    $byteLimit = LoadCache::$diskByteLimit;
    $memoryLimit = LoadCache::$memoryEntryLimit;
    try {
        LoadCache::useDirectory($directory);
        LoadCache::$diskEntryLimit = 5;
        LoadCache::$memoryEntryLimit = 3;
        $L = Standalone::newStateWithLibraries();
        for ($i = 0; $i < 12; $i++) {
            ChunkLoader::load($L, "return $i", '=bound', null);
            assertTrue(\count(entries($directory)) <= 5, 'at most 5 entries on disk');
            assertTrue(LoadCache::memoryEntryCount() <= 3, 'at most 3 entries in memory');
        }
        assertSame(2, \count(entries($directory)), 'the 11th write emptied the directory first');
        [$entries, $bytes] = explode(' ', file_get_contents("$directory/usage"));
        assertSame('2', $entries);
        assertSame((string) array_sum(array_map('filesize', entries($directory))), $bytes);

        // the byte limit: room for two entries of this size, not three
        $entrySize = filesize(entries($directory)[0]);
        LoadCache::$diskEntryLimit = 1000;
        LoadCache::$diskByteLimit = 2 * $entrySize + intdiv($entrySize, 2);
        for ($i = 20; $i < 26; $i++) {
            ChunkLoader::load($L, "return $i", '=bound', null);
            assertTrue(array_sum(array_map('filesize', entries($directory))) <= LoadCache::$diskByteLimit, 'within the byte limit');
        }
        // an entry bigger than the limit on its own is not written
        $before = entries($directory);
        ChunkLoader::load($L, 'local x = 1 return ' . str_repeat('x + ', 300) . 'x', '=bound', null);
        assertSame($before, entries($directory));
    } finally {
        LoadCache::useDirectory(null);
        LoadCache::$diskEntryLimit = $entryLimit;
        LoadCache::$diskByteLimit = $byteLimit;
        LoadCache::$memoryEntryLimit = $memoryLimit;
    }
}

function test_the_cache_directory_must_be_private(): void
{
    $directory = newDirectory('cache-private');
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    $expected = [0, "true\ttrue\n230\thits.lua:1: boom\n", ''];

    mkdir("$directory/shared", 0755);
    chmod("$directory/shared", 0755);
    assertSame($expected, runLua('hits.lua', $directory, "$directory/shared"));
    assertSame(['.', '..'], scandir("$directory/shared"), 'a directory others can read is not used');

    symlink("$directory/shared", "$directory/link");
    chmod("$directory/shared", 0700);
    assertSame($expected, runLua('hits.lua', $directory, "$directory/link"));
    assertSame(['.', '..'], scandir("$directory/shared"), 'a symbolic link is not used');
    assertSame($expected, runLua('hits.lua', $directory, "$directory/link/"));
    assertSame(['.', '..'], scandir("$directory/shared"), 'a symbolic link is not used with a trailing slash either');
    assertSame($expected, runLua('hits.lua', $directory, "$directory/link//"));
    assertSame(['.', '..'], scandir("$directory/shared"), 'nor with two');

    file_put_contents("$directory/file", '');
    assertSame($expected, runLua('hits.lua', $directory, "$directory/file/cache"), 'a directory that cannot be created: silently no cache');

    assertSame($expected, runLua('hits.lua', $directory, "$directory/new/cache"));
    assertSame(040700, fileperms("$directory/new/cache") & 0777777, 'created private');
    assertTrue(entries("$directory/new/cache") !== []);
}

/**
 * Like ssh's StrictModes: no other user may be able to replace the cache
 * directory, so it and every directory above it must be owned by root or
 * by us and not writable by others unless sticky (as /tmp is).
 */
function test_a_cache_directory_others_could_replace_is_not_used(): void
{
    $directory = newDirectory('cache-replace');
    // the name of the entry for this chunk (the same in any cache directory)
    $trusted = newDirectory('cache-replace/trusted');
    LoadCache::useDirectory($trusted);
    try {
        $L = Standalone::newStateWithLibraries();
        ChunkLoader::load($L, 'return 42', '=victim', null);
        $entryName = basename(entries($trusted)[0]);

        // a parent directory others can write to (not sticky): they could
        // rename our cache directory away and put theirs in its place
        mkdir("$directory/open", 0777);
        chmod("$directory/open", 0777);
        LoadCache::useDirectory("$directory/open/cache");
        ChunkLoader::load($L, 'return 1', '=first', null);  // the check happens here, once
        assertSame([], glob("$directory/open/cache/*.php"), 'not used');
        if (is_dir("$directory/open/cache")) {
            rename("$directory/open/cache", "$directory/open/ours");
        }
        mkdir("$directory/open/cache", 0777);
        $marker = "$directory/foreign-code-ran";
        file_put_contents("$directory/open/cache/$entryName", '<?php file_put_contents(' . var_export($marker, true) . ', "x"); return null;');
        assertSame([0, [42]], Standalone::docall($L, ChunkLoader::load($L, 'return 42', '=victim', null), []));
        assertTrue(!file_exists($marker), 'a replaced cache directory is never included from');

        // group-writable is refused too; a sticky world-writable parent is fine
        mkdir("$directory/group", 0770);
        chmod("$directory/group", 0770);
        LoadCache::useDirectory("$directory/group/cache");
        ChunkLoader::load($L, 'return 2', '=second', null);
        assertSame([], glob("$directory/group/cache/*.php"), 'a group-writable parent: not used');
        mkdir("$directory/sticky", 0777);
        chmod("$directory/sticky", 01777);
        LoadCache::useDirectory("$directory/sticky/cache");
        ChunkLoader::load($L, 'return 3', '=third', null);
        assertSame(1, \count(glob("$directory/sticky/cache/*.php")), 'a sticky parent: used');
    } finally {
        LoadCache::useDirectory(null);
    }
}

function test_the_disk_cache_works_without_posix_functions(): void
{
    $directory = newDirectory('cache-no-posix');
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    $expected = [0, "true\ttrue\n230\thits.lua:1: boom\n", ''];
    $noPosix = ['-d', 'disable_functions=posix_geteuid,posix_getuid,posix_getegid,posix_getgid,posix_getpwuid'];
    $run = static fn (array $environment, array $phpOptions, array $script): array
        => runCommand(['env', ...$environment, 'php', ...$noPosix, ...$phpOptions, ...$script], '', $directory);
    // bin/lua with LUAPHP_CACHE_DIR
    $cacheDirectory = "$directory/cache";
    assertSame($expected, $run(["LUAPHP_CACHE_DIR=$cacheDirectory"], [], [REPO_ROOT . '/bin/lua', 'hits.lua']));
    assertSame(23, \count(entries($cacheDirectory)));
    assertSame($expected, $run(["LUAPHP_CACHE_DIR=$cacheDirectory"], ['-d', 'auto_prepend_file=' . GUARD], [REPO_ROOT . '/bin/lua', 'hits.lua']));
    // a generated script's default directory
    runCommand(['php', REPO_ROOT . '/bin/lua2php', 'hits.lua'], '', $directory);
    $temporary = newDirectory('cache-no-posix/tmp');
    assertSame($expected, $run(['-u', 'LUAPHP_CACHE_DIR', "TMPDIR=$temporary"], [], ['hits.php']));
    assertSame(22, \count(entries("$temporary/luaphp-" . posix_geteuid())), 'luaphp-<uid> without posix');
    assertSame($expected, $run(['-u', 'LUAPHP_CACHE_DIR', "TMPDIR=$temporary"], ['-d', 'auto_prepend_file=' . GUARD], ['hits.php']));
}

function test_generated_scripts_default_to_a_private_directory_under_tmp(): void
{
    $directory = newDirectory('cache-default');
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    runCommand(['php', REPO_ROOT . '/bin/lua2php', 'hits.lua'], '', $directory);
    $expected = [0, "true\ttrue\n230\thits.lua:1: boom\n", ''];
    $temporary = newDirectory('cache-default/tmp');
    $cacheDirectory = "$temporary/luaphp-" . posix_geteuid();
    assertSame($expected, runCommand(['env', '-u', 'LUAPHP_CACHE_DIR', "TMPDIR=$temporary", 'php', 'hits.php'], '', $directory));
    assertSame(040700, fileperms($cacheDirectory) & 0777777);
    assertSame(22, \count(entries($cacheDirectory)), 'one entry per load()');
    assertSame($expected, runCommand(['env', '-u', 'LUAPHP_CACHE_DIR', "TMPDIR=$temporary", 'php', '-d', 'auto_prepend_file=' . GUARD, 'hits.php'], '', $directory));

    // a directory that is not private (here: world-writable) is not used
    $otherTemporary = newDirectory('cache-default/other-tmp');
    mkdir("$otherTemporary/luaphp-" . posix_geteuid(), 0777);
    chmod("$otherTemporary/luaphp-" . posix_geteuid(), 0777);
    assertSame($expected, runCommand(['env', '-u', 'LUAPHP_CACHE_DIR', "TMPDIR=$otherTemporary", 'php', 'hits.php'], '', $directory));
    assertSame([], entries("$otherTemporary/luaphp-" . posix_geteuid()));
}

function test_cache_files_do_nothing_when_run_directly(): void
{
    $directory = newDirectory('cache-run');
    file_put_contents("$directory/hits.lua", HITS_SCRIPT);
    runLua('hits.lua', $directory, "$directory/cache");
    assertSame(23, \count(entries("$directory/cache")));
    foreach (entries("$directory/cache") as $entry) {
        assertSame([0, '', ''], runCommand(['php', $entry], '', $directory), basename($entry));
    }
}
