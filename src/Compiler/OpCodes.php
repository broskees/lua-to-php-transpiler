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
 * Macros keep their C names (GETARG_A, SETARG_sBx, CREATE_ABCk, ...) so C
 * code ports mechanically. The C getters' check_exp() mode assertions are
 * debug-only in C and are not ported. SET* functions return the new
 * instruction instead of modifying their argument.
 *
 * @internal
 */
final class OpCodes
{
    // lopcodes.h: enum OpMode
    public const iABC = 0;
    public const iABx = 1;
    public const iAsBx = 2;
    public const iAx = 3;
    public const isJ = 4;

    // lopcodes.h: size and position of opcode arguments
    public const SIZE_C = 8;
    public const SIZE_B = 8;
    public const SIZE_Bx = self::SIZE_C + self::SIZE_B + 1;
    public const SIZE_A = 8;
    public const SIZE_Ax = self::SIZE_Bx + self::SIZE_A;
    public const SIZE_sJ = self::SIZE_Bx + self::SIZE_A;

    public const SIZE_OP = 7;

    public const POS_OP = 0;

    public const POS_A = self::POS_OP + self::SIZE_OP;
    public const POS_k = self::POS_A + self::SIZE_A;
    public const POS_B = self::POS_k + 1;
    public const POS_C = self::POS_B + self::SIZE_B;

    public const POS_Bx = self::POS_k;

    public const POS_Ax = self::POS_A;

    public const POS_sJ = self::POS_A;

