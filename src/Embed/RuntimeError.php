<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * A Lua error no pcall caught. getMessage() is $luaMessage.
 *
 * - $luaMessage: the error value if it is a string or a number, else what
 *   tostring() makes of it ('__tostring' included);
 * - $value: the error value converted to PHP as results are (a table that
 *   cannot be converted stays a LuaTable handle);
 * - $traceback: Lua's "stack traceback:" text from where the error was
 *   raised (as lua5.4 prints it, without the "[C]: in ?" of lua.c's own
 *   frame), or '' for errors that run no message handler (memory errors,
 *   errors in error handling).
 *
 * The sandbox stays usable.
 */
final class RuntimeError extends \RuntimeException
{
    /** @internal */
    public function __construct(
        public readonly string $luaMessage,
        public readonly mixed $value,
        public readonly string $traceback,
    ) {
        parent::__construct($luaMessage);
    }
}
