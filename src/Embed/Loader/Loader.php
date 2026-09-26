<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Loader;

/**
 * Where an Environment finds scripts: Environment::run(name) and require.
 * require("a.b") asks for "a/b.lua", then "a/b/init.lua".
 */
interface Loader
{
    public function exists(string $name): bool;

    /** @throws \InvalidArgumentException when there is no such script */
    public function getSource(string $name): Source;
}
