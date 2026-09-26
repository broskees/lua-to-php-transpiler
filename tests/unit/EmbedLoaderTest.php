<?php

declare(strict_types=1);

namespace Tests\EmbedLoaderTest;

use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Loader\ArrayLoader;
use LuaPhp\Embed\Loader\FilesystemLoader;
use LuaPhp\Embed\Loader\Source;

/*
 * Loaders of the embedding API: FilesystemLoader never reads outside its
 * root (no "..", no absolute names, no symbolic links that lead out), and
 * require resolves "a.b" to the loader's "a/b.lua", then "a/b/init.lua".
 */

/**
 * root/ with a.lua, sub/b.lua, sub/init.lua, links in and out; secret.lua
 * and outside/x.lua next to it. The secret files record that they ran.
 */
function project(): string
{
    static $directory = null;
    if ($directory !== null) {
        return $directory;
    }
    $directory = scratchDirectory() . '/embed-loader';
    mkdir("$directory/root/sub", 0777, true);
    mkdir("$directory/outside");
    file_put_contents("$directory/root/a.lua", 'return "a"');
    file_put_contents("$directory/root/sub/b.lua", 'return "b"');
    file_put_contents("$directory/root/sub/init.lua", 'return "sub"');
    file_put_contents("$directory/secret.lua", 'leaked("secret") return "secret"');
    file_put_contents("$directory/outside/x.lua", 'leaked("outside") return "outside"');
    symlink("$directory/secret.lua", "$directory/root/link-out.lua");
    symlink("$directory/outside", "$directory/root/linkdir");
    symlink('../secret.lua', "$directory/root/sub/relative-out.lua");
    symlink("$directory/root/a.lua", "$directory/root/link-in.lua");
    symlink('..', "$directory/root/sub/up");
    return $directory;
}

function test_the_filesystem_loader_stays_inside_its_root(): void
{
    $directory = project();
    $loader = new FilesystemLoader("$directory/root");
    foreach (['a.lua', 'sub/b.lua', 'sub/init.lua', 'link-in.lua', './a.lua', 'sub//b.lua', 'sub/up/a.lua'] as $name) {
        assertTrue($loader->exists($name), "$name exists");
    }
    $outside = [
        '../secret.lua', 'sub/../a.lua', '..', "$directory/secret.lua", '/etc/passwd', "$directory/root/a.lua",
        'link-out.lua', 'linkdir/x.lua', 'sub/relative-out.lua', 'sub/up/../secret.lua', 'sub', '', "a.lua\0.txt", 'missing.lua',
    ];
    foreach ($outside as $name) {
        assertTrue(!$loader->exists($name), var_export($name, true) . ' is not found');
        assertSame("Lua script not found: $name", assertThrows(\InvalidArgumentException::class, static fn () => $loader->getSource($name)));
    }
    $source = $loader->getSource('sub/b.lua');
    assertSame('return "b"', $source->code);
    assertSame('@sub/b.lua', $source->chunkName);
    assertSame(hash('sha256', 'return "b"'), $source->fingerprint);
    assertThrows(\InvalidArgumentException::class, static fn () => new FilesystemLoader("$directory/no-such-root"));
    assertThrows(\InvalidArgumentException::class, static fn () => new FilesystemLoader("$directory/secret.lua"));
    // a root reached through a symbolic link is its real path
    symlink("$directory/root", "$directory/root-link");
    assertTrue((new FilesystemLoader("$directory/root-link"))->exists('sub/b.lua'));
    assertTrue(!(new FilesystemLoader("$directory/root-link"))->exists('link-out.lua'));
}

function test_require_cannot_leave_the_root(): void
{
    $directory = project();
    $leaked = [];
    $environment = new Environment(loader: new FilesystemLoader("$directory/root"));
    $environment->addGlobal('leaked', static function (string $what) use (&$leaked): void {
        $leaked[] = $what;
    });
    $sandbox = $environment->newSandbox();
    assertSame(['a', 'b', 'sub', 'a'], $sandbox->load('return require("a"), require("sub.b"), require("sub"), (require("link-in"))')->call());
    $attempts = ['../secret', '..secret', '/etc/passwd', 'link-out', 'linkdir.x', 'sub.relative-out', 'sub.up..secret', "$directory/secret"];
    foreach ($attempts as $name) {
        $escaped = str_replace('.', '/', $name);
        [$ok, $message] = $sandbox->load('return pcall(require, ...)')->call($name);
        assertSame(false, $ok, $name);
        assertSame("module '$name' not found:\n\tno host module '$name'\n\tno file '$escaped.lua'\n\tno file '$escaped/init.lua'", $message);
    }
    assertSame([], $leaked);
    // scripts by name, with '@' and the name as chunk name
    file_put_contents("$directory/root/sub/fails.lua", 'error("x")');
    assertSame('sub/fails.lua:1: x', assertThrows(\LuaPhp\Embed\RuntimeError::class, static fn () => $environment->run('sub/fails.lua')));
    assertThrows(\InvalidArgumentException::class, static fn () => $environment->run('../secret.lua'));
    assertSame([], $leaked);
}

function test_the_array_loader(): void
{
    $loader = new ArrayLoader(['name.lua' => 'return 1', 'a/b.lua' => 'return ...']);
    assertTrue($loader->exists('name.lua'));
    assertTrue(!$loader->exists('other.lua'));
    assertTrue(!$loader->exists('./name.lua'));
    $source = $loader->getSource('a/b.lua');
    assertTrue($source instanceof Source);
    assertSame(['return ...', '@a/b.lua'], [$source->code, $source->chunkName]);
    assertSame('Lua script not found: other.lua', assertThrows(\InvalidArgumentException::class, static fn () => $loader->getSource('other.lua')));
    assertSame([[3], ['a.b', 'a/b.lua']], [
        (new Environment(loader: $loader))->run('a/b.lua', [3])->values,
        (new Environment(loader: $loader))->newSandbox()->load('return require("a.b")')->call(),
    ]);
}
