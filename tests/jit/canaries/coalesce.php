<?php

declare(strict_types=1);

// Canary (a) of tests/unit/TracingJitTest.php, after the reproducer of
// php-src#24186. The loop below runs 1..4 with the body
//     $results[] = $values[$index] ?? null;
// ?? compiles to ZEND_COALESCE, which the trace compiler runs as a call to its
// VM handler: the detector flags the loop under opcache.jit=1254 (tracing with
// register allocation), and interrupts make calls return wrong arrays there.
// Under 1054 (no register allocation, canary (e)) it is not flagged and stays
// right.
// Prints how many of 1,000,000 calls return an array other than [1, 2, 3, 4].

$unpackValues = static function (array $values): array {
    $results = [];
    for ($index = 1; $index <= 4; $index++) {
        $results[] = $values[$index] ?? null;
    }
    return $results;
};

$wrongCalls = 0;
for ($call = 0; $call < 1000000; $call++) {
    if ($unpackValues([1 => 1, 2 => 2, 3 => 3, 4 => 4]) !== [1, 2, 3, 4]) {
        $wrongCalls++;
    }
}
echo $wrongCalls, "\n";
