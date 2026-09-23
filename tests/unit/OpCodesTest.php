<?php

declare(strict_types=1);

namespace Tests\OpCodesTest;

use LuaPhp\Compiler\OpCodes;

function test_tables_cover_every_opcode(): void
{
    assertSame(83, OpCodes::NUM_OPCODES);
    assertSame(OpCodes::NUM_OPCODES, count(OpCodes::OPNAMES));
    assertSame(OpCodes::NUM_OPCODES, count(OpCodes::OPMODES));
    assertSame('EXTRAARG', OpCodes::OPNAMES[OpCodes::OP_EXTRAARG]);
    assertSame('VARARGPREP', OpCodes::OPNAMES[OpCodes::OP_VARARGPREP]);
}

function test_argument_limits_match_lopcodes_h(): void
{
    assertSame(255, OpCodes::MAXARG_A);
    assertSame(255, OpCodes::MAXARG_B);
    assertSame(255, OpCodes::MAXARG_C);
    assertSame(127, OpCodes::OFFSET_sC);
    assertSame(131071, OpCodes::MAXARG_Bx);
    assertSame(65535, OpCodes::OFFSET_sBx);
    assertSame(33554431, OpCodes::MAXARG_Ax);
    assertSame(16777215, OpCodes::OFFSET_sJ);
}

function test_opmodes_match_lopcodes_c(): void
{
    assertSame(OpCodes::iAsBx, OpCodes::getOpMode(OpCodes::OP_LOADI));
    assertSame(OpCodes::iABx, OpCodes::getOpMode(OpCodes::OP_CLOSURE));
    assertSame(OpCodes::isJ, OpCodes::getOpMode(OpCodes::OP_JMP));
    assertSame(OpCodes::iAx, OpCodes::getOpMode(OpCodes::OP_EXTRAARG));
    assertTrue(OpCodes::testMMMode(OpCodes::OP_MMBINK));
    assertTrue(OpCodes::testTMode(OpCodes::OP_TESTSET) && OpCodes::testAMode(OpCodes::OP_TESTSET));
    assertTrue(OpCodes::testITMode(OpCodes::OP_SETLIST) && !OpCodes::testOTMode(OpCodes::OP_SETLIST));
    assertTrue(OpCodes::testOTMode(OpCodes::OP_VARARG) && !OpCodes::testITMode(OpCodes::OP_VARARG));
    assertTrue(!OpCodes::testAMode(OpCodes::OP_SETUPVAL));
}

function test_known_instructions_decode(): void
{
    // Words from `luac5.4 -o` of "local a, b = ...\na = b + 1\n", listed by luac5.4 -l as
    // "ADDI 0 1 1" and "MMBINI 1 1 6 0 ; __add".
    $addi = OpCodes::CREATE_ABCk(OpCodes::OP_ADDI, 0, 1, OpCodes::int2sC(1), 0);
    assertSame(0x80010015, $addi);
    assertSame(OpCodes::OP_ADDI, OpCodes::GET_OPCODE($addi));
    assertSame(0, OpCodes::GETARG_A($addi));
    assertSame(1, OpCodes::GETARG_B($addi));
    assertSame(1, OpCodes::GETARG_sC($addi));

    $mmbini = 0x068000af;
    assertSame(OpCodes::OP_MMBINI, OpCodes::GET_OPCODE($mmbini));
    assertSame([1, 1, 6, 0], [OpCodes::GETARG_A($mmbini), OpCodes::GETARG_sB($mmbini), OpCodes::GETARG_C($mmbini), OpCodes::GETARG_k($mmbini)]);

    $jump = OpCodes::CREATE_sJ(OpCodes::OP_JMP, -3 + OpCodes::OFFSET_sJ, 0);
    assertSame(-3, OpCodes::GETARG_sJ($jump));
    assertSame(OpCodes::OP_JMP, OpCodes::GET_OPCODE($jump));
}

