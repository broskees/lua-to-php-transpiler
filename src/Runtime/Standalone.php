<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Lib\StandardLibraries;
use LuaPhp\Runtime\Gc\Collector;

/**
 * Pieces of lua.c (the stand-alone interpreter) shared by bin/lua and the
 * scripts bin/lua2php generates: process setup, the message handler that
 * appends a traceback, protected calls and error reporting.
 */
final class Standalone
{
    /**
     * PHP settings for running Lua: no PHP warning may leak into Lua's
     * output (they become exceptions, i.e. crashes that show the bug),
     * exception traces must not keep Lua values alive, deep recursion
     * needs memory, and stdout is buffered like C's stdio.
     */
    public static function configurePhp(): void
    {
        ini_set('zend.exception_ignore_args', '1');
        ini_set('memory_limit', '4G');
        ini_set('serialize_precision', '-1');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;  // silenced with @
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        ob_start(null, 8192);
    }

    /** C stack for runOnLargeStack (virtual; pages are committed only when used) */
    private const LARGE_STACK_BYTES = 1024 * 1024 * 1024;

    /**
     * Run $body on a PHP Fiber with a large C stack and return its result.
     * PHP frees nested data recursively on the C stack (a Lua linked list
     * of a million nodes nests a million levels deep), so a Lua session
     * runs, and drops its state, here rather than on the process's stack.
     */
    public static function runOnLargeStack(\Closure $body): mixed
    {
        $previousStackSize = ini_get('fiber.stack_size');
        $restoreStackSize = static function () use ($previousStackSize): void {
            if ($previousStackSize === false || $previousStackSize === '') {
                ini_restore('fiber.stack_size');  // no explicit setting: PHP's default
            } else {
                ini_set('fiber.stack_size', $previousStackSize);
            }
        };
        $fiber = new \Fiber(static function () use ($body, $restoreStackSize): mixed {
            $restoreStackSize();  // this fiber's stack exists now; later fibers get the usual size
            return $body();
        });
        ini_set('fiber.stack_size', (string) self::LARGE_STACK_BYTES);  // read when the fiber starts
        $fiber->start();
        return $fiber->getReturn();
    }

    /** lauxlib.c: luaL_newstate + linit.c: luaL_openlibs */
    public static function newStateWithLibraries(): Coroutine
    {
        $L = Coroutine::newState();
        StandardLibraries::openAll($L);
        return $L;
    }

    /**
     * lua.c: createargtable: arg[i - script] = argv[i].
     *
     * @param list<string> $argv
     */
    public static function createArgTable(Coroutine $L, array $argv, int $script): void
    {
        $table = new LuaTable(max(0, \count($argv) - ($script + 1)));
        foreach ($argv as $index => $argument) {
            $table->arr[$index - $script] = $argument;
        }
        $L->globalState->globals->hash['arg'] = $table;
    }

    /** lua.c: msghandler: add a traceback to the error message */
    public static function messageHandler(): NativeFunction
    {
        return new NativeFunction('msghandler', static function (Coroutine $L, array $args): array {
            $value = $args[0] ?? null;
            $message = LuaObject::toStringCoerced($value);
            if ($message === null) {  // is error object not a string?
                [$called, $result] = Auxiliary::callMeta($L, $value, '__tostring');
                if ($called && \is_string($result)) {  // does it have a metamethod that produces a string?
                    return [$result];  // that is the message
                }
                $message = '(error object is a ' . Auxiliary::argumentTypeName($args, 1) . ' value)';
            }
            return [Auxiliary::traceback($L, $L, $message, 1)];  // append a standard traceback
        });
    }

    /**
     * lua.c: docall: call $function with the traceback message handler.
     * Returns [status, results or error value].
     *
     * @param list<mixed> $arguments
     * @return array{int, mixed}
     */
    public static function docall(Coroutine $L, mixed $function, array $arguments): array
    {
        return Calls::protectedCall($L, $function, $arguments, self::messageHandler());
    }

    /** lua.c: l_message */
    public static function message(?string $programName, string $message): void
    {
        self::flushStdout();
        if ($programName !== null) {
            fwrite(STDERR, "$programName: ");
        }
        fwrite(STDERR, "$message\n");
    }

    /** lua.c: report: print a non-OK status's message */
    public static function report(?string $programName, int $status, mixed $value): int
    {
        if ($status !== Lua::LUA_OK) {
            $message = LuaObject::toStringCoerced($value) ?? '(error message not a string)';
            self::message($programName, $message);
        }
        return $status;
    }

