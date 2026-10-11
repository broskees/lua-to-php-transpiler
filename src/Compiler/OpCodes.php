<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Port of lopcodes.h, lopcodes.c and lopnames.h (Lua 5.4.9).
 *
 * Instructions are PHP ints holding the unsigned 32-bit value:
 *
 *        3 3 2 2 2 2 2 2 2 2 2 2 1 1 1 1 1 1 1 1 1 1 0 0 0 0 0 0 0 0 0 0
 *        1 0 9 8 7 6 5 4 3 2 1 0 9 8 7 6 5 4 3 2 1 0 9 8 7 6 5 4 3 2 1 0
 * iABC          C(8)     |      B(8)     |k|     A(8)      |   Op(7)     |
 * iABx                Bx(17)               |     A(8)      |   Op(7)     |
 * iAsBx              sBx (signed)(17)      |     A(8)      |   Op(7)     |
 * iAx                           Ax(25)                     |   Op(7)     |
 * isJ                           sJ (signed)(25)            |   Op(7)     |
 *
 * Macros are methods named after them in camelCase (GETARG_A is getArgA,
 * SETARG_sBx setArgSBx, CREATE_ABCk createABCk, GET_OPCODE getOpcode,
 * MASK1 mask1) and constants keep their C names in upper case (iABC is
 * IABC, MAXARG_Bx MAXARG_BX, OFFSET_sBx OFFSET_SBX, POS_k POS_K), so C code
 * still ports mechanically. The C getters' check_exp() mode assertions are
 * debug-only in C and are not ported. SET* functions return the new
 * instruction instead of modifying their argument.
 *
 * @internal
 */
final class OpCodes
{
    // lopcodes.h: enum OpMode (iABC, iABx, iAsBx, iAx, isJ)
    public const IABC = 0;
    public const IABX = 1;
    public const IASBX = 2;
    public const IAX = 3;
    public const ISJ = 4;

    // lopcodes.h: size and position of opcode arguments
    public const SIZE_C = 8;
    public const SIZE_B = 8;
    public const SIZE_BX = self::SIZE_C + self::SIZE_B + 1;
    public const SIZE_A = 8;
    public const SIZE_AX = self::SIZE_BX + self::SIZE_A;
    public const SIZE_SJ = self::SIZE_BX + self::SIZE_A;

    public const SIZE_OP = 7;

    public const POS_OP = 0;

    public const POS_A = self::POS_OP + self::SIZE_OP;
    public const POS_K = self::POS_A + self::SIZE_A;
    public const POS_B = self::POS_K + 1;
    public const POS_C = self::POS_B + self::SIZE_B;

    public const POS_BX = self::POS_K;

    public const POS_AX = self::POS_A;

    public const POS_SJ = self::POS_A;

    // lopcodes.h: limits for opcode arguments ('int' has 32 bits here)
    public const MAXARG_BX = (1 << self::SIZE_BX) - 1;
    public const OFFSET_SBX = self::MAXARG_BX >> 1;
    public const MAXARG_AX = (1 << self::SIZE_AX) - 1;
    public const MAXARG_SJ = (1 << self::SIZE_SJ) - 1;
    public const OFFSET_SJ = self::MAXARG_SJ >> 1;
    public const MAXARG_A = (1 << self::SIZE_A) - 1;
    public const MAXARG_B = (1 << self::SIZE_B) - 1;
    public const MAXARG_C = (1 << self::SIZE_C) - 1;
    public const OFFSET_SC = self::MAXARG_C >> 1;

    // lopcodes.h: MAXINDEXRK
    public const MAXINDEXRK = self::MAXARG_B;

    // lopcodes.h: invalid register that fits in 8 bits
    public const NO_REG = self::MAXARG_A;

    // lopcodes.h: number of list items to accumulate before a SETLIST instruction
    public const LFIELDS_PER_FLUSH = 50;

