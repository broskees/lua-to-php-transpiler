<?php

/** The functions and scripts of the embedding benchmark bench/embed.php. */

declare(strict_types=1);

use LuaPhp\Embed\Environment;
use LuaPhp\Embed\Limits;
use LuaPhp\Embed\Loader\ArrayLoader;
use LuaPhp\Embed\RunContext;
use LuaPhp\Embed\ScriptError;

const BENCHMARK = <<<'LUA'
    local s = 0
    for i = 1, 3000000 do s = s + i % 7 end
    local t = {}
    for i = 1, 200000 do t[#t + 1] = tostring(i) end
    return s, #table.concat(t)
    LUA;

const LOYALTY = <<<'LUA'
    local orders = require("shop.orders")
    local cart = ...

    local recent = orders.recent(10)
    print("checked " .. #recent .. " orders")

    if #recent >= 5 then
      return { discount = cart.total * 0.10, reason = "loyal customer (" .. #recent .. " orders)" }
    end
    return { discount = 0, reason = "none" }
    LUA;

function median(array $values): float
{
    sort($values);
    return $values[intdiv(\count($values), 2)];
}

/** median milliseconds per call of $body over $runs runs of $count calls */
function perCall(int $runs, int $count, \Closure $body): float
{
    $times = [];
    for ($run = 0; $run < $runs; $run++) {
        $start = hrtime(true);
        for ($i = 0; $i < $count; $i++) {
            $body();
        }
        $times[] = (hrtime(true) - $start) / 1e6 / $count;
    }
    return median($times);
}

function shop(?string $cacheDirectory): Environment
{
    $environment = new Environment(
        loader: new ArrayLoader(['discounts/loyalty.lua' => LOYALTY, 'benchmark.lua' => BENCHMARK]),
        cacheDir: $cacheDirectory,
        limits: new Limits(steps: 5_000_000, memoryBytes: 32 * 1024 * 1024, seconds: 2.0, outputBytes: 1024 * 1024),
    );
    $environment->addModule('shop.orders', static fn (RunContext $context): array => [
        'recent' => static function (int $limit) use ($context): array {
            if ($limit > 100) {
                throw new ScriptError('orders.recent: limit must be 100 or less');
            }
            return \array_slice($context->get('orders'), 0, $limit);
        },
    ]);
    return $environment;
}
