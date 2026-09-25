<?php

declare(strict_types=1);

namespace Tests\TeardownTest;

/*
 * PHP frees an object's properties recursively on the C stack, so a chain of
 * a million objects would need about 150 MB of it (a coroutine's fiber has
 * far less). The runtime frees such chains one link at a time (see
 * LuaPhp\Runtime\Teardown). tests/diff/teardown_*.lua cover the chains Lua
 * code can build; the ones here go through links Lua cannot create (user
 * values of full userdata, a payload, upvalues set on a native function, the
 * error value of a dead coroutine, a coroutine's CallInfo chain, ...). Each
 * chain is dropped on the main stack and inside a coroutine, in a separate
 * PHP process, so that a crash fails the test instead of ending the run.
 */

const CHAIN_SCRIPT = <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1];

use LuaPhp\Runtime\CallInfo;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Standalone;
use LuaPhp\Runtime\Userdata;

Standalone::configurePhp();
Standalone::raiseMemoryLimit();  // as bin/lua: the longest chains need more than PHP's default
[, , $kind, $length] = $argv;

function buildChain(Coroutine $L, string $kind, int $length): object
{
    $noop = static fn (Coroutine $L, array $args): array => [];
    if ($kind === 'callinfo') {  // one coroutine with a call stack $length frames deep
        $co = Coroutine::newThread($L, null);
        for ($i = 0; $i < $length; $i++) {
            $ci = new CallInfo();
            $ci->previous = $co->ci;
            $ci->R = [$i];
            $co->ci = $ci;
        }
        return $co;
    }
    $chain = new LuaTable();
    for ($i = 0; $i < $length; $i++) {
        switch ($kind) {
            case 'userdata-uservalue':
                $chain = new Userdata(null, [$chain]);
                break;
            case 'userdata-payload':
                $chain = new Userdata($chain);
                break;
            case 'userdata-metatable':
                $userdata = new Userdata();
                $userdata->metatable = new LuaTable();
                $userdata->metatable->hash['__index'] = $chain;
                $chain = $userdata;
                break;
            case 'native-upvalue':
                $chain = new NativeFunction('link', $noop, [$chain]);
                break;
            case 'coroutine-error':  // a dead coroutine keeps the value it died with
                $co = Coroutine::newThread($L, null);
                $co->status = Lua::LUA_ERRRUN;
                $co->errorValue = $chain;
                $chain = $co;
                break;
            case 'table-key':
                $table = new LuaTable();
                $table->set($chain, true);
                $chain = $table;
                break;
            case 'table-cursor':  // next() remembers the last key it returned, even once removed
                $table = new LuaTable();
                $table->set($chain, true);
                $table->next(null);
                $table->set($chain, null);
                $chain = $table;
                break;
            default:
                throw new LogicException("unknown chain kind $kind");
        }
    }
    return $chain;
}

$L = Coroutine::newState();
$chain = buildChain($L, $kind, (int) $length);
unset($chain);
echo "freed on the main stack\n";
$body = new NativeFunction('body', static function (Coroutine $L, array $args) use ($kind, $length): array {
    $chain = buildChain($L, $kind, (int) $length);
    unset($chain);
    return ['freed inside a coroutine'];
});
[$status, $values] = Coroutine::newThread($L, $body)->resume($L, []);
echo $status === Lua::LUA_OK ? $values[0] : "coroutine failed: $values", "\n";
PHP;

function test_deep_chains_lua_cannot_build_are_freed_one_link_at_a_time(): void
{
    $script = scratchDirectory() . '/teardown_chain.php';
    file_put_contents($script, CHAIN_SCRIPT);
    $chains = [
        'userdata-uservalue' => 1000000,
        'userdata-payload' => 1000000,
        'userdata-metatable' => 200000,
        'native-upvalue' => 1000000,
        'coroutine-error' => 200000,
        'callinfo' => 1000000,
        'table-key' => 200000,
        'table-cursor' => 200000,
    ];
    foreach (array_chunk($chains, 4, true) as $batch) {
        $running = [];
        foreach ($batch as $kind => $length) {
            $running[$kind] = startProcess(['php', $script, REPO_ROOT . '/src/autoload.php', $kind, (string) $length], REPO_ROOT);
        }
        foreach ($running as $kind => $process) {
            [$status, $stdout, $stderr] = finishProcess($process);
            assertSame(
                [0, "freed on the main stack\nfreed inside a coroutine\n", ''],
                [$status, $stdout, $stderr],
                "$kind chain",
            );
        }
    }
}