    // lopcodes.h: enum OpCode ("ORDER OP")
    public const OP_MOVE = 0;
    public const OP_LOADI = 1;
    public const OP_LOADF = 2;
    public const OP_LOADK = 3;
    public const OP_LOADKX = 4;
    public const OP_LOADFALSE = 5;
    public const OP_LFALSESKIP = 6;
    public const OP_LOADTRUE = 7;
    public const OP_LOADNIL = 8;
    public const OP_GETUPVAL = 9;
    public const OP_SETUPVAL = 10;
    public const OP_GETTABUP = 11;
    public const OP_GETTABLE = 12;
    public const OP_GETI = 13;
    public const OP_GETFIELD = 14;
    public const OP_SETTABUP = 15;
    public const OP_SETTABLE = 16;
    public const OP_SETI = 17;
    public const OP_SETFIELD = 18;
    public const OP_NEWTABLE = 19;
    public const OP_SELF = 20;
    public const OP_ADDI = 21;
    public const OP_ADDK = 22;
    public const OP_SUBK = 23;
    public const OP_MULK = 24;
    public const OP_MODK = 25;
    public const OP_POWK = 26;
    public const OP_DIVK = 27;
    public const OP_IDIVK = 28;
    public const OP_BANDK = 29;
    public const OP_BORK = 30;
    public const OP_BXORK = 31;
    public const OP_SHRI = 32;
    public const OP_SHLI = 33;
    public const OP_ADD = 34;
    public const OP_SUB = 35;
    public const OP_MUL = 36;
    public const OP_MOD = 37;
    public const OP_POW = 38;
    public const OP_DIV = 39;
    public const OP_IDIV = 40;
    public const OP_BAND = 41;
    public const OP_BOR = 42;
    public const OP_BXOR = 43;
    public const OP_SHL = 44;
    public const OP_SHR = 45;
    public const OP_MMBIN = 46;
    public const OP_MMBINI = 47;
    public const OP_MMBINK = 48;
    public const OP_UNM = 49;
    public const OP_BNOT = 50;
    public const OP_NOT = 51;
    public const OP_LEN = 52;
    public const OP_CONCAT = 53;
    public const OP_CLOSE = 54;
    public const OP_TBC = 55;
    public const OP_JMP = 56;
    public const OP_EQ = 57;
    public const OP_LT = 58;
    public const OP_LE = 59;
    public const OP_EQK = 60;
    public const OP_EQI = 61;
    public const OP_LTI = 62;
    public const OP_LEI = 63;
    public const OP_GTI = 64;
    public const OP_GEI = 65;
    public const OP_TEST = 66;
    public const OP_TESTSET = 67;
    public const OP_CALL = 68;
    public const OP_TAILCALL = 69;
    public const OP_RETURN = 70;
    public const OP_RETURN0 = 71;
    public const OP_RETURN1 = 72;
    public const OP_FORLOOP = 73;
    public const OP_FORPREP = 74;
    public const OP_TFORPREP = 75;
    public const OP_TFORCALL = 76;
    public const OP_TFORLOOP = 77;
    public const OP_SETLIST = 78;
    public const OP_CLOSURE = 79;
    public const OP_VARARG = 80;
    public const OP_VARARGPREP = 81;
    public const OP_EXTRAARG = 82;

    public const NUM_OPCODES = self::OP_EXTRAARG + 1;

