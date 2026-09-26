<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Loader;

/**
 * Lua source code a Loader found: the code, its chunk name ("@" and the
 * loader's name for it, so errors read "discounts/loyalty.lua:8: ..."),
 * and a fingerprint of its exact bytes (sha256).
 */
final class Source
{
    public readonly string $fingerprint;

    public function __construct(
        public readonly string $code,
        public readonly string $chunkName,
    ) {
        $this->fingerprint = hash('sha256', $code);
    }
}
