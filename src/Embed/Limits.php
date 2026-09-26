<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * The limits of each run in a sandbox; null is no limit. Going over one is
 * LimitExceeded, except call depth, which is Lua's own catchable "stack
 * overflow" error. Steps, memory, seconds and call depth are counted by
 * code compiled for it (the Environment compiles so when any of them is
 * set); with Limits::none() scripts run exactly as fast as bin/lua runs
 * them, and Usage reports only time and output.
 *
 * - steps: roughly Lua instructions, plus what library and PHP functions
 *   charge (RunContext::chargeSteps);
 * - memoryBytes: PHP memory growth since the run started (never more than
 *   what memory_limit leaves);
 * - seconds: wall-clock time, time in PHP functions included;
 * - outputBytes: bytes printed;
 * - coroutines: coroutines alive at once (each is a PHP Fiber, with the
 *   host's fiber.stack_size of address space: PHP's default 2 MB, so
 *   1000 coroutines reserve 2 GB of it, though only touched pages cost
 *   memory; Linux allows about 65530 mappings per process, 2 per fiber);
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
        // (NAN >= 0 can be true in PHP: is_nan first)
        foreach (['steps' => $steps, 'memoryBytes' => $memoryBytes, 'seconds' => $seconds, 'outputBytes' => $outputBytes, 'coroutines' => $coroutines, 'callDepth' => $callDepth] as $name => $limit) {
            if ($limit !== null && (is_nan((float) $limit) || $limit < 0)) {
                throw new \InvalidArgumentException("Limits: $name must be null or at least 0");
            }
        }
    }

    /** no limits at all */
    public static function none(): self
    {
        return new self(coroutines: null, callDepth: null);
    }

    /**
     * @internal
     * whether code must count steps: steps, memory, seconds and call depth are checked by it
     */
    public function needStepCounting(): bool
    {
        return $this->steps !== null || $this->memoryBytes !== null || $this->seconds !== null || $this->callDepth !== null;
    }
}
