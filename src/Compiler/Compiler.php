<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * The only way Lua source becomes a Proto: the front-end ported from
 * llex.c, lparser.c and lcode.c (see Lexer, Parser, CodeGen).
 */
final class Compiler
{
    /**
     * C-call depth (lstate.h getCcalls) at which lua5.4 parses the argument
     * of load() called from a main chunk run by lua.c: the parser's nesting
     * limit (LUAI_MAXCCALLS) counts from here.
     */
    public const MAIN_CHUNK_C_CALLS = 2;

    /**
     * Like lua_load + luaY_parser: compile $source (text, not a binary
     * chunk; nothing is skipped) with chunk name $chunkname ("@file",
     * "=stdin", or the source itself for load()). The main function is
     * vararg with one upvalue, _ENV (instack, index 0).
     *
     * $nCcalls is the caller's C-call depth; the parser raises "C stack
     * overflow" when nesting takes it to LUAI_MAXCCALLS (200).
     *
     * @throws CompileError with Lua's exact message. Code 3 (LUA_ERRSYNTAX)
     *         for syntax errors, e.g. `[string "x = +"]:1: unexpected symbol
     *         near '+'`. Code 2 (LUA_ERRRUN) for errors the parser raises
     *         with luaG_runerror ("C stack overflow", "too many local
     *         variables (limit is 32767)"): they carry no position, and in
     *         lua5.4 the current message handler (e.g. lua.c's traceback)
     *         is applied to them before load() returns. Code 5
     *         (LUA_ERRERR) for "error in error handling": nesting reached
     *         LUAI_MAXCCALLS / 10 * 11 while $nCcalls was already past the
     *         limit (parsing inside the handler of a C stack overflow).
     *
     * $nesting is set, also when compiling fails, to how many levels above
     * $nCcalls the parser's nesting went: the result depends on $nCcalls
     * only through that (see ChunkLoader::nestingError).
     */
    public static function compile(string $source, string $chunkname, int $nCcalls = self::MAIN_CHUNK_C_CALLS, ?int &$nesting = null): Proto
    {
        return Parser::luaY_parser($source, $chunkname, $nCcalls, $nesting);
    }
}