    // lopnames.h: opnames ("ORDER OP")
    public const OPNAMES = [
        'MOVE',
        'LOADI',
        'LOADF',
        'LOADK',
        'LOADKX',
        'LOADFALSE',
        'LFALSESKIP',
        'LOADTRUE',
        'LOADNIL',
        'GETUPVAL',
        'SETUPVAL',
        'GETTABUP',
        'GETTABLE',
        'GETI',
        'GETFIELD',
        'SETTABUP',
        'SETTABLE',
        'SETI',
        'SETFIELD',
        'NEWTABLE',
        'SELF',
        'ADDI',
        'ADDK',
        'SUBK',
        'MULK',
        'MODK',
        'POWK',
        'DIVK',
        'IDIVK',
        'BANDK',
        'BORK',
        'BXORK',
        'SHRI',
        'SHLI',
        'ADD',
        'SUB',
        'MUL',
        'MOD',
        'POW',
        'DIV',
        'IDIV',
        'BAND',
        'BOR',
        'BXOR',
        'SHL',
        'SHR',
        'MMBIN',
        'MMBINI',
        'MMBINK',
        'UNM',
        'BNOT',
        'NOT',
        'LEN',
        'CONCAT',
        'CLOSE',
        'TBC',
        'JMP',
        'EQ',
        'LT',
        'LE',
        'EQK',
        'EQI',
        'LTI',
        'LEI',
        'GTI',
        'GEI',
        'TEST',
        'TESTSET',
        'CALL',
        'TAILCALL',
        'RETURN',
        'RETURN0',
        'RETURN1',
        'FORLOOP',
        'FORPREP',
        'TFORPREP',
        'TFORCALL',
        'TFORLOOP',
        'SETLIST',
        'CLOSURE',
        'VARARG',
        'VARARGPREP',
        'EXTRAARG',
    ];

