<?php

declare(strict_types=1);

namespace Tests\CStackGuardTest;

/*
 * PHP throws an Error ("Maximum call stack size of N bytes reached") when
 * PHP code recursing through internal functions exhausts the C stack of the
 * main thread or of a fiber. The runtime turns it into Lua's "C stack
 * overflow" error (lstate.c: luaE_checkcstack), which Lua code can catch,
 * never a crash. A native function here recurses through array_map until
 * the guard fires; the script runs in a separate PHP process.
 */

const GUARD_SCRIPT = <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1];

use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\Standalone;

Standalone::configurePhp();
$L = Standalone::newStateWithLibraries();
$L->globalState->globals->hash['exhaustCStack'] = new NativeFunction('exhaustCStack', static function (Coroutine $L, array $args): array {
    $recurse = static function () use (&$recurse): array {
        return array_map($recurse, [1]);
    };
    $recurse();
    return [];
});
$code = <<<'LUA'
print(pcall(exhaustCStack))
print(coroutine.resume(coroutine.create(function () return pcall(exhaustCStack) end)))
local co = coroutine.create(exhaustCStack)
print(coroutine.resume(co))
print(coroutine.status(co))
print(pcall(string.rep, "x", 3))
LUA;
[$status, $result] = Standalone::docall($L, ChunkLoader::load($L, $code, '=guard', null), []);
Standalone::report('guard', $status, $result);
Standalone::flushStdout();
PHP;

function test_php_c_stack_guard_becomes_a_lua_error(): void
{
    $script = scratchDirectory() . '/c_stack_guard.php';
    file_put_contents($script, GUARD_SCRIPT);
    // (output through files: an uncaught error would print a trace too long for a pipe)
    $result = finishProcess(startProcess(['php', $script, REPO_ROOT . '/src/autoload.php'], REPO_ROOT));
    $result[2] = substr($result[2], 0, 2000);
    assertSame([0, "false\tC stack overflow\ntrue\tfalse\tC stack overflow\nfalse\tC stack overflow\ndead\ntrue\txxx\n", ''], $result);
}