    /**
     * lua.c: dochunk: run the chunk $loader returns, in protected mode with
     * the traceback handler; report errors (loading or running).
     */
    public static function doChunk(Coroutine $L, string $programName, \Closure $loader): int
    {
        try {
            $function = $loader();
        } catch (LuaError $error) {
            return self::report($programName, $error->status, $error->value);
        }
        [$status, $result] = self::docall($L, $function, []);
        return self::report($programName, $status, $result);
    }

    /** lua.c: handle_luainit: run LUA_INIT_5_4 or LUA_INIT ("@file" or code) */
    public static function handleLuaInit(Coroutine $L, string $programName): int
    {
        $name = '=LUA_INIT_5_4';
        $init = getenv('LUA_INIT_5_4');
        if ($init === false) {
            $name = '=LUA_INIT';
            $init = getenv('LUA_INIT');  // try alternative name
        }
        if ($init === false) {
            return Lua::LUA_OK;
        }
        if (str_starts_with($init, '@')) {
            $filename = substr($init, 1);
            return self::doChunk($L, $programName, static fn (): LuaClosure => ChunkLoader::loadFile($L, $filename, null));
        }
        return self::doChunk($L, $programName, static fn (): LuaClosure => ChunkLoader::load($L, $init, $name, null));
    }

    /**
     * lua.c: pushargs: the contents of table 'arg' from 1 to #arg.
     *
     * @return list<mixed>
     */
    public static function scriptArguments(Coroutine $L): array
    {
        $argTable = $L->globalState->globals->hash['arg'] ?? null;
        if (!($argTable instanceof LuaTable)) {
            Auxiliary::error($L, "'arg' is not a table");
        }
        $count = Auxiliary::len($L, $argTable);
        Auxiliary::checkStack($L, $count + 3, 'too many arguments to script');
        $arguments = [];
        for ($i = 1; $i <= $count; $i++) {
            $arguments[] = $argTable->get($i);
        }
        return $arguments;
    }

    /**
     * lua.c: handle_script: load the script with $loader, call it with the
     * script arguments, report errors.
     */
    public static function handleScript(Coroutine $L, string $programName, \Closure $loader): int
    {
        try {
            $function = $loader();
            $arguments = self::scriptArguments($L);
        } catch (LuaError $error) {
            return self::report($programName, $error->status, $error->value);
        }
        [$status, $result] = self::docall($L, $function, $arguments);
        return self::report($programName, $status, $result);
    }

    /**
     * lua.c: main + pmain for a script compiled ahead of time by
     * bin/lua2php: behaves like 'lua <script> args...'. $argv is PHP's
     * ($argv[0] is the PHP script); in 'arg', index 0 is $scriptName and
     * -1 is the PHP script. Returns the process exit status.
     *
     * @param list<string> $argv
     */
    public static function runCompiledScript(array $argv, string $scriptName, \Closure $loadScript): int
    {
        return self::runOnLargeStack(static function () use ($argv, $scriptName, $loadScript): int {
            $programName = $argv[0] ?? 'lua';
            $L = Coroutine::newState();
            $L->globalState->gcstp = Collector::GCSTPUSR;  // lua_gc(L, LUA_GCSTOP): stop GC while building state
            $pmain = new NativeFunction('pmain', static function (Coroutine $L, array $args) use ($argv, $programName, $scriptName, $loadScript): array {
                StandardLibraries::openAll($L);
                $luaArgv = array_merge([$argv[0] ?? 'lua', $scriptName], \array_slice($argv, 1));
                self::createArgTable($L, $luaArgv, 1);
                Collector::restart($L->globalState);  // lua.c: lua_gc(L, LUA_GCRESTART): start GC...
                Collector::changeMode($L, Collector::KGC_GEN);  // ...in generational mode
                if (self::handleLuaInit($L, $programName) !== Lua::LUA_OK) {
                    return [false];
                }
                return [self::handleScript($L, $programName, static fn (): LuaClosure => $loadScript($L)) === Lua::LUA_OK];
            });
            [$status, $result] = Calls::protectedCall($L, $pmain, []);
            self::report($programName, $status, $result);
            Collector::closeState($L);  // lua_close
            self::flushStdout();
            $succeeded = $status === Lua::LUA_OK && $result[0];
            unset($L, $pmain, $result);  // free the Lua state on this stack
            return $succeeded ? 0 : 1;
        });
    }

    public static function flushStdout(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_start(null, 8192);
    }
}