function test_encoders_and_decoders_round_trip(): void
{
    mt_srand(504);
    for ($round = 0; $round < 2000; $round++) {
        $opcode = mt_rand(0, OpCodes::NUM_OPCODES - 1);
        $a = mt_rand(0, OpCodes::MAXARG_A);
        $b = mt_rand(0, OpCodes::MAXARG_B);
        $c = mt_rand(0, OpCodes::MAXARG_C);
        $k = mt_rand(0, 1);
        $abck = OpCodes::CREATE_ABCk($opcode, $a, $b, $c, $k);
        assertTrue($abck >= 0 && $abck <= 0xFFFFFFFF, 'instructions are unsigned 32-bit');
        assertSame([$opcode, $a, $b, $c, $k], [
            OpCodes::GET_OPCODE($abck), OpCodes::GETARG_A($abck), OpCodes::GETARG_B($abck),
            OpCodes::GETARG_C($abck), OpCodes::GETARG_k($abck),
        ]);
        assertSame($b - OpCodes::OFFSET_sC, OpCodes::GETARG_sB($abck));
        assertSame($c - OpCodes::OFFSET_sC, OpCodes::GETARG_sC($abck));
        assertSame($k === 1, OpCodes::TESTARG_k($abck));

        $bx = mt_rand(0, OpCodes::MAXARG_Bx);
        $abx = OpCodes::CREATE_ABx($opcode, $a, $bx);
        assertSame([$opcode, $a, $bx], [OpCodes::GET_OPCODE($abx), OpCodes::GETARG_A($abx), OpCodes::GETARG_Bx($abx)]);

        $sbx = mt_rand(-OpCodes::OFFSET_sBx, OpCodes::MAXARG_Bx - OpCodes::OFFSET_sBx);
        assertSame($sbx, OpCodes::GETARG_sBx(OpCodes::SETARG_sBx($abx, $sbx)));

        $ax = mt_rand(0, OpCodes::MAXARG_Ax);
        $axInstruction = OpCodes::CREATE_Ax($opcode, $ax);
        assertSame([$opcode, $ax], [OpCodes::GET_OPCODE($axInstruction), OpCodes::GETARG_Ax($axInstruction)]);

        $sj = mt_rand(-OpCodes::OFFSET_sJ, OpCodes::MAXARG_sJ - OpCodes::OFFSET_sJ);
        $jump = OpCodes::CREATE_sJ(OpCodes::OP_JMP, $sj + OpCodes::OFFSET_sJ, 0);
        assertSame($sj, OpCodes::GETARG_sJ($jump));
        assertSame($sj, OpCodes::GETARG_sJ(OpCodes::SETARG_sJ(OpCodes::CREATE_sJ(OpCodes::OP_JMP, 0, 0), $sj)));
    }
}

function test_setters_change_only_their_field(): void
{
    $instruction = OpCodes::CREATE_ABCk(OpCodes::OP_CALL, 1, 2, 3, 1);
    $instruction = OpCodes::SETARG_A($instruction, 200);
    $instruction = OpCodes::SETARG_B($instruction, 0);
    $instruction = OpCodes::SETARG_C($instruction, 255);
    $instruction = OpCodes::SETARG_k($instruction, 0);
    $instruction = OpCodes::SET_OPCODE($instruction, OpCodes::OP_TAILCALL);
    assertSame(OpCodes::CREATE_ABCk(OpCodes::OP_TAILCALL, 200, 0, 255, 0), $instruction);
    assertSame(OpCodes::CREATE_Ax(OpCodes::OP_EXTRAARG, 12345), OpCodes::SETARG_Ax(OpCodes::CREATE_Ax(OpCodes::OP_EXTRAARG, 1), 12345));
    assertSame(OpCodes::CREATE_ABx(OpCodes::OP_LOADK, 3, 999), OpCodes::SETARG_Bx(OpCodes::CREATE_ABx(OpCodes::OP_LOADK, 3, 7), 999));
    // an out-of-range value is cut to the field, never spilling into neighbours
    assertSame(OpCodes::CREATE_ABCk(OpCodes::OP_MOVE, 0xFF, 1, 2, 0), OpCodes::SETARG_A(OpCodes::CREATE_ABCk(OpCodes::OP_MOVE, 0, 1, 2, 0), 0x1FF));
    assertTrue(OpCodes::isOT(OpCodes::CREATE_ABCk(OpCodes::OP_CALL, 0, 1, 0, 0)));
    assertTrue(!OpCodes::isOT(OpCodes::CREATE_ABCk(OpCodes::OP_CALL, 0, 1, 1, 0)));
    assertTrue(OpCodes::isOT(OpCodes::CREATE_ABCk(OpCodes::OP_TAILCALL, 0, 1, 1, 0)));
    assertTrue(OpCodes::isIT(OpCodes::CREATE_ABCk(OpCodes::OP_RETURN, 0, 0, 1, 0)));
}
