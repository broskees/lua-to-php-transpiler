<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * Library profiles for Environment's libraries: a list of library names
 * (base, package, string, table, math, utf8, coroutine, io, os, debug) or
 * single functions ("os.time", "base.print"). Any list but ALL is
 * sandboxed: no dofile, loadfile or string.dump; load only with
 * allowLoad (text chunks only, with the run's limits and the
 * Environment's cache); collectgarbage limited to "collect", "count" and
 * "step"; package is only package.loaded, and require (with "package")
 * finds host modules, then the loader's files. An unknown name is an
 * \InvalidArgumentException when the Environment is created.
 *
 * SAFE (the default): base, require and package.loaded, string, table,
 * math, utf8, coroutine, and os.time, os.date, os.clock and os.difftime.
 * (Nothing process-wide: no io, os.exit, os.setlocale, os.execute.)
 *
 * ALL: every standard library as bin/lua has them, for trusted code (it
 * can read files, run commands and end the PHP process); require also
 * finds host modules and the loader's files first.
 */
final class Libraries
{
    public const SAFE = ['base', 'package', 'string', 'table', 'math', 'utf8', 'coroutine', 'os.time', 'os.date', 'os.clock', 'os.difftime'];

    public const ALL = ['base', 'package', 'coroutine', 'table', 'io', 'os', 'string', 'math', 'utf8', 'debug'];

    /**
     * @internal
     * @param list<string> $libraries
     * @return list<string>
     */
    public static function check(array $libraries): array
    {
        if (!array_is_list($libraries) || array_filter($libraries, static fn (mixed $name): bool => !\is_string($name)) !== []) {
            throw new \InvalidArgumentException('libraries must be a list of names');
        }
        return $libraries;
    }
}
