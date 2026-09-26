<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Compiler\ChunkId;
use LuaPhp\Embed\Loader\Source;
use LuaPhp\Embed\SyntaxError;
use LuaPhp\Runtime\CacheDirectory;
use LuaPhp\Runtime\ChunkCache;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;

/**
 * An Environment's compiled scripts: every state of the Environment has it
 * as its ChunkCache (GlobalState::$chunkCache), so its scripts, modules
 * and load() calls are compiled (and their PHP emitted and compiled) the
 * first time their exact bytes appear under a chunk name, then reused.
 * The key is LoadCache::key's: the chunk name, the bytes and whether the
 * code counts steps (GlobalState::$countSteps); on disk, CacheDirectory
 * adds the runtime's fingerprint. In memory: at most MEMORY_ENTRY_LIMIT
 * entries, emptied when full; on disk (a cacheDir): generated PHP files
 * loaded with include, trusted and bounded as load()'s cache (LoadCache).
 *
 * @internal
 */
final class CompileCache implements ChunkCache
{
    private const MEMORY_ENTRY_LIMIT = 1000;

    private const DISK_ENTRY_LIMIT = 20000;

    private const DISK_BYTE_LIMIT = 256 * 1024 * 1024;

    /** @var array<string, array{?string, int, \Closure}> key => [Protos as a binary chunk, parser nesting, factory] */
    private array $memory = [];

    private readonly ?CacheDirectory $disk;

    public function __construct(?string $cacheDirectory)
    {
        $this->disk = $cacheDirectory === null ? null : new CacheDirectory($cacheDirectory);
    }

    /**
     * lua_load of $source in mode "t" on thread $L (whose state has this
     * cache): its main function, with _ENV set to the global table. A
     * chunk that does not load is a LuaError (LUA_ERRSYNTAX with Lua's
     * message, or the error the parser raised: "C stack overflow", memory).
     */
    public static function load(Coroutine $L, Source $source): LuaClosure
    {
        return ChunkLoader::load($L, $source->code, $source->chunkName, 't');
    }

    /** the SyntaxError for $error, raised loading $source */
    public static function syntaxError(LuaError $error, Source $source): SyntaxError
    {
        $message = \is_string($error->value) ? $error->value : Lua::MEMERRMSG;
        $prefix = ChunkId::of($source->chunkName) . ':';
        $line = null;
        if (str_starts_with($message, $prefix) && preg_match('/^(\d+):/', substr($message, \strlen($prefix)), $match) === 1) {
            $line = (int) $match[1];
        }
        return new SyntaxError($message, $source->chunkName, $line);
    }

    public function find(string $key, int $nCcalls): ?array
    {
        $entry = $this->memory[$key] ?? $this->readEntry($key);
        if ($entry === null || ChunkLoader::nestingError($nCcalls, $entry[1]) !== null) {
            return null;  // (not usable at this depth: compiling it again raises the error)
        }
        return [$entry[0], $entry[2]];
    }

    public function store(string $key, ?string $protos, int $nesting, \Closure $factory, string $factorySource): void
    {
        $this->remember($key, [$protos, $nesting, $factory]);
        $this->disk?->write($key, $protos, $nesting, $factorySource, self::DISK_ENTRY_LIMIT, self::DISK_BYTE_LIMIT);
    }

    /** the number of entries in memory */
    public function memoryEntryCount(): int
    {
        return \count($this->memory);
    }

    /** @param array{?string, int, \Closure} $entry */
    private function remember(string $key, array $entry): array
    {
        if (\count($this->memory) >= self::MEMORY_ENTRY_LIMIT) {
            $this->memory = [];
        }
        return $this->memory[$key] = $entry;
    }

    /** @return ?array{?string, int, \Closure} */
    private function readEntry(string $key): ?array
    {
        $entry = $this->disk?->read($key);
        return $entry === null ? null : $this->remember($key, $entry);
    }
}
