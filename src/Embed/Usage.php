<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/** What a run used, reported whether it succeeded or not (Result, LimitExceeded). */
final class Usage
{
    public function __construct(
        public readonly int $steps,
        public readonly int $peakMemoryBytes,
        public readonly float $milliseconds,
        public readonly int $outputBytes,
    ) {
    }
}
