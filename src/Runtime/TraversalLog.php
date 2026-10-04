<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * The order in which a suspended traversal goes on through one part of a
 * LuaTable (see LuaTable::next and TableTraversal): the part's keys as a
 * traversal at its key saw them, deleted ones included (C's dead keys).
 * Taken from the part when the traversal lost the internal pointer, up to
 * its key; the keys after it are added when it gets there.
 *
 * @internal
 */
final class TraversalLog
{
    /** @var list<int|string> PHP keys of the part, in traversal order */
    public array $keys = [];

    /** @var array<int|string, int> the position of each key in $keys */
    public array $positions = [];

    /** whether $keys ends with the part's last key; else the part's keys not in it come next */
    public bool $complete = false;

    /** the part's size and last key when its keys were last read (LuaTable::logIsStale) */
    public int $partCountWhenRead = 0;
    public int|string|null $partLastKeyWhenRead = null;

    /**
     * how many suspended traversals are at each of its keys (by PHP key),
     * and in all: it goes when none is left
     *
     * @var array<int|string, int>
     */
    public array $traversalsAt = [];
    public int $traversalCount = 0;

    /** where the traversal it was made for calls next from (see TableTraversal::$holderPc) */
    public int $ownerPc = -1;
    public ?Proto $ownerProto = null;
    public int $ownerFrameBytes = -1;

    public function __construct(public readonly int $part)
    {
    }

    public function append(int|string $phpKey): void
    {
        $this->positions[$phpKey] = \count($this->keys);
        $this->keys[] = $phpKey;
    }
}
