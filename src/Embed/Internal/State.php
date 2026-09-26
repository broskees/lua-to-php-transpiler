<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Embed\ConversionError;
use LuaPhp\Embed\LimitExceeded;
use LuaPhp\Embed\Libraries;
use LuaPhp\Embed\Limits;
use LuaPhp\Embed\Loader\Loader;
use LuaPhp\Embed\Loader\Source;
use LuaPhp\Embed\RunContext;
use LuaPhp\Embed\RuntimeError;
use LuaPhp\Embed\SandboxClosed;
use LuaPhp\Embed\ScriptError;
use LuaPhp\Embed\Usage;
use LuaPhp\Lib\PackageLib;
use LuaPhp\Lib\StandardLibraries;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Budget;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\LimitReached;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable as RuntimeTable;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\PhpErrors;

/**
 * One sandbox's Lua state (Sandbox is its public face; handles and PHP
 * functions hold this).
 *
 * The state: the Environment's libraries (openSelected; sandboxed unless
 * Libraries::ALL), require from host modules and the loader, the
 * Environment's CompileCache as its chunk cache, one Sink for standard
 * output and error, code compiled with step counting when the limits need
 * it, a Budget from the Limits (memoryBytes at most what memory_limit
 * leaves when the sandbox is created), and no ini_set for fibers.
 *
 * Calls into Lua run through enter(): PHP's warnings are exceptions
 * meanwhile (PhpErrors), a LimitReached becomes LimitExceeded, and any
 * exception that is not a Lua error stops the sandbox: it is closed and
 * abandoned (no more '__gc' or '__close'), and the exception goes on
 * unchanged. A call from PHP outside any run of this sandbox starts a run
 * (startRun: the budget, the output collected); a call from a PHP function
 * the run called is part of that run. Lua code runs on the thread whose
 * Lua code called the PHP function running now (runningThread), else on
 * the main thread, as protected calls whose message handler takes the
 * traceback; a Lua error there is a RuntimeError.
 *
 * PHP functions called from Lua run through callHost: a ScriptError is a
 * Lua error; a RuntimeError of this sandbox that a PHP function let
 * through goes on as the Lua error it was (as lua_call's would); anything
 * else stops the run; afterwards time and memory are checked (its time
 * counts).
 *
 * Handles register their values in a table the collector marks
 * ($G->libraryState), as luaL_ref would, so a value PHP holds stays alive
 * for Lua too (weak tables, finalizers).
 *
 * @internal
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

    private readonly ?Budget $budget;

    private readonly Sink $sink;

    /** calls into Lua running now (a PHP function a run called may call in again) */
    private int $depth = 0;

    /** hrtime(true) when the current run started */
    private int $runStart = 0;

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
     * @param ?\Closure(string): void $output the host's output sink, or null to collect
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly CompileCache $cache,
        private readonly ?Loader $loader,
        private readonly array $modules,
        array $globals,
        array $libraries,
        bool $allowLoad,
        Limits $limits,
        bool $countSteps,
        ?\Closure $output,
        array $context,
    ) {
        $this->raisedErrors = new \WeakMap();
        $this->hostFunctions = new \WeakMap();
        $this->context = new RunContext($context, $this);
        $this->sink = new Sink($output);
        $L = Coroutine::newState();
        $this->L = $L;
        $G = $L->globalState;
        $G->setsFiberStackSize = false;
        $G->chunkCache = $cache;
        $G->countSteps = $countSteps;
        $G->output = $this->sink;
        $G->errorOutput = $this->sink;
        PhpErrors::call(static fn () => StandardLibraries::openSelected($L, $libraries, $libraries !== Libraries::ALL, $allowLoad));
        $this->anchors = new RuntimeTable();
        $G->libraryState['embed.handles'] = $this->anchors;
        if (\in_array('package', $libraries, true)) {
            $this->openRequire($L, $libraries !== Libraries::ALL);
        }
        foreach ($globals as $name => $value) {
            $G->globals->set($name, Convert::toLua($value, $name, $this));
        }
        $this->budget = self::budgetFor($limits);
        if ($this->budget !== null) {
            $G->setBudget($this->budget);
        }
    }

    /** the Budget of $limits (none: no limit at all); its memory never more than what memory_limit leaves */
    private static function budgetFor(Limits $limits): ?Budget
    {
        if ($limits == Limits::none()) {
            return null;
        }
        $budget = new Budget($limits->steps, $limits->memoryBytes, $limits->seconds, $limits->outputBytes, $limits->coroutines, $limits->callDepth);
        $budget->hostMemoryIsLimit = true;
        return $budget;
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
        if ($this->L !== null) {
            Collector::abandonState($this->L->globalState);
        }
        $this->closed = true;
        $this->L = null;
        $this->anchors = null;
    }

    /** counts $steps against the run's step limit */
    public function chargeSteps(int $steps): void
    {
        $this->budget?->chargeSteps($steps);
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
        $this->startRun();
        return $this->compileSource($source);
    }

    /**
     * Runs $source with PHP values $arguments: [its results as PHP values,
     * what it wrote to a collecting sink, what it used].
     *
     * @param array<mixed> $arguments
     * @return array{list<mixed>, string, Usage}
     */
    public function run(Source $source, array $arguments): array
    {
        $this->ensureOpen();
        $luaArguments = $this->argumentsToLua($arguments);
        $this->startRun();
        $outputStart = \strlen($this->sink->collected);
        $results = $this->callLua($this->compileSource($source), $luaArguments);
        $values = $this->resultsToPhp($results);
        $output = substr($this->sink->collected, $outputStart);
        $usage = $this->usage();
        if ($this->depth === 0) {
            $this->sink->collected = '';
        }
        return [$values, $output, $usage];
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
        $luaArguments = $this->argumentsToLua($arguments);
        $this->startRun();
        $values = $this->resultsToPhp($this->callLua($function, $luaArguments));
        if ($this->depth === 0) {
            $this->sink->collected = '';  // (only runs return what they printed)
        }
        return $values;
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
        $this->budget?->checkLimits();  // (the time it took counts)
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

    /** a call from PHP outside any run of this sandbox starts one: the budget, the output collected */
    private function startRun(): void
    {
        if ($this->depth > 0) {
            return;  // (called back from a PHP function of the run: part of it)
        }
        $this->budget?->start();
        $this->runStart = hrtime(true);
        $this->sink->collected = '';
        $this->sink->written = 0;
    }

    /** what the current run used (steps and memory are measured with limits only) */
    private function usage(): Usage
    {
        if ($this->budget === null) {
            return new Usage(0, 0, (hrtime(true) - $this->runStart) / 1e6, $this->sink->written);
        }
        $usage = $this->budget->usage();
        return new Usage($usage['steps'], $usage['peakMemoryBytes'], $usage['milliseconds'], $usage['outputBytes']);
    }

    /** $source's main function (in the current run) */
    private function compileSource(Source $source): LuaClosure
    {
        $L = $this->runningThread ?? $this->L;
        try {
            return $this->enter(static fn (): LuaClosure => CompileCache::load($L, $source));
        } catch (LuaError $error) {
            throw CompileCache::syntaxError($error, $source);
        }
    }

    /**
     * @param array<mixed> $arguments
     * @return list<mixed>
     */
    private function argumentsToLua(array $arguments): array
    {
        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException('Lua functions take a list of arguments (no names)');
        }
        $luaArguments = [];
        foreach ($arguments as $index => $argument) {
            $luaArguments[] = Convert::toLua($argument, "args[$index]", $this);
        }
        return $luaArguments;
    }

    /**
     * @param list<mixed> $results
     * @return list<mixed>
     */
    private function resultsToPhp(array $results): array
    {
        $values = [];
        foreach ($results as $index => $result) {
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
    private function callLua(mixed $function, array $arguments): array
    {
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
     * Every call from PHP into this sandbox's Lua code: see the class
     * comment.
     */
    private function enter(\Closure $body): mixed
    {
        $this->depth++;
        try {
            return PhpErrors::call($body);
        } catch (LuaError $error) {
            throw $error;
        } catch (LimitReached $reached) {
            $exceeded = new LimitExceeded($reached->limit, $this->usage());
            $this->stop($exceeded);
            throw $exceeded;
        } catch (\Throwable $thrown) {
            $this->stop($thrown);
            throw $thrown;
        } finally {
            $this->depth--;
        }
    }

    /** a run was stopped by $thrown: the sandbox is closed and runs no more Lua code */
    private function stop(\Throwable $thrown): void
    {
        $this->stoppedBy ??= $thrown;
        $this->closed = true;
        if ($this->L !== null) {
            Collector::abandonState($this->L->globalState);
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
     * it tried]. In a sandbox they are all of require; with
     * Libraries::ALL they come before package.searchers' own.
     */
    private function openRequire(Coroutine $L, bool $sandboxed): void
    {
        $searchers = [$this->hostModuleSearcher(...), $this->fileSearcher('.lua'), $this->fileSearcher('/init.lua')];
        if ($sandboxed) {
            PackageLib::openSandboxRequire($L, $searchers);
            return;
        }
        $table = $L->globalState->globals->hash['package']->hash['searchers'];
        $all = [];
        foreach ($searchers as $searcher) {
            $all[] = new NativeFunction('searcher', static function (Coroutine $L, array $args) use ($searcher): array {
                [$loader, $data] = $searcher($L, Auxiliary::checkString($L, $args, 1));
                return $loader === null ? [$data] : [$loader, $data];
            });
        }
        for ($i = 1; ($standard = $table->get($i)) !== null; $i++) {
            $all[] = $standard;
        }
        foreach ($all as $index => $searcher) {
            $table->set($index + 1, $searcher);
        }
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
                $loader = CompileCache::load($L, $source);
            } catch (LuaError $error) {
                // loadlib.c: checkload (luaL_error from a searcher: no position)
                LuaError::raise("error loading module '$name' from file '$file':\n\t" . (LuaObject::toStringCoerced($error->value) ?? ''));
            }
            return [$loader, $file];
        };
    }
}
