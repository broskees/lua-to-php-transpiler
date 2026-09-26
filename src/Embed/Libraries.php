<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * Library profiles for Environment's libraries: a list of library names
 * (base, package, string, table, math, utf8, coroutine, io, os, debug) or
 * single functions ("os.time").
 *
 * SAFE (the default): base without dofile, loadfile and load (unless
 * allowLoad), with collectgarbage limited to "collect", "count" and
 * "step"; require (host modules and the loader) and package.loaded;
 * string without string.dump; table, math, utf8, coroutine; os.time,
 * os.date, os.clock and os.difftime.
 *
 * ALL: every standard library, as bin/lua has them, for trusted code.
 */
final class Libraries
{
    public const SAFE = ['base', 'package', 'string', 'table', 'math', 'utf8', 'coroutine', 'os.time', 'os.date', 'os.clock', 'os.difftime'];

    public const ALL = ['base', 'package', 'coroutine', 'table', 'io', 'os', 'string', 'math', 'utf8', 'debug'];

    /** the names a list of libraries may use */
    private const NAMES = ['base', 'package', 'string', 'table', 'math', 'utf8', 'coroutine', 'io', 'os', 'debug'];

    /**
     * @internal
     * @param list<string> $libraries
     * @return list<string>
     */
    public static function check(array $libraries): array
    {
        if (!array_is_list($libraries)) {
            throw new \InvalidArgumentException('libraries must be a list of names');
        }
        foreach ($libraries as $name) {
            $library = \is_string($name) ? explode('.', $name, 2) : [];
            if (
                !\in_array($library[0] ?? null, self::NAMES, true)
                || (isset($library[1]) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $library[1]) !== 1)
            ) {
                throw new \InvalidArgumentException('unknown library ' . var_export($name, true) . ' (use ' . implode(', ', self::NAMES) . ', or "library.function")');
            }
        }
        return $libraries;
    }
}
