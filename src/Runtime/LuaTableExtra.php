<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * The parts of a LuaTable that most tables never use, kept out of the
 * table object so every table is 64 bytes smaller (see LuaTable): the keys
 * that are neither integers nor strings, and the cursor of a traversal in
 * progress. LuaTable creates it when it first needs it and drops it when a
 * traversal ends and no such key is left.
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

    /**
     * the last key returned by next() (a Lua value; null: none), whose
     * part's PHP internal array pointer next() left on it
     */
    public mixed $cursorKey = null;
}
