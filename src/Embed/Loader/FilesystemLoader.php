<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Loader;

/**
 * Scripts from the files under a root directory, by relative name
 * ("discounts/loyalty.lua"). Nothing outside the root is ever read: an
 * absolute name, a name with a ".." component, and a name whose real path
 * (symbolic links resolved) leaves the root are all "not found".
 */
final class FilesystemLoader implements Loader
{
    /** the root's real path with a trailing slash */
    private readonly string $root;

    public function __construct(string $root)
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new \InvalidArgumentException("not a directory: $root");
        }
        $this->root = rtrim($realRoot, '/') . '/';
    }

    public function exists(string $name): bool
    {
        return $this->path($name) !== null;
    }

    public function getSource(string $name): Source
    {
        $path = $this->path($name);
        $code = $path === null ? false : file_get_contents($path);
        if ($code === false) {
            throw new \InvalidArgumentException("Lua script not found: $name");
        }
        return new Source($code, '@' . $name);
    }

    /** the real path of the readable file $name under the root, or null */
    private function path(string $name): ?string
    {
        if ($name === '' || $name[0] === '/' || str_contains($name, "\0") || \in_array('..', explode('/', $name), true)) {
            return null;
        }
        $path = realpath($this->root . $name);
        if ($path === false || !str_starts_with($path, $this->root) || !is_file($path) || !is_readable($path)) {
            return null;
        }
        return $path;
    }
}
