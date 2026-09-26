<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * C Lua fails an allocation it cannot make with a catchable "not enough
 * memory" error (lmem.c: luaM_error, status LUA_ERRMEM); PHP ends the
 * whole process with a fatal error when it reaches memory_limit. So a
 * library function that can build a large result in one call
 * (string.rep, table.concat, string.format, '..', io.read, ...) asks here
 * before building it, and gets Lua's error when the result would not fit
 * below memory_limit. Results of up to CHECK_ABOVE bytes are not checked.
 * Memory that grows a little at a time while Lua code runs (tables,
 * closures, call frames) is not checked here.
 *
 * With a Budget (an embedded state), a result must also fit its
 * memoryBytes, else LimitReached('memory'): callers pass the state's
 * budget (null: none).
 *
 * @internal
 */
final class MemoryLimit
{
    /** results up to this size are not checked */
    public const CHECK_ABOVE = 64 * 1024;

    /**
     * kept free below memory_limit after a checked allocation: room to
     * raise and report the error, and for PHP's next allocations (its
     * heap grows in 2 MB chunks)
     */
    private const RESERVE = 4 * 1024 * 1024;

    /** memory_limit as last read, and in bytes (-1: no limit) */
    private static string $limitSetting = '';
    private static int $limit = -1;

    /**
     * lmem.c: luaM_malloc_: "not enough memory" unless $bytes more can be
     * allocated now (the result about to be built, with the copies PHP
     * makes of it on the way) and RESERVE stays free. As C's allocator
     * collects garbage before it fails (lmem.c: tryagain), a failed check
     * frees PHP's garbage cycles and cached chunks and checks again.
     */
    public static function reserve(int $bytes, ?Budget $budget = null): void
    {
        if ($bytes <= self::CHECK_ABOVE) {
            return;
        }
        $budget?->reserveMemory($bytes);
        if (self::fits($bytes)) {
            return;
        }
        gc_collect_cycles();
        gc_mem_caches();  // freed chunks PHP keeps cached count against memory_limit
        if (self::fits($bytes)) {
            return;
        }
        throw new LuaError(Lua::MEMERRMSG, Lua::LUA_ERRMEM);
    }

    /**
     * A string built piece by piece (C: a luaL_Buffer) is about to reach
     * $length bytes: reserve room for it to grow to twice that (PHP may
     * move a growing string to a new block while the old one is still
     * allocated). Returns the length up to which it may grow before the
     * next check. Builders start with a capacity of CHECK_ABOVE.
     */
    public static function grow(int $length, ?Budget $budget = null): int
    {
        self::reserve(2 * $length, $budget);
        return 2 * $length;
    }

    /** can $bytes more be allocated now, with RESERVE to spare (without collecting garbage)? */
    public static function fits(int $bytes): bool
    {
        return $bytes <= self::available() - self::RESERVE;
    }

    /** memory PHP may still allocate below memory_limit (PHP_INT_MAX: no limit) */
    public static function available(): int
    {
        $setting = (string) ini_get('memory_limit');
        if ($setting !== self::$limitSetting) {
            self::$limitSetting = $setting;
            self::$limit = (int) @ini_parse_quantity($setting);
        }
        // zend_alloc.c checks memory_limit against the memory its heap holds (real_size)
        return self::$limit <= 0 ? PHP_INT_MAX : self::$limit - memory_get_usage(true);
    }
}
