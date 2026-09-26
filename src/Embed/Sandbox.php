<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

use LuaPhp\Embed\Internal\Convert;
use LuaPhp\Embed\Internal\State;
use LuaPhp\Embed\Loader\Source;

/**
 * One isolated Lua state (Environment::newSandbox): its own globals,
 * registry, string metatable, package.loaded and module tables. Cheap,
 * and meant to be thrown away; handles keep it alive.
 *
 * Errors: a Lua error no pcall catches is a RuntimeError, and the sandbox
 * stays usable. An exception thrown by a PHP function (other than
 * ScriptError) stops the run: no Lua code sees it, and it reaches the PHP
 * caller unchanged. A LimitExceeded stops the run too. Either closes the
 * sandbox: using it (or a handle of it) afterwards is SandboxClosed.
 *
 * Closing a sandbox (close(), or a stopped run) runs no more Lua code:
 * pending '__gc' finalizers are skipped, as are those of a sandbox that is
 * simply dropped.
 */
final class Sandbox
{
    /** @internal */
    public function __construct(
        private readonly State $state,
    ) {
    }

    /** sets global $name to $value (converted to Lua; rawset) */
    public function setGlobal(string $name, mixed $value): void
    {
        $this->state->ensureOpen();
        $this->state->L->globalState->globals->set($name, Convert::toLua($value, $name, $this->state));
    }

    /** global $name converted to PHP (rawget; tables copied) */
    public function getGlobal(string $name): mixed
    {
        $this->state->ensureOpen();
        return Convert::toPhp($this->state->L->globalState->globals->get($name), $name, $this->state);
    }

    /** global $name without copying (rawget): a table as a LuaTable handle, a function as a LuaFunction */
    public function getGlobalHandle(string $name): mixed
    {
        $this->state->ensureOpen();
        return Convert::toHandle($this->state->L->globalState->globals->get($name), $this->state);
    }

    /**
     * Compiles $code (text only) as a function; call it to run it. The
     * chunk name defaults to the code itself, as luaL_loadstring's
     * (errors then read [string "..."]:1:). Compiled code is cached by the
     * Environment. A chunk that does not compile is a SyntaxError.
     */
    public function load(string $code, ?string $chunkName = null): LuaFunction
    {
        $this->state->ensureOpen();
        return new LuaFunction($this->state, $this->state->compile(new Source($code, $chunkName ?? $code)));
    }

    /**
     * Runs script $name of the Environment's loader with $args (the
     * script's "..."). Its return values come back in the Result.
     *
     * @param list<mixed> $args
     */
    public function run(string $name, array $args = []): Result
    {
        $this->state->ensureOpen();
        $function = $this->state->compile($this->state->source($name));
        $start = hrtime(true);
        $values = $this->state->callWithPhpValues($function, $args);
        // TODO(runtime lane): output from the sink, usage from the Budget
        return new Result($values, '', new Usage(0, 0, (hrtime(true) - $start) / 1e6, 0));
    }

    /** closes the sandbox: it and its handles cannot be used any more */
    public function close(): void
    {
        $this->state->close();
    }

    public function isClosed(): bool
    {
        return $this->state->closed;
    }
}
