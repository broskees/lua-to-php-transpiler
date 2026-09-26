<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Embed\ConversionError;
use LuaPhp\Embed\Loader\Loader;
use LuaPhp\Embed\Loader\Source;
use LuaPhp\Embed\RunContext;
use LuaPhp\Embed\RuntimeError;
use LuaPhp\Embed\SandboxClosed;
use LuaPhp\Embed\ScriptError;
use LuaPhp\Lib\StandardLibraries;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable as RuntimeTable;
use LuaPhp\Runtime\NativeFunction;

/**
 * @internal
 *
 * One sandbox's Lua state (Sandbox is its public face; handles and PHP
 * functions hold this).
 *
 * Calls into Lua (call, compile) run on the thread that is running this
 * sandbox's Lua code when a PHP function called from it calls back
 * (runningThread), else on the main thread, as protected calls whose
 * message handler takes the traceback. A Lua error there is a
 * RuntimeError; any other exception stops the sandbox (it is closed, and
 * the exception goes on unchanged: no pcall, message handler or '__close'
 * sees it, as none catches it) and so does a LimitExceeded.
 *
 * PHP functions called from Lua run through callHost: a ScriptError is a
 * Lua error; a RuntimeError of this sandbox that a PHP function let
 * through goes on as the Lua error it was (as lua_call's would); anything
 * else stops the run.
 *
 * Handles register their values in a table the collector marks
 * ($G->libraryState), as luaL_ref would, so a value PHP holds stays alive
 * for Lua too (weak tables, finalizers).
 */
final class State
{
    public ?Coroutine $L;

    public readonly RunContext $context;

    /** the thread whose Lua code called the PHP function running now, if any */
    public ?Coroutine $runningThread = null;

    /** the exception that stopped a run, closing the sandbox */
    public ?\Throwable $stoppedBy = null;

    public bool $closed = false;

    /** handle number => value (see anchor) */
    private ?RuntimeTable $anchors;

    private int $nextAnchor = 1;

    /** @var \WeakMap<RuntimeError, array{mixed, int}> errors this sandbox raised to PHP: [Lua error value, status] */
    private \WeakMap $raisedErrors;

    /** @var \WeakMap<\Closure, NativeFunction> the Lua function of each PHP Closure passed to this sandbox */
    private \WeakMap $hostFunctions;

    /**
     * @param array<string, array<mixed>|\Closure> $modules
     * @param array<string, mixed> $globals
     * @param list<string> $libraries
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly CompileCache $cache,
        private readonly ?Loader $loader,
        private readonly array $modules,
        array $globals,
        array $libraries,
        bool $allowLoad,
        private readonly bool $countSteps,
        array $context,
    ) {
        $this->raisedErrors = new \WeakMap();
        $this->hostFunctions = new \WeakMap();
        $this->context = new RunContext($context, $this);
        $L = Coroutine::newState();
        $this->L = $L;
        // TODO(runtime lane): StandardLibraries::openSelected($L, $libraries, sandboxed: $libraries !== Libraries::ALL, allowLoad: $allowLoad)
        StandardLibraries::openAll($L);
        $this->anchors = new RuntimeTable();
        $L->globalState->libraryState['embed.handles'] = $this->anchors;
        $this->openRequire($L);
        foreach ($globals as $name => $value) {
            $L->globalState->globals->set($name, Convert::toLua($value, $name, $this));
        }
    }

    public function ensureOpen(): void
    {
        if (!$this->closed) {
            return;
        }
        if ($this->stoppedBy === null) {
            throw new SandboxClosed('the sandbox is closed');
        }
        throw new SandboxClosed('the sandbox is closed: a ' . $this->stoppedBy::class . ' stopped a run in it', 0, $this->stoppedBy);
    }

    /** closes the sandbox: no more Lua code runs in it (pending '__gc' finalizers are skipped) */
    public function close(): void
    {
        $this->closed = true;
        // TODO(runtime lane): abandon the state without running Lua code
        $this->L = null;
        $this->anchors = null;
    }

    /** counts $steps against the run's step limit */
    public function chargeSteps(int $steps): void
    {
        // TODO(runtime lane): $this->L->globalState->budget?->chargeSteps($steps)
    }

