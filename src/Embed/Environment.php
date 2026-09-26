<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

use LuaPhp\Embed\Internal\CompileCache;
use LuaPhp\Embed\Internal\Convert;
use LuaPhp\Embed\Internal\State;
use LuaPhp\Embed\Loader\Loader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\LuaError;

/**
 * What every run shares, created once per process: where scripts come
 * from (the loader), the compile cache (in memory, and in $cacheDir if
 * given: trusted only if private to this user, as load()'s cache), the
 * libraries scripts get (Libraries), the PHP functions and modules they
 * may use, and the limits of each run.
 *
 * A script is compiled the first time its exact bytes appear under its
 * name, then reused; compile() does it ahead of time. Creating an
 * Environment or a Sandbox, loading and running code never change PHP's
 * settings, handlers or output buffers.
 */
final class Environment
{
    private readonly CompileCache $cache;

    /** @var list<string> */
    private readonly array $libraries;

    /** @var array<string, array<mixed>|\Closure> */
    private array $modules = [];

    /** @var array<string, mixed> */
    private array $globals = [];

    /** @param list<string> $libraries */
    public function __construct(
        private readonly ?Loader $loader = null,
        ?string $cacheDir = null,
        array $libraries = Libraries::SAFE,
        private readonly Limits $limits = new Limits(),
        private readonly bool $allowLoad = false,
    ) {
        $this->libraries = Libraries::check($libraries);
        $this->cache = new CompileCache($cacheDir);
    }

    /**
     * Makes $module available to require($name) (not as a global): an
     * array of Closures (functions), scalars and arrays (tables), or a
     * Closure(RunContext): array that builds it, once per sandbox, the
     * first time a script requires it. Host modules come before the
     * loader's files.
     *
     * @param array<mixed>|\Closure $module
     */
    public function addModule(string $name, array|\Closure $module): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('a module needs a name');
        }
        if (\is_array($module)) {
            Convert::toLua($module, $name, null);  // can it be passed to Lua? (reads the signatures of its functions)
        }
        $this->modules[$name] = $module;
    }

    /** sets global $name to $value (converted to Lua) in every sandbox created from now on */
    public function addGlobal(string $name, mixed $value): void
    {
        Convert::toLua($value, $name, null);
        $this->globals[$name] = $value;
    }

    /** @param array<string, mixed> $context what the sandbox's RunContext carries */
    public function newSandbox(array $context = []): Sandbox
    {
        return new Sandbox(new State($this->cache, $this->loader, $this->modules, $this->globals, $this->libraries, $this->allowLoad, $this->countsSteps(), $context));
    }

    /**
     * Runs script $name in a new sandbox with $args (the script's "...")
     * and $context; see Sandbox::run.
     *
     * @param list<mixed> $args
     * @param array<string, mixed> $context
     */
    public function run(string $name, array $args = [], array $context = []): Result
    {
        return $this->newSandbox($context)->run($name, $args);
    }

    /**
     * Compiles scripts ahead of time (at deploy or save time) into the
     * cache, running nothing. Returns the SyntaxError of each script that
     * does not compile, by name.
     *
     * @param iterable<string> $names
     * @return array<string, SyntaxError>
     */
    public function compile(iterable $names): array
    {
        if ($this->loader === null) {
            throw new \LogicException('cannot compile scripts: the Environment has no loader');
        }
        $L = Coroutine::newState();  // the compiler runs on a thread (its protected parser, its C-call depth)
        $errors = [];
        foreach ($names as $name) {
            $source = $this->loader->getSource($name);
            try {
                $this->cache->load($L, $source, $this->countsSteps());
            } catch (LuaError $error) {
                $errors[$name] = CompileCache::syntaxError($error, $source);
            }
        }
        return $errors;
    }

    /** whether scripts are compiled to count steps (the cache keeps both kinds apart) */
    private function countsSteps(): bool
    {
        return false;  // TODO(runtime lane): true when $this->limits needs the Budget's step checks
    }
}
