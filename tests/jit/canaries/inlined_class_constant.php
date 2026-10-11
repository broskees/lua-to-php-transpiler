<?php

declare(strict_types=1);

// Canary (g) of tests/unit/TracingJitTest.php: the detector must name the
// operation of a loop trace whose IP use is in a function the trace inlines,
// at an address no check places (CanaryWeights::total's loop inlines
// CanaryWeights::weight, whose FETCH_CLASS_CONSTANT runs its VM handler; as
// FunctionEmitter::inlineWeights inlines instructionWeight). Its loop keeps
// no value in a register: not flagged.
// Prints how many of 1,000,000 calls return a total other than 8.

use Tests\TracingJit\Canaries\CanaryWeights;

require __DIR__ . '/CanaryOpcodes.php';
require __DIR__ . '/CanaryWeights.php';

$weights = new CanaryWeights();
$wrongCalls = 0;
for ($call = 0; $call < 1000000; $call++) {
    if ($weights->total([0, 1, 2, 3]) !== 8) {
        $wrongCalls++;
    }
}
echo $wrongCalls, "\n";
