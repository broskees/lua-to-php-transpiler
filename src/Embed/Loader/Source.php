<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Loader;

/**
 * Lua source code a Loader found: the code, its chunk name ("@" and the
 * loader's name for it, so errors read "discounts/loyalty.lua:8: ..."),
 * and a fingerprint of its exact bytes (sha256, computed when asked).
 */
final class Source
{
    public string $fingerprint {
        get => hash('sha256', $this->code);
    }

    public function __construct(
        public readonly string $code,
        public readonly string $chunkName,
    ) {
    }
}
