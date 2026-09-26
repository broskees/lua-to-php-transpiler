<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Compiler\ChunkId;
use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\Dump;
use LuaPhp\Compiler\Undump;
use LuaPhp\Embed\Loader\Source;
use LuaPhp\Embed\SyntaxError;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Runtime\CacheDirectory;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;

/**
 * @internal
 *
 * An Environment's compiled scripts: a script is compiled (and its PHP
 * emitted and compiled) the first time its exact bytes appear under a
 * chunk name, then reused. The key is the chunk name, the source's
 * sha256 and whether the code counts steps; on disk, CacheDirectory adds
 * the runtime's fingerprint. In memory: at most MEMORY_ENTRY_LIMIT
 * entries, emptied when full; on disk (a cacheDir): generated PHP files
 * loaded with include, trusted and bounded as load()'s cache (LoadCache).
 * Only text is loaded (lua_load with mode "t"). Like LoadCache, a hit
 * undumps fresh Protos (sharing equal strings like the lexer) and is used
 * only where compiling at the current C-call depth would succeed.
 */
final class CompileCache
{
    private const MEMORY_ENTRY_LIMIT = 1000;

    private const DISK_ENTRY_LIMIT = 20000;

    private const DISK_BYTE_LIMIT = 256 * 1024 * 1024;

    /** @var array<string, array{string, int, \Closure}> key => [Protos as a binary chunk, parser nesting, factory] */
    private array $memory = [];

    private readonly ?CacheDirectory $disk;

    public function __construct(?string $cacheDirectory)
    {
        $this->disk = $cacheDirectory === null ? null : new CacheDirectory($cacheDirectory);
    }

    /**
     * lapi.c: lua_load of $source in mode "t" on thread $L: its main
     * function, with _ENV set to the global table. A chunk that does not
     * load is a LuaError (LUA_ERRSYNTAX with Lua's message, or the error
     * the parser raised: "C stack overflow", memory).
     */
    public function load(Coroutine $L, Source $source, bool $countSteps): LuaClosure
    {
        // ldo.c: luaD_protectedparser, as ChunkLoader::load
        [$status, $result] = Calls::protectedRun($L, function () use ($L, $source, $countSteps): LuaClosure {
            $code = $source->code;
            $chunkName = $source->chunkName;
            if ($code !== '' && $code[0] === Undump::LUA_SIGNATURE[0]) {  // ldo.c: checkmode
                throw new LuaError("attempt to load a binary chunk (mode is 't')", Lua::LUA_ERRSYNTAX);
            }
            $key = self::key($source, $countSteps);
            $entry = $this->memory[$key] ?? $this->readEntry($key);
            if ($entry !== null && ChunkLoader::nestingError($L->nCcalls, $entry[1]) === null) {
                return ChunkLoader::instantiate(Undump::undump($entry[0], $chunkName, true), $entry[2]);
            }
            $nesting = 0;
            try {
                $proto = Compiler::compile($code, $chunkName, $L->nCcalls, $nesting);
            } catch (CompileError $error) {
                $errorStatus = match ($error->getCode()) {
                    Lua::LUA_ERRRUN => Lua::LUA_ERRRUN,
                    Lua::LUA_ERRERR => Lua::LUA_ERRERR,
                    default => Lua::LUA_ERRSYNTAX,
                };
                throw new LuaError($error->getMessage(), $errorStatus);
            }
            // TODO(runtime lane): Emitter::emitChunk($proto, countSteps: $countSteps)
            $factorySource = Emitter::emitChunk($proto);
            $factory = ChunkLoader::factoryForSource($factorySource);
            $protos = Dump::dump($proto, false);
            $this->remember($key, [$protos, $nesting, $factory]);
            $this->disk?->write($key, $protos, $nesting, $factorySource, self::DISK_ENTRY_LIMIT, self::DISK_BYTE_LIMIT);
            return ChunkLoader::instantiate($proto, $factory);
        }, $L->errfunc);
        if ($status !== Lua::LUA_OK) {
            throw new LuaError($result, $status);
        }
        $closure = $result;
        if ($closure->proto->upvalues !== []) {  // does it have an upvalue?
            $closure->getUpval(0)->v = $L->globalState->globals;  // set it to the global table
        }
        return $closure;
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

    /** the number of entries in memory */
    public function memoryEntryCount(): int
    {
        return \count($this->memory);
    }

    private static function key(Source $source, bool $countSteps): string
    {
        return hash('sha256', ($countSteps ? 'steps:' : 'plain:') . \strlen($source->chunkName) . ':' . $source->chunkName . $source->fingerprint);
    }

    /** @param array{string, int, \Closure} $entry */
    private function remember(string $key, array $entry): array
    {
        if (\count($this->memory) >= self::MEMORY_ENTRY_LIMIT) {
            $this->memory = [];
        }
        return $this->memory[$key] = $entry;
    }

    /** @return ?array{string, int, \Closure} */
    private function readEntry(string $key): ?array
    {
        $entry = $this->disk?->read($key);
        if ($entry === null || !\is_string($entry[0])) {  // (a text chunk always has its Protos)
            return null;
        }
        return $this->remember($key, $entry);
    }
}
