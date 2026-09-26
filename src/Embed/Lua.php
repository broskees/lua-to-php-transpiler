<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/** Shortcuts. */
final class Lua
{
    private static ?Environment $environment = null;

    /**
     * Runs $code in a new sandbox of a default Environment (SAFE
     * libraries, no loader) with $args; returns its results.
     *
     * @param list<mixed> $args
     * @return list<mixed>
     */
    public static function run(string $code, array $args = []): array
    {
        self::$environment ??= new Environment();
        return self::$environment->newSandbox()->load($code)->call(...$args);
    }

    /** several return values of a PHP function called from Lua: return Lua::multiple($a, $b) */
    public static function multiple(mixed ...$values): Multiple
    {
        return new Multiple(array_values($values));
    }
}