    /**
     * lopcodes.c: luaP_opmodes ("ORDER OP"), built with lopcodes.h opmode():
     * bits 0-2: op mode
     * bit 3: instruction set register A
     * bit 4: operator is a test (next instruction must be a jump)
     * bit 5: instruction uses 'L->top' set by previous instruction (when B == 0)
     * bit 6: instruction sets 'L->top' for next instruction (when C == 0)
     * bit 7: instruction is an MM instruction (call a metamethod)
     */
    public const OPMODES = [
        //          MM OT IT T  A  mode              opcode
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_MOVE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IASBX,  // OP_LOADI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IASBX,  // OP_LOADF
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABX,   // OP_LOADK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABX,   // OP_LOADKX
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_LOADFALSE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_LFALSESKIP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_LOADTRUE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_LOADNIL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_GETUPVAL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_SETUPVAL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_GETTABUP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_GETTABLE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_GETI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_GETFIELD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_SETTABUP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_SETTABLE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_SETI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_SETFIELD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_NEWTABLE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SELF
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_ADDI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_ADDK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SUBK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_MULK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_MODK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_POWK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_DIVK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_IDIVK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BANDK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BORK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BXORK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SHRI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SHLI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_ADD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SUB
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_MUL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_MOD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_POW
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_DIV
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_IDIV
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BAND
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BOR
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BXOR
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SHL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_SHR
        (1 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_MMBIN
        (1 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_MMBINI
        (1 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_MMBINK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_UNM
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_BNOT
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_NOT
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_LEN
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_CONCAT
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_CLOSE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_TBC
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::ISJ,    // OP_JMP
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_EQ
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_LT
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_LE
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_EQK
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_EQI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_LTI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_LEI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_GTI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_GEI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::IABC,   // OP_TEST
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (1 << 3) | self::IABC,   // OP_TESTSET
        (0 << 7) | (1 << 6) | (1 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_CALL
        (0 << 7) | (1 << 6) | (1 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_TAILCALL
        (0 << 7) | (0 << 6) | (1 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_RETURN
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_RETURN0
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_RETURN1
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABX,   // OP_FORLOOP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABX,   // OP_FORPREP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABX,   // OP_TFORPREP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_TFORCALL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABX,   // OP_TFORLOOP
        (0 << 7) | (0 << 6) | (1 << 5) | (0 << 4) | (0 << 3) | self::IABC,   // OP_SETLIST
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABX,   // OP_CLOSURE
        (0 << 7) | (1 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_VARARG
        (0 << 7) | (0 << 6) | (1 << 5) | (0 << 4) | (1 << 3) | self::IABC,   // OP_VARARGPREP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::IAX,    // OP_EXTRAARG
    ];

    private const INSTRUCTION_MASK = 0xFFFFFFFF;

    // lopcodes.h: the masks GET_OPCODE and the GETARG_* macros compute,
    // MASK1(size, 0), as constants (C's compiler folds them; computing
    // them per call made decoding a fifth of load()'s time for new code)
    private const MASK_OP = (1 << self::SIZE_OP) - 1;  // MASK1(SIZE_OP, 0)
    private const MASK_A = (1 << self::SIZE_A) - 1;  // MASK1(SIZE_A, 0)
    private const MASK_B = (1 << self::SIZE_B) - 1;  // MASK1(SIZE_B, 0)
    private const MASK_C = (1 << self::SIZE_C) - 1;  // MASK1(SIZE_C, 0)
    private const MASK_BX = (1 << self::SIZE_BX) - 1;  // MASK1(SIZE_Bx, 0)
    private const MASK_AX = (1 << self::SIZE_AX) - 1;  // MASK1(SIZE_Ax, 0)
    private const MASK_SJ = (1 << self::SIZE_SJ) - 1;  // MASK1(SIZE_sJ, 0)

    // lopcodes.h: getOpMode
    public static function getOpMode(int $opcode): int
    {
        return self::OPMODES[$opcode] & 7;
    }

    // lopcodes.h: testAMode
    public static function testAMode(int $opcode): bool
    {
        return (self::OPMODES[$opcode] & (1 << 3)) !== 0;
    }

    // lopcodes.h: testTMode
    public static function testTMode(int $opcode): bool
    {
        return (self::OPMODES[$opcode] & (1 << 4)) !== 0;
    }

    // lopcodes.h: testITMode
    public static function testITMode(int $opcode): bool
    {
        return (self::OPMODES[$opcode] & (1 << 5)) !== 0;
    }

    // lopcodes.h: testOTMode
    public static function testOTMode(int $opcode): bool
    {
        return (self::OPMODES[$opcode] & (1 << 6)) !== 0;
    }

    // lopcodes.h: testMMMode
    public static function testMMMode(int $opcode): bool
    {
        return (self::OPMODES[$opcode] & (1 << 7)) !== 0;
    }

    // lopcodes.h: isOT ("out top": set top for next instruction)
    public static function isOT(int $instruction): bool
    {
        $opcode = self::getOpcode($instruction);
        return (self::testOTMode($opcode) && self::getArgC($instruction) === 0)
            || $opcode === self::OP_TAILCALL;
    }

    // lopcodes.h: isIT ("in top": uses top from previous instruction)
    public static function isIT(int $instruction): bool
    {
        return self::testITMode(self::getOpcode($instruction)) && self::getArgB($instruction) === 0;
    }

    // lopcodes.h: int2sC
    public static function int2sC(int $value): int
    {
        return $value + self::OFFSET_SC;
    }

    // lopcodes.h: sC2int
    public static function sC2int(int $value): int
    {
        return $value - self::OFFSET_SC;
    }

    // lopcodes.h: MASK1 (a mask with 'n' 1 bits at position 'p')
    public static function mask1(int $bitCount, int $position): int
    {
        return (((1 << $bitCount) - 1) << $position) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: MASK0 (a mask with 'n' 0 bits at position 'p')
    public static function mask0(int $bitCount, int $position): int
    {
        // (~x written x ^ -1: ~ is a VM handler call in the loops that set arguments, see AGENTS.md)
        return (self::mask1($bitCount, $position) ^ -1) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: GET_OPCODE
    public static function getOpcode(int $instruction): int
    {
        return ($instruction >> self::POS_OP) & self::MASK_OP;
    }

    // lopcodes.h: SET_OPCODE
    public static function setOpcode(int $instruction, int $opcode): int
    {
        return self::setarg($instruction, $opcode, self::POS_OP, self::SIZE_OP);
    }

    // lopcodes.h: getarg
    public static function getarg(int $instruction, int $position, int $size): int
    {
        return ($instruction >> $position) & self::mask1($size, 0);
    }

    // lopcodes.h: setarg
    public static function setarg(int $instruction, int $value, int $position, int $size): int
    {
        return ($instruction & self::mask0($size, $position))
            | (($value << $position) & self::mask1($size, $position));
    }

    // lopcodes.h: GETARG_A
    public static function getArgA(int $instruction): int
    {
        return ($instruction >> self::POS_A) & self::MASK_A;
    }

    // lopcodes.h: SETARG_A
    public static function setArgA(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_A, self::SIZE_A);
    }

    // lopcodes.h: GETARG_B
    public static function getArgB(int $instruction): int
    {
        return ($instruction >> self::POS_B) & self::MASK_B;
    }

    // lopcodes.h: GETARG_sB
    public static function getArgSB(int $instruction): int
    {
        return self::sC2int(self::getArgB($instruction));
    }

    // lopcodes.h: SETARG_B
    public static function setArgB(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_B, self::SIZE_B);
    }

    // lopcodes.h: GETARG_C
    public static function getArgC(int $instruction): int
    {
        return ($instruction >> self::POS_C) & self::MASK_C;
    }

    // lopcodes.h: GETARG_sC
    public static function getArgSC(int $instruction): int
    {
        return self::sC2int(self::getArgC($instruction));
    }

    // lopcodes.h: SETARG_C
    public static function setArgC(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_C, self::SIZE_C);
    }

    // lopcodes.h: TESTARG_k
    public static function testArgK(int $instruction): bool
    {
        return ($instruction & (1 << self::POS_K)) !== 0;
    }

    // lopcodes.h: GETARG_k
    public static function getArgK(int $instruction): int
    {
        return ($instruction >> self::POS_K) & 1;  // MASK1(1, 0)
    }

    // lopcodes.h: SETARG_k
    public static function setArgK(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_K, 1);
    }

    // lopcodes.h: GETARG_Bx
    public static function getArgBx(int $instruction): int
    {
        return ($instruction >> self::POS_BX) & self::MASK_BX;
    }

    // lopcodes.h: SETARG_Bx
    public static function setArgBx(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_BX, self::SIZE_BX);
    }

    // lopcodes.h: GETARG_Ax
    public static function getArgAx(int $instruction): int
    {
        return ($instruction >> self::POS_AX) & self::MASK_AX;
    }

    // lopcodes.h: SETARG_Ax
    public static function setArgAx(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_AX, self::SIZE_AX);
    }

    // lopcodes.h: GETARG_sBx
    public static function getArgSBx(int $instruction): int
    {
        return (($instruction >> self::POS_BX) & self::MASK_BX) - self::OFFSET_SBX;
    }

    // lopcodes.h: SETARG_sBx
    public static function setArgSBx(int $instruction, int $value): int
    {
        return self::setArgBx($instruction, ($value + self::OFFSET_SBX) & self::INSTRUCTION_MASK);
    }

    // lopcodes.h: GETARG_sJ
    public static function getArgSJ(int $instruction): int
    {
        return (($instruction >> self::POS_SJ) & self::MASK_SJ) - self::OFFSET_SJ;
    }

    // lopcodes.h: SETARG_sJ
    public static function setArgSJ(int $instruction, int $value): int
    {
        $field = ($value + self::OFFSET_SJ) & self::INSTRUCTION_MASK;
        return self::setarg($instruction, $field, self::POS_SJ, self::SIZE_SJ);
    }

    // lopcodes.h: CREATE_ABCk
    public static function createABCk(int $opcode, int $a, int $b, int $c, int $k): int
    {
        return (($opcode << self::POS_OP)
            | ($a << self::POS_A)
            | ($b << self::POS_B)
            | ($c << self::POS_C)
            | ($k << self::POS_K)) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: CREATE_ABx
    public static function createABx(int $opcode, int $a, int $bx): int
    {
        return (($opcode << self::POS_OP)
            | ($a << self::POS_A)
            | ($bx << self::POS_BX)) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: CREATE_Ax
    public static function createAx(int $opcode, int $ax): int
    {
        return (($opcode << self::POS_OP)
            | ($ax << self::POS_AX)) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: CREATE_sJ (callers pass j already offset, as lcode.c does)
    public static function createSJ(int $opcode, int $j, int $k): int
    {
        return (($opcode << self::POS_OP)
            | ($j << self::POS_SJ)
            | ($k << self::POS_K)) & self::INSTRUCTION_MASK;
    }
}
