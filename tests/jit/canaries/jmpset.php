<?php

declare(strict_types=1);

// Canary (b) of tests/unit/TracingJitTest.php, after the reproducer of
// php-src#24186. The loop below runs 1..4 with the body
//     $results[] = $values[$index] ?: null;
// ?: compiles to ZEND_JMP_SET, a call to its VM handler too: flagged under
// opcache.jit=1254.
// Prints how many of 1,000,000 calls return an array other than [1, 2, 3, 4].

$unpackValues = static function (array $values): array {
    $results = [];
    for ($index = 1; $index <= 4; $index++) {
        $results[] = $values[$index] ?: null;
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
