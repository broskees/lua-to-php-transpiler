<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * The parts of a LuaTable that most tables never use, kept out of the
 * table object so every table is 64 bytes smaller (see LuaTable): the keys
 * that are neither integers nor strings, and the traversals in progress.
 * LuaTable creates it when it first needs it and drops it when no such key
 * and no traversal is left.
 *
 * It needs no destructor of its own (see Teardown): a table hands it to
 * Teardown::$pending whole, and its arrays lead straight to Lua values.
 *
 * @internal
 */
final class LuaTableExtra
{
    /**
     * float (non-integral), boolean and object keys: values and the keys
     * themselves, by encoded key (LuaTable::otherKey())
     *
     * @var array<string, mixed>
     */
    public array $otherValues = [];

    /** @var array<string, mixed> */
    public array $otherKeys = [];

    /** the traversals in progress (LuaTable::next); null: none */
    public ?TableTraversal $traversal = null;
}
