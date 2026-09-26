<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\OpCodes;
use LuaPhp\Runtime\Gc\Collector;

/**
 * A table constructor made of constants, run from its bytecode (lvm.c:
 * luaV_execute for OP_NEWTABLE, OP_SETFIELD, OP_SETI, OP_SETTABLE,
 * OP_SETLIST and the OP_LOAD* that fill their registers).
 *
 * FunctionEmitter emits a call to run() for such a run of instructions
 * (see FunctionEmitter::constructorRunEnd) instead of inline code for each
 * instruction: a data module of 100,000 rows is one PHP statement, not a
 * PHP function too big for PHP to compile. The instructions are the
 * compact data: run() reads them and their constants from the running
 * function's Proto, so long strings keep their identity like everywhere
 * else in emitted code. (In a small function, the call is the slow path:
 * without a hook, and when no collection can start, the emitted code
 * builds the run's result from literals instead; see
 * FunctionEmitter::emitConstructorRun.)
 *
 * Each instruction does exactly what its inline code does, in bytecode
 * order: the line/count hook check before each instruction C's vmfetch
 * fetches (not the OP_EXTRAARG an instruction consumes), the GC check
 * after each OP_NEWTABLE (savedpc, live registers up to its A), the raw
 * set of OP_SET* when the target is a table without a metatable (else
 * Vm::setTable, with savedpc, as when a hook or finalizer changed the
 * register or gave the table a metatable), and every register written.
 * Lua code can only run at those points (traceExec's hooks,
 * Collector::step's finalizers, Vm::setTable's metamethods), and each
 * instruction reads its operands after them.
 *
 * @internal
 */
