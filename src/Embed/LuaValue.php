<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

use LuaPhp\Embed\Internal\State;
use LuaPhp\Runtime\LuaObject;

/**
 * A Lua value held by PHP: a thread or a userdata (LuaTable and
 * LuaFunction are the handles of tables and functions). A handle keeps
 * its sandbox and its value alive, for Lua's collector too (weak tables
 * and finalizers see it as reachable). It can go back into Lua only in
 * its own sandbox.
 */
class LuaValue
{
    private readonly int $anchor;

    /** @internal */
    public function __construct(
        /** @internal */
        public readonly State $sandboxState,
        /** @internal */
        public readonly mixed $luaValue,
    ) {
        $this->anchor = $sandboxState->anchor($luaValue);
    }

    public function __destruct()
    {
        $this->sandboxState->release($this->anchor);
    }

    /** Lua's type() of the value: "thread", "userdata", "table" or "function" */
    public function type(): string
    {
        return LuaObject::typeName($this->luaValue);
    }
}
