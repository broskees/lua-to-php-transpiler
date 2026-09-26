<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * The limits of each run in a sandbox; null is no limit. Going over one is
 * LimitExceeded, except call depth, which is Lua's own catchable "stack
 * overflow" error.
 *
 * - steps: roughly Lua instructions, plus what library and PHP functions
 *   charge (RunContext::chargeSteps);
 * - memoryBytes: PHP memory growth since the run started (never more than
 *   what memory_limit leaves);
 * - seconds: wall-clock time, time in PHP functions included;
 * - outputBytes: bytes printed;
 * - coroutines: coroutines alive at once (each is a PHP Fiber);
 * - callDepth: Lua call levels.
 */
final class Limits
{
    public function __construct(
        public readonly ?int $steps = null,
        public readonly ?int $memoryBytes = null,
        public readonly ?float $seconds = null,
        public readonly ?int $outputBytes = null,
        public readonly ?int $coroutines = 1000,
        public readonly ?int $callDepth = 1000,
    ) {
        foreach (['steps' => $steps, 'memoryBytes' => $memoryBytes, 'seconds' => $seconds, 'outputBytes' => $outputBytes, 'coroutines' => $coroutines, 'callDepth' => $callDepth] as $name => $limit) {
            if ($limit !== null && (is_nan((float) $limit) || $limit < 0)) {
                throw new \InvalidArgumentException("Limits: $name must be null or at least 0");
            }
        }
    }
}
