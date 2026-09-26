<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * A Lua function held by PHP. Calling it runs it in its sandbox and
 * returns its results as a list of PHP values (converted as a script's
 * results are); a Lua error is a RuntimeError.
 */
final class LuaFunction extends LuaValue
{
    /** @return list<mixed> */
    public function call(mixed ...$args): array
    {
        return $this->sandboxState->callWithPhpValues($this->luaValue, $args);
    }

    /** @return list<mixed> */
    public function __invoke(mixed ...$args): array
    {
        return $this->sandboxState->callWithPhpValues($this->luaValue, $args);
    }
}
