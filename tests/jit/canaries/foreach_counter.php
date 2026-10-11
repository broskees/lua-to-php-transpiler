<?php

declare(strict_types=1);

// Canary (f) of tests/unit/TracingJitTest.php: php-src#24186 without ?? or
// ?:. The loop below is a foreach with a counter, as LuaTable::fromList
// has: FE_FETCH_R stores EX(opline) from the IP the trace keeps track of,
// which gives the loop trace the interrupt stub too, and $key lives in a
// register. The detector flags it under opcache.jit=1254, and interrupts
// make calls return wrong arrays there.
// Prints how many of 1,000,000 calls return an array other than
// [1 => 10, 2 => 20, 3 => 30, 4 => 40].

$fromList = static function (array $values): array {
    $result = [];
    $key = 1;
    foreach ($values as $value) {
        $result[$key] = $value;
        $key++;
    }
    return $result;
};

$wrongCalls = 0;
for ($call = 0; $call < 1000000; $call++) {
    if ($fromList([10, 20, 30, 40]) !== [1 => 10, 2 => 20, 3 => 30, 4 => 40]) {
        $wrongCalls++;
    }
}
echo $wrongCalls, "\n";
