<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\Proto;

/**
 * The traversals (next() calls) in progress over one LuaTable: the one that
 * holds the PHP internal pointers, and where each suspended one goes on
 * (see LuaTable::next). Created on a table's first next() and dropped when
 * no traversal needs it any more; it lives in LuaTableExtra.
 *
 * ltable.c needs none of this: a key deleted during a traversal stays in its
 * node as a dead key, so luaH_next finds the place of any traversal from its
 * key alone. A PHP array also keeps a deleted entry's slot, but cannot say
 * where it was, and has one internal pointer to follow one traversal.
 *
 * It holds no Lua value but the holder's key and thread, so it needs no
 * destructor of its own (see Teardown).
 *
 * @internal
 */
final class TableTraversal
{
    /**
     * the key last given to the traversal that holds the internal pointer
     * of that key's part (the pointer is past it); null: no traversal holds
     * a pointer
     */
    public mixed $cursorKey = null;

    /** where the holder calls next from (LuaTable::isCaller): its caller's savedpc, -1 for PHP code */
    public int $holderPc = -1;

    /** ... the caller's code (null: not a Lua function) */
    public ?Proto $holderProto = null;

    /**
     * ... and its frame: the CallInfo's frameBytes, which grows with every
     * frame of a thread, so it tells the live frames of one thread apart (a
     * recursion); -1 for PHP code
     */
    public int $holderFrameBytes = -1;

    /** ... and its thread (null for PHP code) */
    public ?Coroutine $holderThread = null;

    /**
     * the logs of suspended traversals, by spl_object_id, least recently
     * used first (LuaTable::findLog)
     *
     * @var array<int, TraversalLog>
     */
    public array $logs = [];

    /**
     * first keys that next(t) gave while another traversal held a pointer,
     * by part and PHP key, oldest first (emptiness checks, or traversals
     * that start there): one deleted is followed by the part's first entry
     *
     * @var array<int, array<int|string, true>>
     */
    public array $firstKeysGiven = [];

    /** keys in all logs together (LuaTable::rememberLog keeps it in bounds) */
    public int $loggedKeyCount = 0;
}
