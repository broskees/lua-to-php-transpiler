<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Mirrors C 'Token' in llex.h. 'seminfo' (C union SemInfo) holds the
 * string of a TK_NAME/TK_STRING, the int of a TK_INT or the float of a
 * TK_FLT; null for other tokens.
 */
final class Token
{
    public int $token = 0;

    public int|float|string|null $seminfo = null;
}
