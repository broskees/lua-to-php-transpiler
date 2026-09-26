<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Where load() (ChunkLoader::load) keeps compiled chunks for a state that
 * has its own cache (GlobalState::$chunkCache: an embedding
 * Environment's); every other state uses LoadCache. Keys are
 * LoadCache::key's; entries and the nesting rule are LoadCache's.
 *
 * @internal
 */
interface ChunkCache
{
    /**
     * The Protos (a binary chunk, or null: the chunk itself) and factory
     * of the chunk with $key, or null: not cached, or compiling it at
     * C-call depth $nCcalls would fail (ChunkLoader::nestingError).
     *
     * @return ?array{?string, \Closure}
     */
    public function find(string $key, int $nCcalls): ?array;

    /** caches a chunk that compiled: see LoadCache::store */
    public function store(string $key, ?string $protos, int $nesting, \Closure $factory, string $factorySource): void;
}