final class TableConstructor
{
    /** runs the instructions $pc..$last of the running Lua function (the frame of $ci, registers $R) */
    public static function run(Coroutine $L, CallInfo $ci, array &$R, int $pc, int $last): void
    {
        $proto = $ci->func->proto;
        $code = $proto->code;
        $k = $proto->k;
        for (; $pc <= $last; $pc++) {
            if ($L->trap) {
                Hooks::traceExec($L, $ci, $pc);
            }
            $instruction = $code[$pc];
            $a = ($instruction >> OpCodes::POS_A) & OpCodes::MAXARG_A;
            switch ($instruction & 0x7F) {  // lopcodes.h: GET_OPCODE
                case OpCodes::OP_SETFIELD:
                    $t = $R[$a];
                    $key = $k[($instruction >> OpCodes::POS_B) & OpCodes::MAXARG_B];
                    $c = $instruction >> OpCodes::POS_C;
                    $v = ($instruction & (1 << OpCodes::POS_k)) !== 0 ? $k[$c] : $R[$c];
                    if ($t instanceof LuaTable && ($t->metatable === null || isset($t->hash[$key]))) {
                        if ($v !== null) {
                            $t->hash[$key] = $v;
                        } else {
                            unset($t->hash[$key]);
                        }
                    } else {
                        $ci->savedpc = $pc;
                        Vm::setTable($L, $t, $key, $v, $a);
                    }
                    break;

                case OpCodes::OP_NEWTABLE:
                    $b = ($instruction >> OpCodes::POS_B) & OpCodes::MAXARG_B;
                    $arraySize = $instruction >> OpCodes::POS_C;
                    if (($instruction & (1 << OpCodes::POS_k)) !== 0) {  // non-zero extra argument?
                        $arraySize += ($code[$pc + 1] >> OpCodes::POS_Ax) * (OpCodes::MAXARG_C + 1);
                    }
                    $hashSize = $b > 0 ? 1 << ($b - 1) : 0;  // size is 2^(b - 1)
                    $R[$a] = new LuaTable($arraySize);
                    if (($L->globalState->gcDebt += Collector::tableSize($arraySize, $hashSize)) > 0) {  // lvm.c: checkGC
                        $ci->savedpc = $pc;
                        Collector::step($L, $a + 1);
                    }
                    $pc++;  // skip its OP_EXTRAARG
                    break;

                case OpCodes::OP_LOADK:
                    $R[$a] = $k[$instruction >> OpCodes::POS_Bx];
                    break;

                case OpCodes::OP_LOADKX:
                    $R[$a] = $k[$code[++$pc] >> OpCodes::POS_Ax];  // (and skip its OP_EXTRAARG)
                    break;

                case OpCodes::OP_LOADI:
                    $R[$a] = ($instruction >> OpCodes::POS_Bx) - OpCodes::OFFSET_sBx;
                    break;

                case OpCodes::OP_LOADF:
                    $R[$a] = (float) (($instruction >> OpCodes::POS_Bx) - OpCodes::OFFSET_sBx);
                    break;

                case OpCodes::OP_LOADFALSE:
                    $R[$a] = false;
                    break;

                case OpCodes::OP_LOADTRUE:
                    $R[$a] = true;
                    break;

                case OpCodes::OP_LOADNIL:
                    $b = ($instruction >> OpCodes::POS_B) & OpCodes::MAXARG_B;
                    for ($i = 0; $i <= $b; $i++) {
                        $R[$a + $i] = null;
                    }
                    break;

                case OpCodes::OP_SETI:
                    $t = $R[$a];
                    $b = ($instruction >> OpCodes::POS_B) & OpCodes::MAXARG_B;
                    $c = $instruction >> OpCodes::POS_C;
                    $v = ($instruction & (1 << OpCodes::POS_k)) !== 0 ? $k[$c] : $R[$c];
                    if ($t instanceof LuaTable && ($t->metatable === null || isset($t->arr[$b]))) {
                        if ($v !== null) {
                            $t->arr[$b] = $v;
                        } else {
                            unset($t->arr[$b]);
                        }
                    } else {
                        $ci->savedpc = $pc;
                        Vm::setTable($L, $t, $b, $v, $a);
                    }
                    break;

                case OpCodes::OP_SETTABLE:
                    $t = $R[$a];
                    $key = $R[($instruction >> OpCodes::POS_B) & OpCodes::MAXARG_B];
                    $c = $instruction >> OpCodes::POS_C;
                    $v = ($instruction & (1 << OpCodes::POS_k)) !== 0 ? $k[$c] : $R[$c];
                    if ($t instanceof LuaTable && \is_int($key) && ($t->metatable === null || isset($t->arr[$key]))) {
                        if ($v !== null) {
                            $t->arr[$key] = $v;
                        } else {
                            unset($t->arr[$key]);
                        }
                    } elseif ($t instanceof LuaTable && \is_string($key) && ($t->metatable === null || isset($t->hash[$key]))) {
                        if ($v !== null) {
                            $t->hash[$key] = $v;
                        } else {
                            unset($t->hash[$key]);
                        }
                    } else {
                        $ci->savedpc = $pc;
                        Vm::setTable($L, $t, $key, $v, $a);
                    }
                    break;

                case OpCodes::OP_SETLIST:  // (never with B = 0 here: see FunctionEmitter)
                    $n = ($instruction >> OpCodes::POS_B) & OpCodes::MAXARG_B;
                    $index = $instruction >> OpCodes::POS_C;  // the last index already set
                    if (($instruction & (1 << OpCodes::POS_k)) !== 0) {
                        $index += ($code[++$pc] >> OpCodes::POS_Ax) * (OpCodes::MAXARG_C + 1);  // (and skip its OP_EXTRAARG)
                    }
                    $t = $R[$a];
                    for ($i = 1; $i <= $n; $i++) {
                        $v = $R[$a + $i];
                        if ($v !== null) {
                            $t->arr[$index + $i] = $v;
                        } else {
                            unset($t->arr[$index + $i]);
                        }
                    }
                    if ($t->sizearray < $index + $n) {
                        $t->sizearray = $index + $n;
                    }
                    break;

                default:
                    throw new \LogicException('not a table constructor instruction: ' . OpCodes::OPNAMES[$instruction & 0x7F]);
            }
        }
    }
}
