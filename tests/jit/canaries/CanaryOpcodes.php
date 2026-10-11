<?php

declare(strict_types=1);

namespace Tests\TracingJit\Canaries;

/**
 * Constants of canary (g), tests/jit/canaries/inlined_class_constant.php:
 * in a file of their own, so that CanaryWeights reads them at run time
 * (FETCH_CLASS_CONSTANT), as FunctionEmitter reads OpCodes'.
 */
final class CanaryOpcodes
{
    public const CALL = 1;
    public const VARARG = 2;
    public const CONCAT = 3;
}
