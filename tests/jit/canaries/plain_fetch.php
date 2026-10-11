<?php

declare(strict_types=1);

// Canary (c) of tests/unit/TracingJitTest.php, after the reproducer of
// php-src#24186. The loop below runs 1..4 with the body
//     $results[] = $values[$index];
// every opcode of the loop has inline code in the trace compiler: not flagged
// under opcache.jit=1254 (its interrupt check writes the registers back), and
// it stays right under interrupts.
// Prints how many of 1,000,000 calls return an array other than [1, 2, 3, 4].

$unpackValues = static function (array $values): array {
    $results = [];
    for ($index = 1; $index <= 4; $index++) {
        $results[] = $values[$index];
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
