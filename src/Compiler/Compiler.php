<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * The only way Lua source becomes a Proto.
 *
 * !!! TEMPORARY BRIDGE — REPLACED IN PHASE 1A !!!
 * This shells out to the reference compiler `luac5.4` and undumps its
 * output. Phase 1A replaces it with a real front-end (ports of llex.c,
 * lparser.c, lcode.c). No other code may call luac5.4.
 */
final class Compiler
{
    /**
     * Like luaL_loadbuffer + luaY_parser: compile $source (text, not a
     * binary chunk) with chunk name $chunkname ("@file", "=stdin", or the
     * source itself for load()).
     *
     * @throws CompileError with Lua's exact message, e.g.
     *         `[string "x = +"]:1: unexpected symbol near '+'`
     */
    public static function compile(string $source, string $chunkname): Proto
    {
        $sourceFile = tempnam(sys_get_temp_dir(), 'luaphp');
        if ($sourceFile === false) {
            throw new \RuntimeException('Compiler bridge: cannot create a temporary file');
        }
        $bytecodeFile = $sourceFile . '.luac';
        try {
            // luac5.4 reads files with luaL_loadfile, which skips a leading
            // BOM or '#' line and treats a leading ESC as a binary chunk.
            // luaL_loadbuffer does none of that. A leading space defeats all
            // three without changing tokens or line numbers.
            file_put_contents($sourceFile, ' ' . $source);

            $process = proc_open(
                ['luac5.4', '-o', $bytecodeFile, $sourceFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if ($process === false) {
                throw new \RuntimeException('Compiler bridge: cannot run luac5.4');
            }
            stream_get_contents($pipes[1]);
            $luacErrorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $temporaryChunkname = '@' . $sourceFile;
            if ($exitCode !== 0) {
                throw new CompileError(self::rewriteLuacError($luacErrorOutput, $temporaryChunkname, $chunkname));
            }

            $mainProto = Undump::undump(file_get_contents($bytecodeFile), $temporaryChunkname);
            self::renameSource($mainProto, $temporaryChunkname, $chunkname);
            return $mainProto;
        } finally {
            @unlink($sourceFile);
            @unlink($bytecodeFile);
        }
    }

    /**
     * luac5.4 reports "luac5.4: <short_src of temp file>:<line>: <msg>".
     * Lua reports "<short_src of chunkname>:<line>: <msg>".
     */
    private static function rewriteLuacError(string $luacErrorOutput, string $temporaryChunkname, string $chunkname): string
    {
        $message = rtrim($luacErrorOutput, "\n");
        $luacPrefix = 'luac5.4: ';
        if (str_starts_with($message, $luacPrefix)) {
            $message = substr($message, strlen($luacPrefix));
        }
        $temporaryShortSource = ChunkId::of($temporaryChunkname);
        if (str_starts_with($message, $temporaryShortSource . ':')) {
            $message = ChunkId::of($chunkname) . substr($message, strlen($temporaryShortSource));
        }
        return $message;
    }

    private static function renameSource(Proto $proto, string $temporaryChunkname, string $chunkname): void
    {
        if ($proto->source === $temporaryChunkname) {
            $proto->source = $chunkname;
        }
        foreach ($proto->p as $childProto) {
            self::renameSource($childProto, $temporaryChunkname, $chunkname);
        }
    }
}
