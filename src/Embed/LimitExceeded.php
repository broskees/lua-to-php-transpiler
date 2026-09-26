<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * A run went over one of its Limits: $limit is 'steps', 'memory',
 * 'seconds', 'output' or 'coroutines'. (Call depth stays Lua's own
 * catchable "stack overflow" error.) No Lua code runs after it: no pcall,
 * message handler, '__close' or '__gc' sees it, and the sandbox is closed.
 */
final class LimitExceeded extends \RuntimeException
{
    /** @internal */
    public function __construct(
        public readonly string $limit,
        public readonly Usage $usage,
    ) {
        parent::__construct("the Lua script exceeded its $limit limit");
    }
}
