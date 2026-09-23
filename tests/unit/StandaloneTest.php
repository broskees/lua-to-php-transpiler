<?php

declare(strict_types=1);

namespace Tests\StandaloneTest;

use LuaPhp\Runtime\Standalone;

/*
 * Standalone::runOnLargeStack runs a Lua session on a Fiber with a large
 * C stack; Fibers created inside it (coroutines) must still work.
 */

function test_fibers_created_inside_the_large_stack_work(): void
{
    $result = Standalone::runOnLargeStack(static function (): string {
        $inner = new \Fiber(static function (): string {
            return 'inner ran';
        });
        $inner->start();
        return $inner->getReturn();
    });
    assertSame('inner ran', $result);
    $after = new \Fiber(static fn (): int => 42);
    $after->start();
    assertSame(42, $after->getReturn(), 'fibers created afterwards work too');
}
