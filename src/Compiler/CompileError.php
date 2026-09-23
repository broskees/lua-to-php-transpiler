<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * A chunk failed to load: syntax error in source, or a bad binary chunk.
 * The message is exactly Lua's (e.g. `[string "x = +"]:1: unexpected symbol
 * near '+'` or `binary string: bad binary format (truncated chunk)`), ready
 * for load() to return as `nil, msg`.
 */
final class CompileError extends \RuntimeException
{
}
