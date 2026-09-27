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
    assertSame(127, OpCodes::OFFSET_SC);
    assertSame(131071, OpCodes::MAXARG_BX);
    assertSame(65535, OpCodes::OFFSET_SBX);
    assertSame(33554431, OpCodes::MAXARG_AX);
    assertSame(16777215, OpCodes::OFFSET_SJ);
}

function test_opmodes_match_lopcodes_c(): void
{
    assertSame(OpCodes::IASBX, OpCodes::getOpMode(OpCodes::OP_LOADI));
    assertSame(OpCodes::IABX, OpCodes::getOpMode(OpCodes::OP_CLOSURE));
    assertSame(OpCodes::ISJ, OpCodes::getOpMode(OpCodes::OP_JMP));
    assertSame(OpCodes::IAX, OpCodes::getOpMode(OpCodes::OP_EXTRAARG));
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
    $addi = OpCodes::createABCk(OpCodes::OP_ADDI, 0, 1, OpCodes::int2sC(1), 0);
    assertSame(0x80010015, $addi);
    assertSame(OpCodes::OP_ADDI, OpCodes::getOpcode($addi));
    assertSame(0, OpCodes::getArgA($addi));
    assertSame(1, OpCodes::getArgB($addi));
    assertSame(1, OpCodes::getArgSC($addi));

    $mmbini = 0x068000af;
    assertSame(OpCodes::OP_MMBINI, OpCodes::getOpcode($mmbini));
    assertSame([1, 1, 6, 0], [
        OpCodes::getArgA($mmbini),
        OpCodes::getArgSB($mmbini),
        OpCodes::getArgC($mmbini),
        OpCodes::getArgK($mmbini),
    ]);

    $jump = OpCodes::createSJ(OpCodes::OP_JMP, -3 + OpCodes::OFFSET_SJ, 0);
    assertSame(-3, OpCodes::getArgSJ($jump));
    assertSame(OpCodes::OP_JMP, OpCodes::getOpcode($jump));
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
        $abck = OpCodes::createABCk($opcode, $a, $b, $c, $k);
        assertTrue($abck >= 0 && $abck <= 0xFFFFFFFF, 'instructions are unsigned 32-bit');
        assertSame([$opcode, $a, $b, $c, $k], [
            OpCodes::getOpcode($abck), OpCodes::getArgA($abck), OpCodes::getArgB($abck),
            OpCodes::getArgC($abck), OpCodes::getArgK($abck),
        ]);
        assertSame($b - OpCodes::OFFSET_SC, OpCodes::getArgSB($abck));
        assertSame($c - OpCodes::OFFSET_SC, OpCodes::getArgSC($abck));
        assertSame($k === 1, OpCodes::testArgK($abck));

        $bx = mt_rand(0, OpCodes::MAXARG_BX);
        $abx = OpCodes::createABx($opcode, $a, $bx);
        assertSame([$opcode, $a, $bx], [OpCodes::getOpcode($abx), OpCodes::getArgA($abx), OpCodes::getArgBx($abx)]);

        $sbx = mt_rand(-OpCodes::OFFSET_SBX, OpCodes::MAXARG_BX - OpCodes::OFFSET_SBX);
        assertSame($sbx, OpCodes::getArgSBx(OpCodes::setArgSBx($abx, $sbx)));

        $ax = mt_rand(0, OpCodes::MAXARG_AX);
        $axInstruction = OpCodes::createAx($opcode, $ax);
        assertSame([$opcode, $ax], [OpCodes::getOpcode($axInstruction), OpCodes::getArgAx($axInstruction)]);

        $sj = mt_rand(-OpCodes::OFFSET_SJ, OpCodes::MAXARG_SJ - OpCodes::OFFSET_SJ);
        $jump = OpCodes::createSJ(OpCodes::OP_JMP, $sj + OpCodes::OFFSET_SJ, 0);
        assertSame($sj, OpCodes::getArgSJ($jump));
        assertSame($sj, OpCodes::getArgSJ(OpCodes::setArgSJ(OpCodes::createSJ(OpCodes::OP_JMP, 0, 0), $sj)));
    }
}

function test_setters_change_only_their_field(): void
{
    $instruction = OpCodes::createABCk(OpCodes::OP_CALL, 1, 2, 3, 1);
    $instruction = OpCodes::setArgA($instruction, 200);
    $instruction = OpCodes::setArgB($instruction, 0);
    $instruction = OpCodes::setArgC($instruction, 255);
    $instruction = OpCodes::setArgK($instruction, 0);
    $instruction = OpCodes::setOpcode($instruction, OpCodes::OP_TAILCALL);
    assertSame(OpCodes::createABCk(OpCodes::OP_TAILCALL, 200, 0, 255, 0), $instruction);
    assertSame(
        OpCodes::createAx(OpCodes::OP_EXTRAARG, 12345),
        OpCodes::setArgAx(OpCodes::createAx(OpCodes::OP_EXTRAARG, 1), 12345),
    );
    assertSame(
        OpCodes::createABx(OpCodes::OP_LOADK, 3, 999),
        OpCodes::setArgBx(OpCodes::createABx(OpCodes::OP_LOADK, 3, 7), 999),
    );
    // an out-of-range value is cut to the field, never spilling into neighbours
    assertSame(
        OpCodes::createABCk(OpCodes::OP_MOVE, 0xFF, 1, 2, 0),
        OpCodes::setArgA(OpCodes::createABCk(OpCodes::OP_MOVE, 0, 1, 2, 0), 0x1FF),
    );
    assertTrue(OpCodes::isOT(OpCodes::createABCk(OpCodes::OP_CALL, 0, 1, 0, 0)));
    assertTrue(!OpCodes::isOT(OpCodes::createABCk(OpCodes::OP_CALL, 0, 1, 1, 0)));
    assertTrue(OpCodes::isOT(OpCodes::createABCk(OpCodes::OP_TAILCALL, 0, 1, 1, 0)));
    assertTrue(OpCodes::isIT(OpCodes::createABCk(OpCodes::OP_RETURN, 0, 0, 1, 0)));
}
