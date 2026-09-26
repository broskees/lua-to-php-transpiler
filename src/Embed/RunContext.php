<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

use LuaPhp\Embed\Internal\State;

/**
 * What a host gave a sandbox as context: (the current user, a tenant, a
 * repository, ...), for its PHP functions and module factories, without
 * globals. Also charges the run's step limit for expensive PHP work.
 */
final class RunContext
{
    /**
     * @internal
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly array $values,
        private readonly State $state,
    ) {
    }

    /** @throws \OutOfBoundsException when the context has no such value */
    public function get(string $key): mixed
    {
        if (!\array_key_exists($key, $this->values)) {
            throw new \OutOfBoundsException("the run context has no value '$key'");
        }
        return $this->values[$key];
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    /**
     * Counts $steps against the run's step limit (a PHP function called
     * from Lua costs 1 step on its own).
     */
    public function chargeSteps(int $steps): void
    {
        if ($steps < 0) {
            throw new \InvalidArgumentException('steps to charge must be at least 0');
        }
        $this->state->chargeSteps($steps);
    }
}
