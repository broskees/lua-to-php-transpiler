<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

use LuaPhp\Embed\Internal\Convert;

/**
 * A Lua table held by PHP, accessed exactly and without copying: get,
 * set, length and pairs are raw (rawget, rawset, rawlen, next: no
 * metamethods, no Lua code runs) and give tables, functions and other
 * objects as handles; toArray() copies it to PHP as results are.
 */
final class LuaTable extends LuaValue
{
    /** rawget: the value at $key (a PHP value converted to Lua), as a handle if it is not nil, a boolean, a number or a string */
    public function get(mixed $key): mixed
    {
        $this->sandboxState->ensureOpen();
        $table = $this->luaValue;
        return Convert::toHandle($table->get(Convert::toLua($key, 'key', $this->sandboxState)), $this->sandboxState);
    }

    /** rawset: $value (converted to Lua; null removes the entry) at $key */
    public function set(mixed $key, mixed $value): void
    {
        $this->sandboxState->ensureOpen();
        $luaKey = Convert::toLua($key, 'key', $this->sandboxState);
        if ($luaKey === null || (\is_float($luaKey) && is_nan($luaKey))) {
            throw new ConversionError('a table index cannot be ' . ($luaKey === null ? 'nil' : 'NaN'), '');
        }
        $this->luaValue->set($luaKey, Convert::toLua($value, 'value', $this->sandboxState));
    }

    /** rawlen: the table's border (#t without '__len') */
    public function length(): int
    {
        $this->sandboxState->ensureOpen();
        return $this->luaValue->length();
    }

    /**
     * next: every key => value, as get() gives them (keys of any type).
     * Removing entries while iterating is allowed, as in Lua.
     *
     * @return \Generator<mixed, mixed>
     */
    public function pairs(): \Generator
    {
        $key = null;
        while (true) {
            $this->sandboxState->ensureOpen();
            $entry = $this->luaValue->next($key);
            if ($entry === null) {
                return;
            }
            if ($entry === false) {
                throw new \LogicException('invalid key to next: a key was added to the table during pairs()');
            }
            [$key, $value] = $entry;
            yield Convert::toHandle($key, $this->sandboxState) => Convert::toHandle($value, $this->sandboxState);
        }
    }

    /** the table copied to a PHP array (see Environment: lists start at 0) */
    public function toArray(): array
    {
        $this->sandboxState->ensureOpen();
        return Convert::toPhp($this->luaValue, 'table', $this->sandboxState);
    }
}
