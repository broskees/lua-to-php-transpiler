<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * Thrown by a PHP function called from Lua: a normal Lua error, which the
 * script's pcall can catch. new ScriptError('message') raises the message
 * with the position of the calling Lua code, as luaL_error does
 * ("discounts/loyalty.lua:4: message"); ScriptError::value($value) raises
 * $value (converted to Lua) as the error value. Any other exception a PHP
 * function throws stops the run instead (see Sandbox).
 */
final class ScriptError extends \Exception
{
    private bool $raisesValue = false;

    private mixed $errorValue = null;

    public static function value(mixed $value): self
    {
        $error = new self('Lua error value of type ' . get_debug_type($value));
        $error->raisesValue = true;
        $error->errorValue = $value;
        return $error;
    }

    /** whether this error raises a value (ScriptError::value) rather than its message */
    public function hasValue(): bool
    {
        return $this->raisesValue;
    }

    /** the value ScriptError::value raises (null for a message) */
    public function getValue(): mixed
    {
        return $this->errorValue;
    }
}