    /** the Source of script $name from the Environment's loader */
    public function source(string $name): Source
    {
        if ($this->loader === null) {
            throw new \LogicException("cannot run '$name': the Environment has no loader");
        }
        return $this->loader->getSource($name);
    }

    /** $source's main function; a chunk that does not compile is a SyntaxError */
    public function compile(Source $source): LuaClosure
    {
        $this->ensureOpen();
        $L = $this->runningThread ?? $this->L;
        try {
            return $this->enter(fn (): LuaClosure => $this->cache->load($L, $source, $this->countSteps));
        } catch (LuaError $error) {
            throw CompileCache::syntaxError($error, $source);
        }
    }

    /**
     * Calls Lua function $function with PHP values $arguments; returns its
     * results as PHP values.
     *
     * @param array<mixed> $arguments
     * @return list<mixed>
     */
    public function callWithPhpValues(mixed $function, array $arguments): array
    {
        $this->ensureOpen();
        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException('Lua functions take a list of arguments (no names)');
        }
        $luaArguments = [];
        foreach ($arguments as $index => $argument) {
            $luaArguments[] = Convert::toLua($argument, "args[$index]", $this);
        }
        $values = [];
        foreach ($this->call($function, $luaArguments) as $index => $result) {
            $values[] = Convert::toPhp($result, 'result[' . ($index + 1) . ']', $this);
        }
        return $values;
    }

    /**
     * Calls Lua value $function with Lua values $arguments in protected
     * mode; returns its results, or throws the RuntimeError of the Lua
     * error it raised.
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    public function call(mixed $function, array $arguments): array
    {
        $this->ensureOpen();
        $L = $this->runningThread ?? $this->L;
        $failure = null;  // [error value, message, traceback], from the message handler
        $messageHandler = new NativeFunction('messageHandler', static function (Coroutine $L, array $args) use (&$failure): array {
            $value = $args[0] ?? null;
            $failure = [$value, self::errorMessage($L, $value), Auxiliary::traceback($L, $L, null, 1)];
            return [$value];
        });
        [$status, $result] = $this->enter(static fn (): array => Calls::protectedCall($L, $function, $arguments, $messageHandler));
        if ($status === Lua::LUA_OK) {
            return $result;
        }
        if ($failure !== null && $failure[0] === $result) {
            [, $message, $traceback] = $failure;
        } else {  // no message handler ran (a memory error, an error in error handling)
            $message = LuaObject::toStringCoerced($result) ?? '(error object is a ' . LuaObject::typeName($result) . ' value)';
            $traceback = '';
        }
        try {
            $value = Convert::toPhp($result, 'error', $this);
        } catch (ConversionError) {
            $value = Convert::toHandle($result, $this);
        }
        $error = new RuntimeError($message, $value, $traceback);
        $this->raisedErrors[$error] = [$result, $status];
        throw $error;
    }

    /**
     * Runs $body, a PHP function called from Lua code on thread $L; see
     * the class comment.
     */
    public function callHost(Coroutine $L, \Closure $body): mixed
    {
        $previousThread = $this->runningThread;
        $this->runningThread = $L;
        try {
            $result = $body();
        } catch (ScriptError $error) {
            if ($error->hasValue()) {
                LuaError::raise(Convert::toLua($error->getValue(), 'ScriptError::value()', $this));
            }
            Auxiliary::error($L, $error->getMessage());  // luaL_error: with the position of the calling Lua code
        } catch (RuntimeError $error) {
            $raised = $this->raisedErrors[$error] ?? null;
            if ($raised === null) {
                throw $error;
            }
            throw new LuaError($raised[0], $raised[1]);
        } finally {
            $this->runningThread = $previousThread;
        }
        if ($this->closed) {  // it caught what stopped the run, or closed the sandbox: no more Lua code runs
            throw $this->stoppedBy ?? new SandboxClosed('the sandbox was closed while it ran');
        }
        return $result;
    }

    /** the Lua function of PHP Closure $closure in this sandbox: the same one every time */
    public function hostFunction(\Closure $closure, string $path): NativeFunction
    {
        return $this->hostFunctions[$closure] ??= HostFunction::of($closure, $path)->bind($this, $path);
    }

    /** registers the value of a new handle (see the class comment); returns its number */
    public function anchor(mixed $value): int
    {
        $number = $this->nextAnchor++;
        if ($this->anchors !== null) {
            $this->anchors->arr[$number] = $value;
        }
        return $number;
    }

    /** forgets the value of a handle that is gone */
    public function release(int $number): void
    {
        if ($this->anchors !== null) {
            unset($this->anchors->arr[$number]);
        }
    }

    /**
     * Every call from PHP into this sandbox's Lua code: an exception that
     * is not a Lua error stops the sandbox.
     */
    private function enter(\Closure $body): mixed
    {
        // TODO(runtime lane): PhpErrors::call($body); the Budget starts at a call from outside Lua
        try {
            return $body();
        } catch (LuaError $error) {
            throw $error;
        } catch (\Throwable $thrown) {
            // TODO(runtime lane): LimitReached -> LimitExceeded with the Budget's usage
            $this->stoppedBy ??= $thrown;
            $this->closed = true;
            throw $thrown;
        }
    }

    /** RuntimeError::$luaMessage for error value $value: a string or number, else tostring($value) */
    private static function errorMessage(Coroutine $L, mixed $value): string
    {
        $message = LuaObject::toStringCoerced($value);
        if ($message !== null) {
            return $message;
        }
        [$status, $text] = Calls::protectedRun($L, static fn (): string => Auxiliary::toLString($L, $value));
        return $status === Lua::LUA_OK ? $text : '(error object is a ' . LuaObject::typeName($value) . ' value)';
    }

    /**
     * require: host modules (Environment::addModule), then the loader's
     * "a/b.lua" and "a/b/init.lua" for require("a.b"), as loadlib.c's
     * searchers: a searcher returns [loader, loader data] or [null, what
     * it tried].
     */
    private function openRequire(Coroutine $L): void
    {
        $searchers = [$this->hostModuleSearcher(...), $this->fileSearcher('.lua'), $this->fileSearcher('/init.lua')];
        // TODO(runtime lane): PackageLib::openSandboxRequire($L, $searchers); until then, package.searchers
        $table = new RuntimeTable(\count($searchers));
        foreach ($searchers as $index => $searcher) {
            $table->arr[$index + 1] = new NativeFunction('searcher', static function (Coroutine $L, array $args) use ($searcher): array {
                [$loader, $data] = $searcher($L, Auxiliary::checkString($L, $args, 1));
                return $loader === null ? [$data] : [$loader, $data];
            });
        }
        $L->globalState->globals->hash['package']->hash['searchers'] = $table;
    }

    /** @return array{?NativeFunction, string} */
    private function hostModuleSearcher(Coroutine $L, string $name): array
    {
        if (!isset($this->modules[$name])) {
            return [null, "no host module '$name'"];
        }
        $loader = new NativeFunction($name, function (Coroutine $L, array $args) use ($name): array {
            $module = $this->modules[$name];
            if ($module instanceof \Closure) {  // a factory: once per sandbox (package.loaded keeps its table)
                $module = $this->callHost($L, fn (): mixed => $module($this->context));
                if (!\is_array($module)) {
                    throw new ConversionError('a module factory must return an array, not ' . get_debug_type($module), $name);
                }
            }
            return [Convert::toLua($module, $name, $this)];
        });
        return [$loader, ':host:'];
    }

    /** the searcher of loader file "a/b$suffix" for require("a.b") */
    private function fileSearcher(string $suffix): \Closure
    {
        return function (Coroutine $L, string $name) use ($suffix): array {
            $file = str_replace('.', '/', $name) . $suffix;
            if ($this->loader === null || !$this->loader->exists($file)) {
                return [null, "no file '$file'"];
            }
            $source = $this->loader->getSource($file);
            try {
                $loader = $this->cache->load($L, $source, $this->countSteps);
            } catch (LuaError $error) {
                // loadlib.c: checkload (luaL_error from a searcher: no position)
                LuaError::raise("error loading module '$name' from file '$file':\n\t" . (LuaObject::toStringCoerced($error->value) ?? ''));
            }
            return [$loader, $file];
        };
    }
}
