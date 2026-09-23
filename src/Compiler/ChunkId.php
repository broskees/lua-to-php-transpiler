<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Port of lobject.c: luaO_chunkid. Turns a chunk name ("@file", "=name", or
 * source text) into the short, printable 'short_src' used in messages.
 */
final class ChunkId
{
    // luaconf.h: LUA_IDSIZE (includes C's terminating '\0')
    public const LUA_IDSIZE = 60;

    private const RETS = '...';
    private const PRE = '[string "';
    private const POS = '"]';

    // lobject.c: luaO_chunkid
    public static function of(string $source): string
    {
        $bufferLength = self::LUA_IDSIZE;  // free space in buffer
        $sourceLength = strlen($source);

        if ($sourceLength > 0 && $source[0] === '=') {  // 'literal' source
            if ($sourceLength <= $bufferLength) {  // small enough?
                return self::asCString(substr($source, 1));
            }
            return self::asCString(substr($source, 1, $bufferLength - 1));  // truncate it
        }

        if ($sourceLength > 0 && $source[0] === '@') {  // file name
            if ($sourceLength <= $bufferLength) {  // small enough?
                return self::asCString(substr($source, 1));
            }
            // add '...' before rest of name
            $bufferLength -= strlen(self::RETS);
            return self::asCString(self::RETS . substr($source, 1 + $sourceLength - $bufferLength));
        }

        // string; format as [string "source"]
        $newlinePosition = strpos(self::asCString($source), "\n");  // C strchr stops at '\0'
        $bufferLength -= strlen(self::PRE . self::RETS . self::POS) + 1;  // save space for prefix+suffix+'\0'
        if ($sourceLength < $bufferLength && $newlinePosition === false) {  // small one-line source?
            return self::asCString(self::PRE . $source . self::POS);  // keep it
        }
        if ($newlinePosition !== false) {
            $sourceLength = $newlinePosition;  // stop at first newline
        }
        if ($sourceLength > $bufferLength) {
            $sourceLength = $bufferLength;
        }
        return self::asCString(self::PRE . substr($source, 0, $sourceLength) . self::RETS . self::POS);
    }

    /**
     * The C result is a NUL-terminated buffer: anything after an embedded
     * '\0' is invisible to its readers.
     */
    private static function asCString(string $bytes): string
    {
        $nulPosition = strpos($bytes, "\0");
        return $nulPosition === false ? $bytes : substr($bytes, 0, $nulPosition);
    }
}
