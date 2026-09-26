<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * Code that does not compile. getMessage() is exactly lua5.4's message
 * (e.g. "discounts/loyalty.lua:3: unexpected symbol near '+'"); $chunkName
 * is the chunk's name as given ("@discounts/loyalty.lua", "=inline", or
 * the code itself for Sandbox::load without a name); $luaLine is the
 * line the message names, or null when it names none (a binary chunk).
 * ($line is PHP's own: the line of PHP code that threw.)
 */
final class SyntaxError extends \RuntimeException
{
    /** @internal */
    public function __construct(
        string $message,
        public readonly string $chunkName,
        public readonly ?int $luaLine,
    ) {
        parent::__construct($message);
    }
}
