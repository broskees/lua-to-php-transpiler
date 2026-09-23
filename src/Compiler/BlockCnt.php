<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Control of blocks; mirrors C 'BlockCnt' in lparser.c.
 */
final class BlockCnt
{
    /** chain */
    public ?BlockCnt $previous = null;

    /** index of first label in this block */
    public int $firstlabel = 0;

    /** index of first pending goto in this block */
    public int $firstgoto = 0;

    /** # active locals outside the block */
    public int $nactvar = 0;

    /** true if some variable in the block is an upvalue */
    public bool $upval = false;

    /** true if 'block' is a loop */
    public bool $isloop = false;

    /** true if inside the scope of a to-be-closed var. */
    public bool $insidetbc = false;
}
