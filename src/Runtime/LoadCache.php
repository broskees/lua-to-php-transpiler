<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * The byte cache of load() (ChunkLoader::load): compiled chunks by their
 * exact bytes and chunk name, so loading a chunk again compiles and emits
 * nothing. Two levels:
 *
 * - in memory, per process: at most $memoryEntryLimit entries, emptied
 *   when full (like ChunkLoader's factory cache);
 * - on disk, when a directory is set (useDirectory): a CacheDirectory of
 *   generated PHP files, loaded with include, so opcache keeps their
 *   compiled code across requests and the JIT can compile it. See
 *   CacheDirectory for which directories are trusted and how entries are
 *   written and bounded.
 *
 * An entry holds the chunk's Protos as a Lua binary chunk (null for a
 * binary chunk: the chunk itself is one), how deep the parser's nesting
 * went, and the factory of its PHP code (see Emitter). A hit undumps fresh
 * Protos, sharing equal strings as a compile does, so two loads of the
 * same bytes look like two compiles (string.format('%p') tells their long
 * strings apart). A hit is used only if compiling at the current C-call
 * depth would succeed (ChunkLoader::nestingError); failed compiles are
 * never cached. The mode check ('t'/'b') is the caller's.
 *
 * @internal
 */
final class LoadCache
{
    /** at most this many entries in memory; tests lower it */
    public static int $memoryEntryLimit = 1000;

    /** at most this many entries on disk; tests lower it */
    public static int $diskEntryLimit = 20000;

    /** at most this many bytes of entries on disk; tests lower it */
    public static int $diskByteLimit = 256 * 1024 * 1024;

    /** @var array<string, array{?string, int, \Closure}> key => [Protos as a binary chunk, parser nesting, factory] */
    private static array $memory = [];

    /** the disk cache, null for none */
    private static ?CacheDirectory $disk = null;

    /**
     * Sets the disk cache directory (null: no disk cache) and empties the
     * cache in memory.
     */
    public static function useDirectory(?string $directory): void
    {
        self::$disk = $directory === null || $directory === '' ? null : new CacheDirectory($directory);
        self::$memory = [];
    }

    /**
     * Uses the disk cache directory of scripts bin/lua2php generates when
     * LUAPHP_CACHE_DIR is not set: "luaphp-<uid>" in the system's
     * temporary directory (found when first needed).
     */
    public static function useDefaultDirectory(): void
    {
        self::$disk = new CacheDirectory(null);
        self::$memory = [];
    }

    /**
     * the key of $chunk loaded with chunk name $chunkname, compiled with
     * step counting or not (Emitter::emitChunk)
     */
    public static function key(string $chunk, string $chunkname, bool $countSteps = false): string
    {
        $context = hash_init('sha256');  // in pieces: load(s) names the chunk s, which may be big
        if ($countSteps) {
            hash_update($context, 'steps:');
        }
        hash_update($context, \strlen($chunkname) . ':');
        hash_update($context, $chunkname);
        hash_update($context, $chunk);
        return hash_final($context);
    }

    /**
     * The Protos (a binary chunk, or null: the chunk itself) and factory
     * of the chunk with $key, or null: not cached, or compiling it at
     * C-call depth $nCcalls would fail.
     *
     * @return ?array{?string, \Closure}
     */
    public static function find(string $key, int $nCcalls): ?array
    {
        $entry = self::$memory[$key] ?? self::readEntry($key);
        if ($entry === null) {
            return null;
        }
        [$protos, $nesting, $factory] = $entry;
        if (ChunkLoader::nestingError($nCcalls, $nesting) !== null) {
            return null;  // compile it again: that raises the error
        }
        return [$protos, $factory];
    }

    /**
     * Caches the chunk with $key: its Protos as a binary chunk (null for
     * a binary chunk), how deep its parser nesting went (see
     * Compiler::compile), its factory and the factory's PHP source.
     */
    public static function store(string $key, ?string $protos, int $nesting, \Closure $factory, string $factorySource): void
    {
        self::remember($key, [$protos, $nesting, $factory]);
        self::$disk?->write($key, $protos, $nesting, $factorySource, self::$diskEntryLimit, self::$diskByteLimit);
    }

    /** the number of entries in memory */
    public static function memoryEntryCount(): int
    {
        return \count(self::$memory);
    }

    /** @param array{?string, int, \Closure} $entry */
    private static function remember(string $key, array $entry): array
    {
        if (\count(self::$memory) >= self::$memoryEntryLimit) {
            self::$memory = [];
        }
        return self::$memory[$key] = $entry;
    }

    /** @return ?array{?string, int, \Closure} the entry on disk, or null */
    private static function readEntry(string $key): ?array
    {
        $entry = self::$disk?->read($key);
        return $entry === null ? null : self::remember($key, $entry);
    }
}
