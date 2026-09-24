<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\Proto;
use LuaPhp\Compiler\Undump;
use LuaPhp\Emitter\Emitter;

/**
 * Chunks -> Lua functions (lapi.c: lua_load, ldo.c: f_parser/checkmode,
 * lauxlib.c: luaL_loadfilex).
 *
 * Source text goes through Compiler::compile, binary chunks through
 * Undump::undump; the Proto is emitted as PHP and eval'd. PHP never frees
 * eval'd code's runtime caches, so factories are cached by their PHP
 * source: loading the same chunk again reuses the compiled code.
 */
final class ChunkLoader
{
    private const FACTORY_CACHE_LIMIT = 1000;

    /** @var array<string, \Closure> PHP source hash => factory (see Emitter) */
    private static array $factoryCache = [];

    /**
     * lapi.c: lua_load. Returns the main function of $chunk with its first
     * upvalue (_ENV) set to the global table, or throws
     * LuaError(LUA_ERRSYNTAX) with Lua's message.
     */
    public static function load(Coroutine $L, string $chunk, string $chunkname, ?string $mode): LuaClosure
    {
        // ldo.c: luaD_protectedparser: parse in protected mode, with the
        // current message handler for errors the parser raises as runtime
        // errors ("C stack overflow" and the like; PHP compiling the emitted
        // code can exhaust the C stack too, see Calls::cStackOverflowError)
        [$status, $result] = Calls::protectedRun($L, static function () use ($L, $chunk, $chunkname, $mode): LuaClosure {
            try {
                if ($chunk !== '' && $chunk[0] === Undump::LUA_SIGNATURE[0]) {
                    self::checkMode($mode, 'binary');
                    $proto = Undump::undump($chunk, $chunkname);
                } else {
                    self::checkMode($mode, 'text');
                    $proto = Compiler::compile($chunk, $chunkname, $L->nCcalls);
                }
            } catch (CompileError $error) {
                $status = match ($error->getCode()) {
                    Lua::LUA_ERRRUN => Lua::LUA_ERRRUN,
                    Lua::LUA_ERRERR => Lua::LUA_ERRERR,
                    default => Lua::LUA_ERRSYNTAX,
                };
                throw new LuaError($error->getMessage(), $status);
            }
            return self::instantiate($proto);
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

    // ldo.c: checkmode
    private static function checkMode(?string $mode, string $kind): void
    {
        if ($mode !== null && !str_contains(DebugInfo::cString($mode), $kind[0])) {
            throw new LuaError("attempt to load a $kind chunk (mode is '" . DebugInfo::cString($mode) . "')", Lua::LUA_ERRSYNTAX);
        }
    }

    /**
     * A new closure for the main function $proto with fresh closed
     * upvalues (lfunc.c: luaF_initupvals).
     */
    public static function instantiate(Proto $proto, ?\Closure $factory = null): LuaClosure
    {
        $factory ??= self::factoryFor($proto);
        $upvalues = [];
        foreach ($proto->upvalues as $unused) {
            $upvalues[] = UpVal::closed(null);
        }
        return LuaClosure::create($proto, $factory($proto), $upvalues);
    }

    /** the compiled factory for a chunk whose main function is $proto */
    public static function factoryFor(Proto $proto): \Closure
    {
        $source = Emitter::emitChunk($proto);
        $key = hash('xxh128', $source);
        $factory = self::$factoryCache[$key] ?? null;
        if ($factory !== null) {
            return $factory;
        }
        $factory = eval(Emitter::PREAMBLE . "\nreturn " . $source . ";\n");
        if (\count(self::$factoryCache) >= self::FACTORY_CACHE_LIMIT) {
            self::$factoryCache = [];
        }
        self::$factoryCache[$key] = $factory;
        return $factory;
    }

    /**
     * lauxlib.c: luaL_loadfilex: load a file (null: standard input),
     * skipping a UTF-8 BOM and a first line starting with '#'. Errors
     * opening or reading the file are LuaError(LUA_ERRFILE).
     */
    public static function loadFile(Coroutine $L, ?string $filename, ?string $mode): LuaClosure
    {
        if ($filename === null) {
            $chunkname = '=stdin';
            $contents = stream_get_contents(STDIN);
            if ($contents === false) {
                throw new LuaError('cannot read stdin', Lua::LUA_ERRFILE);
            }
        } else {
            $chunkname = '@' . $filename;
            if (is_dir($filename)) {
                // C's fopen succeeds on a directory; the read then fails with EISDIR
                throw new LuaError("cannot read $filename: Is a directory", Lua::LUA_ERRFILE);
            }
            $contents = @file_get_contents($filename);
            if ($contents === false) {
                throw new LuaError("cannot open $filename: " . self::lastSystemError(), Lua::LUA_ERRFILE);
            }
        }
        // lauxlib.c: skipBOM (an incomplete BOM keeps its first character, forcing an error)
        $position = 0;
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $position = 3;
        }
        $prefix = '';
        // lauxlib.c: skipcomment (first line starting with '#')
        if (($contents[$position] ?? '') === '#') {
            $newlinePosition = strpos($contents, "\n", $position);
            $position = $newlinePosition === false ? \strlen($contents) : $newlinePosition + 1;
            $prefix = "\n";  // add newline to correct line numbers
        }
        $chunk = substr($contents, $position);
        if (($chunk[0] ?? '') === Undump::LUA_SIGNATURE[0]) {  // binary file?
            $prefix = '';  // remove possible newline
        }
        return self::load($L, $prefix . $chunk, $chunkname, $mode);
    }

    /** the OS error text of PHP's last warning ("...: No such file or directory") */
    private static function lastSystemError(): string
    {
        $lastError = error_get_last()['message'] ?? '';
        $separatorPosition = strrpos($lastError, ': ');
        return $separatorPosition === false ? $lastError : substr($lastError, $separatorPosition + 2);
    }
}
