<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * PHP runs its function JIT with register allocation (opcache.jit
 * "function", 1205, 1105, 1215, 1225, ...), which can repeat loop
 * iterations and so compute wrong results without an error
 * (php-src#23983). Creating a sandbox (newSandbox, run, Lua::run) and
 * Environment::compile throw it before any Lua code is compiled or run.
 * The message names the opcache.jit value and the settings that work:
 * tracing, 1005 (no register allocation) or disable.
 */
final class UnsafeJitSetting extends \RuntimeException
{
    /** @internal */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
