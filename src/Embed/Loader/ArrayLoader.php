<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Loader;

/** Scripts from an array of name => code (tests, code stored in a database). */
final class ArrayLoader implements Loader
{
    /** @param array<string, string> $scripts */
    public function __construct(
        private readonly array $scripts,
    ) {
    }

    public function exists(string $name): bool
    {
        return isset($this->scripts[$name]);
    }

    public function getSource(string $name): Source
    {
        if (!isset($this->scripts[$name])) {
            throw new \InvalidArgumentException("Lua script not found: $name");
        }
        return new Source($this->scripts[$name], '@' . $name);
    }
}
