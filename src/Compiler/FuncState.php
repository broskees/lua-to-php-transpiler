<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * State needed to generate code for a given function; mirrors C
 * 'FuncState' in lparser.h. The Proto's arrays may hold stale entries past
 * the counts below (C keeps them in over-allocated vectors); close_func
 * trims them.
 */
final class FuncState
{
    /** enclosing function */
    public ?FuncState $prev = null;

    /** chain of current blocks */
    public ?BlockCnt $bl = null;

    /** next position to code (equivalent to 'ncode') */
    public int $pc = 0;

    /** 'label' of last 'jump label' */
    public int $lasttarget = 0;

    /** last line that was saved in 'lineinfo' */
    public int $previousline = 0;

    /** number of elements in 'k' */
    public int $nk = 0;

    /** number of elements in 'p' */
    public int $np = 0;

    /** number of elements in 'abslineinfo' */
    public int $nabslineinfo = 0;

    /** index of first local var (in Dyndata array) */
    public int $firstlocal = 0;

    /** index of first label (in 'dyd->label') */
    public int $firstlabel = 0;

    /** number of elements in 'f->locvars' */
    public int $ndebugvars = 0;

    /** number of active local variables */
    public int $nactvar = 0;

    /** number of upvalues */
    public int $nups = 0;

    /** first free register */
    public int $freereg = 0;

    /** instructions issued since last absolute line info */
    public int $iwthabs = 0;

    /** function needs to close upvalues when returning */
    public bool $needclose = false;

    public function __construct(
        /** current function header */
        public Proto $f,
        /** lexical state */
        public Lexer $ls,
    ) {
    }
}