    // lopcodes.h: limits for opcode arguments ('int' has 32 bits here)
    public const MAXARG_Bx = (1 << self::SIZE_Bx) - 1;
    public const OFFSET_sBx = self::MAXARG_Bx >> 1;
    public const MAXARG_Ax = (1 << self::SIZE_Ax) - 1;
    public const MAXARG_sJ = (1 << self::SIZE_sJ) - 1;
    public const OFFSET_sJ = self::MAXARG_sJ >> 1;
    public const MAXARG_A = (1 << self::SIZE_A) - 1;
    public const MAXARG_B = (1 << self::SIZE_B) - 1;
    public const MAXARG_C = (1 << self::SIZE_C) - 1;
    public const OFFSET_sC = self::MAXARG_C >> 1;

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
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_MOVE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iAsBx,  // OP_LOADI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iAsBx,  // OP_LOADF
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABx,   // OP_LOADK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABx,   // OP_LOADKX
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_LOADFALSE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_LFALSESKIP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_LOADTRUE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_LOADNIL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_GETUPVAL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_SETUPVAL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_GETTABUP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_GETTABLE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_GETI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_GETFIELD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_SETTABUP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_SETTABLE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_SETI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_SETFIELD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_NEWTABLE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SELF
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_ADDI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_ADDK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SUBK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_MULK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_MODK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_POWK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_DIVK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_IDIVK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BANDK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BORK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BXORK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SHRI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SHLI
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_ADD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SUB
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_MUL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_MOD
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_POW
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_DIV
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_IDIV
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BAND
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BOR
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BXOR
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SHL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_SHR
        (1 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_MMBIN
        (1 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_MMBINI
        (1 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_MMBINK
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_UNM
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_BNOT
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_NOT
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_LEN
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_CONCAT
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_CLOSE
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_TBC
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::isJ,    // OP_JMP
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_EQ
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_LT
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_LE
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_EQK
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_EQI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_LTI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_LEI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_GTI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_GEI
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (0 << 3) | self::iABC,   // OP_TEST
        (0 << 7) | (0 << 6) | (0 << 5) | (1 << 4) | (1 << 3) | self::iABC,   // OP_TESTSET
        (0 << 7) | (1 << 6) | (1 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_CALL
        (0 << 7) | (1 << 6) | (1 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_TAILCALL
        (0 << 7) | (0 << 6) | (1 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_RETURN
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_RETURN0
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_RETURN1
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABx,   // OP_FORLOOP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABx,   // OP_FORPREP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABx,   // OP_TFORPREP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_TFORCALL
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABx,   // OP_TFORLOOP
        (0 << 7) | (0 << 6) | (1 << 5) | (0 << 4) | (0 << 3) | self::iABC,   // OP_SETLIST
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABx,   // OP_CLOSURE
        (0 << 7) | (1 << 6) | (0 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_VARARG
        (0 << 7) | (0 << 6) | (1 << 5) | (0 << 4) | (1 << 3) | self::iABC,   // OP_VARARGPREP
        (0 << 7) | (0 << 6) | (0 << 5) | (0 << 4) | (0 << 3) | self::iAx,    // OP_EXTRAARG
    ];

    private const INSTRUCTION_MASK = 0xFFFFFFFF;

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
        $opcode = self::GET_OPCODE($instruction);
        return (self::testOTMode($opcode) && self::GETARG_C($instruction) === 0)
            || $opcode === self::OP_TAILCALL;
    }

    // lopcodes.h: isIT ("in top": uses top from previous instruction)
    public static function isIT(int $instruction): bool
    {
        return self::testITMode(self::GET_OPCODE($instruction)) && self::GETARG_B($instruction) === 0;
    }

    // lopcodes.h: int2sC
    public static function int2sC(int $value): int
    {
        return $value + self::OFFSET_sC;
    }

    // lopcodes.h: sC2int
    public static function sC2int(int $value): int
    {
        return $value - self::OFFSET_sC;
    }

    // lopcodes.h: MASK1 (a mask with 'n' 1 bits at position 'p')
    public static function MASK1(int $bitCount, int $position): int
    {
        return (((1 << $bitCount) - 1) << $position) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: MASK0 (a mask with 'n' 0 bits at position 'p')
    public static function MASK0(int $bitCount, int $position): int
    {
        return ~self::MASK1($bitCount, $position) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: GET_OPCODE
    public static function GET_OPCODE(int $instruction): int
    {
        return ($instruction >> self::POS_OP) & self::MASK1(self::SIZE_OP, 0);
    }

    // lopcodes.h: SET_OPCODE
    public static function SET_OPCODE(int $instruction, int $opcode): int
    {
        return self::setarg($instruction, $opcode, self::POS_OP, self::SIZE_OP);
    }

    // lopcodes.h: getarg
    public static function getarg(int $instruction, int $position, int $size): int
    {
        return ($instruction >> $position) & self::MASK1($size, 0);
    }

    // lopcodes.h: setarg
    public static function setarg(int $instruction, int $value, int $position, int $size): int
    {
        return ($instruction & self::MASK0($size, $position))
            | (($value << $position) & self::MASK1($size, $position));
    }

    // lopcodes.h: GETARG_A
    public static function GETARG_A(int $instruction): int
    {
        return self::getarg($instruction, self::POS_A, self::SIZE_A);
    }

    // lopcodes.h: SETARG_A
    public static function SETARG_A(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_A, self::SIZE_A);
    }

    // lopcodes.h: GETARG_B
    public static function GETARG_B(int $instruction): int
    {
        return self::getarg($instruction, self::POS_B, self::SIZE_B);
    }

    // lopcodes.h: GETARG_sB
    public static function GETARG_sB(int $instruction): int
    {
        return self::sC2int(self::GETARG_B($instruction));
    }

    // lopcodes.h: SETARG_B
    public static function SETARG_B(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_B, self::SIZE_B);
    }

    // lopcodes.h: GETARG_C
    public static function GETARG_C(int $instruction): int
    {
        return self::getarg($instruction, self::POS_C, self::SIZE_C);
    }

    // lopcodes.h: GETARG_sC
    public static function GETARG_sC(int $instruction): int
    {
        return self::sC2int(self::GETARG_C($instruction));
    }

    // lopcodes.h: SETARG_C
    public static function SETARG_C(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_C, self::SIZE_C);
    }

    // lopcodes.h: TESTARG_k
    public static function TESTARG_k(int $instruction): bool
    {
        return ($instruction & (1 << self::POS_k)) !== 0;
    }

    // lopcodes.h: GETARG_k
    public static function GETARG_k(int $instruction): int
    {
        return self::getarg($instruction, self::POS_k, 1);
    }

    // lopcodes.h: SETARG_k
    public static function SETARG_k(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_k, 1);
    }

    // lopcodes.h: GETARG_Bx
    public static function GETARG_Bx(int $instruction): int
    {
        return self::getarg($instruction, self::POS_Bx, self::SIZE_Bx);
    }

    // lopcodes.h: SETARG_Bx
    public static function SETARG_Bx(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_Bx, self::SIZE_Bx);
    }

    // lopcodes.h: GETARG_Ax
    public static function GETARG_Ax(int $instruction): int
    {
        return self::getarg($instruction, self::POS_Ax, self::SIZE_Ax);
    }

    // lopcodes.h: SETARG_Ax
    public static function SETARG_Ax(int $instruction, int $value): int
    {
        return self::setarg($instruction, $value, self::POS_Ax, self::SIZE_Ax);
    }

    // lopcodes.h: GETARG_sBx
    public static function GETARG_sBx(int $instruction): int
    {
        return self::getarg($instruction, self::POS_Bx, self::SIZE_Bx) - self::OFFSET_sBx;
    }

    // lopcodes.h: SETARG_sBx
    public static function SETARG_sBx(int $instruction, int $value): int
    {
        return self::SETARG_Bx($instruction, ($value + self::OFFSET_sBx) & self::INSTRUCTION_MASK);
    }

    // lopcodes.h: GETARG_sJ
    public static function GETARG_sJ(int $instruction): int
    {
        return self::getarg($instruction, self::POS_sJ, self::SIZE_sJ) - self::OFFSET_sJ;
    }

    // lopcodes.h: SETARG_sJ
    public static function SETARG_sJ(int $instruction, int $value): int
    {
        return self::setarg($instruction, ($value + self::OFFSET_sJ) & self::INSTRUCTION_MASK, self::POS_sJ, self::SIZE_sJ);
    }

    // lopcodes.h: CREATE_ABCk
    public static function CREATE_ABCk(int $opcode, int $a, int $b, int $c, int $k): int
    {
        return (($opcode << self::POS_OP)
            | ($a << self::POS_A)
            | ($b << self::POS_B)
            | ($c << self::POS_C)
            | ($k << self::POS_k)) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: CREATE_ABx
    public static function CREATE_ABx(int $opcode, int $a, int $bx): int
    {
        return (($opcode << self::POS_OP)
            | ($a << self::POS_A)
            | ($bx << self::POS_Bx)) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: CREATE_Ax
    public static function CREATE_Ax(int $opcode, int $ax): int
    {
        return (($opcode << self::POS_OP)
            | ($ax << self::POS_Ax)) & self::INSTRUCTION_MASK;
    }

    // lopcodes.h: CREATE_sJ (callers pass j already offset, as lcode.c does)
    public static function CREATE_sJ(int $opcode, int $j, int $k): int
    {
        return (($opcode << self::POS_OP)
            | ($j << self::POS_sJ)
            | ($k << self::POS_k)) & self::INSTRUCTION_MASK;
    }
}
