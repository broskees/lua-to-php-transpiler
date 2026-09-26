<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A limit of a state's Budget was reached: 'steps', 'memory', 'seconds',
 * 'output' or 'coroutines'. Not a Lua error (neither LuaError nor \Error):
 * pcall, xpcall's handler, coroutine.resume and wrap, '__close' and '__gc'
 * never see it, so no more Lua code runs once it is thrown; it reaches
 * whoever called into Lua. The state is then unusable (its frames were
 * abandoned without unwinding; see Gc\Collector::abandonState).
 *
 * @internal
 */
final class LimitReached extends \Exception
{
    public function __construct(
        public readonly string $limit,
    ) {
        parent::__construct("$limit limit reached");
    }
}
