<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Expression and variable descriptor; mirrors C 'expdesc' in lparser.h.
 * The C union 'u' becomes one field per member (info, ival, nval, strval,
 * ind.t/ind.idx as indT/indIdx, var.ridx/var.vidx as varRidx/varVidx);
 * lparser.c and lcode.c only read the member that matches 'k'.
 * C copies expdescs by value: use copyFrom(), never share one object.
 *
 * @internal
 */
final class ExpDesc
{
    // lparser.h: enum expkind
    public const VVOID = 0;      // empty expression list
    public const VNIL = 1;       // constant nil
    public const VTRUE = 2;      // constant true
    public const VFALSE = 3;     // constant false
    public const VK = 4;         // constant in 'k'; info = index of constant in 'k'
    public const VKFLT = 5;      // floating constant; nval = numerical float value
    public const VKINT = 6;      // integer constant; ival = numerical integer value
    public const VKSTR = 7;      // string constant; strval = string
    public const VNONRELOC = 8;  // value in a fixed register; info = result register
    public const VLOCAL = 9;     // local variable; varRidx = register, varVidx = index in 'actvar'
    public const VUPVAL = 10;    // upvalue variable; info = index of upvalue in 'upvalues'
    public const VCONST = 11;    // compile-time <const> variable; info = absolute index in 'actvar'
    public const VINDEXED = 12;  // indexed variable; indT = table register, indIdx = key's R index
    public const VINDEXUP = 13;  // indexed upvalue; indT = table upvalue, indIdx = key's K index
    public const VINDEXI = 14;   // indexed with constant integer; indT = table register, indIdx = key's value
    public const VINDEXSTR = 15; // indexed with literal string; indT = table register, indIdx = key's K index
    public const VJMP = 16;      // test/comparison; info = pc of corresponding jump instruction
    public const VRELOC = 17;    // result can go in any register; info = instruction pc
    public const VCALL = 18;     // function call; info = instruction pc
    public const VVARARG = 19;   // vararg expression; info = instruction pc

    public int $k = self::VVOID;

    public int $info = 0;

    public int $ival = 0;

    public float $nval = 0.0;

    public string $strval = '';

    public int $indT = 0;

    public int $indIdx = 0;

    public int $varRidx = 0;

    public int $varVidx = 0;

    /** patch list of 'exit when true' */
    public int $t = CodeGen::NO_JUMP;

    /** patch list of 'exit when false' */
    public int $f = CodeGen::NO_JUMP;

    // lparser.h: vkisvar
    public static function vkisvar(int $k): bool
    {
        return self::VLOCAL <= $k && $k <= self::VINDEXSTR;
    }

    // lparser.h: vkisindexed
    public static function vkisindexed(int $k): bool
    {
        return self::VINDEXED <= $k && $k <= self::VINDEXSTR;
    }

    /** C struct assignment: *this = *other */
    public function copyFrom(ExpDesc $other): void
    {
        $this->k = $other->k;
        $this->info = $other->info;
        $this->ival = $other->ival;
        $this->nval = $other->nval;
        $this->strval = $other->strval;
        $this->indT = $other->indT;
        $this->indIdx = $other->indIdx;
        $this->varRidx = $other->varRidx;
        $this->varVidx = $other->varVidx;
        $this->t = $other->t;
        $this->f = $other->f;
    }
}
