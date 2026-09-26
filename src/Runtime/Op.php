<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Runtime\Gc\Collector;

/**
 * The compact forms of instructions (lvm.c: luaV_execute's cases): one
 * call per instruction, with its program counter and operands as PHP
 * literals, where emitted code would otherwise be the instruction's inline
 * code. FunctionEmitter emits them where speed does not matter and PHP's
 * compile memory does (see FunctionEmitter::compactCodeOutsideLoops): PHP
 * needs about 5 KB of memory to compile an ordinary instruction's inline
 * code, about 1 KB for a call.
 *
 * Each method does exactly what the inline code of its instruction does
 * (FunctionEmitter::emitInstruction), in the same order: the line/count
 * hook check first (luaG_traceexec, with $top for an instruction that
 * takes the values up to it: lopcodes.h isIT), then its operands read
 * from the registers, the same fast paths, savedpc before anything that
 * can raise, call or run a metamethod, the same runtime calls, the GC check
 * after an allocation. A change to an instruction's inline code changes
 * its compact form too; the differential cases run with every instruction
 * compact (compact_forms.php).
 *
 * The running frame is $L->ci (between instructions it is always the
 * frame of the emitted function), its registers $L->ci->R (a reference to
 * the function's $R). Registers are numbers, constants the values
 * themselves (a long string as emitted code reads it from the Proto).
 * Jumps stay in the emitted code: a test returns its condition, and
 * OP_JMP's form only does its hook check.
 *
 * @internal
 */
final class Op
{
    /** OP_MOVE: R[A] := R[B] */
    public static function move(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $R[$a] = $R[$b];
    }

    /** OP_LOADI, OP_LOADF, OP_LOADK, OP_LOADKX, OP_LOADFALSE, OP_LOADTRUE (and OP_LFALSESKIP's load): R[A] := $value */
    public static function load(Coroutine $L, int $pc, int $a, mixed $value): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->R[$a] = $value;
    }

    /** OP_LOADNIL: R[A], ..., R[A+B] := nil */
    public static function loadNil(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        for ($i = 0; $i <= $b; $i++) {
            $R[$a + $i] = null;
        }
    }

    /** OP_GETUPVAL: R[A] := UpValue[B] */
    public static function getUpval(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->R[$a] = $ci->func->getUpval($b)->v;
    }

    /** OP_SETUPVAL: UpValue[B] := R[A] */
    public static function setUpval(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->func->getUpval($b)->v = $ci->R[$a];
    }

    /** OP_GETTABUP: R[A] := UpValue[B][K[C]] (a short string) */
    public static function getTabUp(Coroutine $L, int $pc, int $a, int $b, string $key): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $t = $ci->func->getUpval($b)->v;
        if ($t instanceof LuaTable && (($v = $t->hash[$key] ?? null) !== null || $t->metatable === null)) {
            $ci->R[$a] = $v;
        } else {
            $ci->savedpc = $pc;
            $ci->R[$a] = Vm::finishGet($L, $t, $key, DebugInfo::upvalueSlot($b));
        }
    }

    /** OP_GETFIELD: R[A] := R[B][K[C]] (a short string); OP_GETI: R[A] := R[B][C] */
    public static function getField(Coroutine $L, int $pc, int $a, int $b, string|int $key): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $t = $R[$b];
        if ($t instanceof LuaTable && (($v = (\is_int($key) ? ($t->arr[$key] ?? null) : ($t->hash[$key] ?? null))) !== null || $t->metatable === null)) {
            $R[$a] = $v;
        } else {
            $ci->savedpc = $pc;
            $R[$a] = Vm::finishGet($L, $t, $key, $b);
        }
    }

    /** OP_GETTABLE: R[A] := R[B][R[C]] */
    public static function getTable(Coroutine $L, int $pc, int $a, int $b, int $c): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $t = $R[$b];
        $key = $R[$c];
        if ($t instanceof LuaTable && (($v = (\is_int($key) ? ($t->arr[$key] ?? null) : (\is_string($key) ? ($t->hash[$key] ?? null) : $t->get($key)))) !== null || $t->metatable === null)) {
            $R[$a] = $v;
        } else {
            $ci->savedpc = $pc;
            $R[$a] = Vm::finishGet($L, $t, $key, $b);
        }
    }

    /** OP_SETTABUP: UpValue[A][K[B]] := R[C] */
    public static function setTabUp(Coroutine $L, int $pc, int $a, string $key, int $c): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        self::setString($L, $ci, $pc, $ci->func->getUpval($a)->v, $key, $ci->R[$c], DebugInfo::upvalueSlot($a));
    }

    /** OP_SETTABUP with k: UpValue[A][K[B]] := K[C] */
    public static function setTabUpK(Coroutine $L, int $pc, int $a, string $key, mixed $value): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        self::setString($L, $ci, $pc, $ci->func->getUpval($a)->v, $key, $value, DebugInfo::upvalueSlot($a));
    }

    /** OP_SETFIELD: R[A][K[B]] := R[C] (a short string key); OP_SETI: R[A][B] := R[C] */
    public static function setField(Coroutine $L, int $pc, int $a, string|int $key, int $c): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        if (\is_int($key)) {
            self::setInteger($L, $ci, $pc, $R[$a], $key, $R[$c], $a);
        } else {
            self::setString($L, $ci, $pc, $R[$a], $key, $R[$c], $a);
        }
    }

    /** OP_SETFIELD, OP_SETI with k: R[A][key] := K[C] */
    public static function setFieldK(Coroutine $L, int $pc, int $a, string|int $key, mixed $value): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        if (\is_int($key)) {
            self::setInteger($L, $ci, $pc, $ci->R[$a], $key, $value, $a);
        } else {
            self::setString($L, $ci, $pc, $ci->R[$a], $key, $value, $a);
        }
    }

    /** OP_SETTABLE: R[A][R[B]] := R[C] */
    public static function setTable(Coroutine $L, int $pc, int $a, int $b, int $c): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        self::setAny($L, $ci, $pc, $R[$a], $R[$b], $R[$c], $a);
    }

    /** OP_SETTABLE with k: R[A][R[B]] := K[C] */
    public static function setTableK(Coroutine $L, int $pc, int $a, int $b, mixed $value): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        self::setAny($L, $ci, $pc, $R[$a], $R[$b], $value, $a);
    }

    /** t[key] = v for a string key (emitted code: emitSetField) */
    private static function setString(Coroutine $L, CallInfo $ci, int $pc, mixed $t, string $key, mixed $v, int $slot): void
    {
        if ($t instanceof LuaTable && ($t->metatable === null || isset($t->hash[$key]))) {
            if ($v !== null) {
                $t->hash[$key] = $v;
            } else {
                unset($t->hash[$key]);
            }
        } else {
            $ci->savedpc = $pc;
            Vm::setTable($L, $t, $key, $v, $slot);
        }
    }

    /** t[key] = v for an integer key (emitted code: OP_SETI) */
    private static function setInteger(Coroutine $L, CallInfo $ci, int $pc, mixed $t, int $key, mixed $v, int $slot): void
    {
        if ($t instanceof LuaTable && ($t->metatable === null || isset($t->arr[$key]))) {
            if ($v !== null) {
                $t->arr[$key] = $v;
            } else {
                unset($t->arr[$key]);
            }
        } else {
            $ci->savedpc = $pc;
            Vm::setTable($L, $t, $key, $v, $slot);
        }
    }

    /** t[key] = v for a key of any type (emitted code: OP_SETTABLE) */
    private static function setAny(Coroutine $L, CallInfo $ci, int $pc, mixed $t, mixed $key, mixed $v, int $slot): void
    {
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
            Vm::setTable($L, $t, $key, $v, $slot);
        }
    }

    /** OP_NEWTABLE: R[A] := {} with room for $arraySize array elements, charging $size bytes to the GC (lvm.c: checkGC) */
    public static function newTable(Coroutine $L, int $pc, int $a, int $arraySize, int $size): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->R[$a] = new LuaTable($arraySize);
        if (($L->globalState->gcDebt += $size) > 0) {
            $ci->savedpc = $pc;
            Collector::step($L, $a + 1);
        }
    }

    /** OP_SELF with k: R[A+1] := R[B]; R[A] := R[B][K[C]] */
    public static function self(Coroutine $L, int $pc, int $a, int $b, string $key): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $t = $R[$b];
        $R[$a + 1] = $t;
        if ($t instanceof LuaTable && (($v = $t->hash[$key] ?? null) !== null || $t->metatable === null)) {
            $R[$a] = $v;
        } else {
            $ci->savedpc = $pc;
            $R[$a] = Vm::finishGet($L, $t, $key, $b);
        }
    }

    /**
     * OP_ADD ... OP_SHR, then the OP_MMBIN that follows when the operands
     * are not numbers: R[A] := R[B] op R[C], $operation a Vm::LUA_OP*
     * (ORDER OP, ORDER TM).
     */
    public static function arith(Coroutine $L, int $pc, int $a, int $b, int $c, int $operation): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $ci->savedpc = $pc;  // (an integer division or modulo by zero raises)
        $result = Vm::rawArith($L, $operation, $R[$b], $R[$c]);
        if ($result !== null) {
            $R[$a] = $result;
            return;
        }
        $pc++;  // C's vmfetch fetches the OP_MMBIN only when the arithmetic fails
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->savedpc = $pc;
        $R[$a] = MetaMethods::tryBinTM($L, $R[$b], $R[$c], MetaMethods::TM_ADD + $operation, $b, $c);
    }

    /**
     * OP_ADDK ... OP_BXORK, then the OP_MMBINK that follows when the
     * operand is not a number: R[A] := R[B] op K[C] ($flip: the metamethod
     * gets the constant first).
     */
    public static function arithK(Coroutine $L, int $pc, int $a, int $b, int|float $constant, int $operation, bool $flip): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $ci->savedpc = $pc;
        $x = $R[$b];
        if (\is_int($x) && \is_int($constant) && $operation <= Vm::LUA_OPMOD) {  // (the inline code's integer paths)
            $result = match ($operation) {
                Vm::LUA_OPADD => \is_int($v = $x + $constant) ? $v : Vm::addWrap($x, $constant),
                Vm::LUA_OPSUB => \is_int($v = $x - $constant) ? $v : Vm::subWrap($x, $constant),
                Vm::LUA_OPMUL => \is_int($v = $x * $constant) ? $v : Vm::mulWrap($x, $constant),
                Vm::LUA_OPMOD => Vm::mod($L, $x, $constant),
            };
        } else {
            $result = Vm::rawArith($L, $operation, $x, $constant);
        }
        if ($result !== null) {
            $R[$a] = $result;
            return;
        }
        $pc++;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->savedpc = $pc;
        $R[$a] = MetaMethods::tryBinAssocTM($L, $R[$b], $constant, $flip, MetaMethods::TM_ADD + $operation, $b);
    }

    /**
     * OP_ADDI, OP_SHRI (R[A] := R[B] op sC) and OP_SHLI (R[A] := sC << R[B]),
     * then the OP_MMBINI that follows when the operand is not a number.
     */
    public static function arithI(Coroutine $L, int $pc, int $a, int $b, int $immediate, int $operation, bool $flip): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $x = $R[$b];
        if (\is_int($x) && $operation <= Vm::LUA_OPSUB) {  // (the inline code's integer path)
            $v = $operation === Vm::LUA_OPADD ? $x + $immediate : $x - $immediate;
            $R[$a] = \is_int($v) ? $v : ($operation === Vm::LUA_OPADD ? Vm::addWrap($x, $immediate) : Vm::subWrap($x, $immediate));
            return;
        }
        $result = $operation === Vm::LUA_OPSHL
            ? Vm::rawArith($L, $operation, $immediate, $x)
            : Vm::rawArith($L, $operation, $x, $immediate);
        if ($result !== null) {
            $R[$a] = $result;
            return;
        }
        $pc++;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->savedpc = $pc;
        $R[$a] = MetaMethods::tryBinITM($L, $R[$b], $immediate, $flip, MetaMethods::TM_ADD + $operation, $b);
    }

    /** OP_UNM: R[A] := -R[B] */
    public static function unm(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $x = $R[$b];
        if (\is_int($x)) {
            $R[$a] = $x === \PHP_INT_MIN ? $x : -$x;
        } elseif (\is_float($x)) {
            $R[$a] = $x === $x ? -$x : Vm::floatNegate($x);  // (only a NaN differs from itself)
        } else {
            $ci->savedpc = $pc;
            $R[$a] = MetaMethods::tryBinTM($L, $x, $x, MetaMethods::TM_UNM, $b, $b);
        }
    }

    /** OP_BNOT: R[A] := ~R[B] */
    public static function bnot(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $x = $R[$b];
        if (\is_int($x)) {
            $R[$a] = ~$x;
        } elseif (($i1 = Vm::toIntegerNoString($x)) !== null) {
            $R[$a] = ~$i1;
        } else {
            $ci->savedpc = $pc;
            $R[$a] = MetaMethods::tryBinTM($L, $x, $x, MetaMethods::TM_BNOT, $b, $b);
        }
    }

    /** OP_NOT: R[A] := not R[B] */
    public static function not(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $x = $R[$b];
        $R[$a] = $x === null || $x === false;
    }

    /** OP_LEN: R[A] := #R[B] */
    public static function len(Coroutine $L, int $pc, int $a, int $b): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $x = $R[$b];
        if (\is_string($x)) {
            $R[$a] = \strlen($x);
        } elseif ($x instanceof LuaTable && $x->metatable === null) {
            $R[$a] = $x->length();
        } else {
            $ci->savedpc = $pc;
            $R[$a] = Vm::objectLength($L, $x, $b);
        }
    }

    /** OP_CONCAT: R[A] := R[A].. ... ..R[A + n - 1] (small results of strings joined here, as inline: see MemoryLimit) */
    public static function concat(Coroutine $L, int $pc, int $a, int $n): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $values = [];
        $length = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = $R[$a + $i];
            $values[] = $v;
            $length = ($length !== null && \is_string($v)) ? $length + \strlen($v) : null;
        }
        if ($length !== null && $length <= MemoryLimit::CHECK_ABOVE) {
            $R[$a] = implode('', $values);
        } else {
            $ci->savedpc = $pc;
            $R[$a] = Vm::concat($L, $values, $a);
        }
        $v = $R[$a];
        if (($L->globalState->gcDebt += (\is_string($v) ? Collector::STRING_OVERHEAD + \strlen($v) : 0)) > 0) {  // lvm.c: checkGC
            $ci->savedpc = $pc;
            Collector::step($L, $a + 1);
        }
    }

    /** OP_CLOSE: close all upvalues >= R[A] */
    public static function close(Coroutine $L, int $pc, int $a): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        if ($ci->openupval !== [] || $ci->tbclist !== []) {
            $ci->savedpc = $pc;
            Upvalues::close($L, $ci, $a);
        }
    }

    /** OP_TBC: mark variable A "to be closed" */
    public static function tbc(Coroutine $L, int $pc, int $a): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $ci->savedpc = $pc;
        Upvalues::newTbc($L, $ci, $a);
    }

    /** OP_JMP: its hook check (the emitted code jumps) */
    public static function jump(Coroutine $L, int $pc): void
    {
        if ($L->trap) {
            Hooks::traceExec($L, $L->ci, $pc);
        }
    }

    /** OP_EQ's condition: R[A] == R[B] */
    public static function eq(Coroutine $L, int $pc, int $a, int $b): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        $y = $ci->R[$b];
        if ($x === $y) {
            return true;
        }
        if (\is_object($x) || \is_float($x) || \is_float($y)) {
            $ci->savedpc = $pc;
            return Vm::equalObjects($L, $x, $y);
        }
        return false;
    }

    /** OP_LT's condition: R[A] < R[B] */
    public static function lt(Coroutine $L, int $pc, int $a, int $b): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        $y = $ci->R[$b];
        if ((\is_int($x) && \is_int($y)) || (\is_float($x) && \is_float($y))) {
            return $x < $y;
        }
        $ci->savedpc = $pc;
        return Vm::lessThan($L, $x, $y);
    }

    /** OP_LE's condition: R[A] <= R[B] */
    public static function le(Coroutine $L, int $pc, int $a, int $b): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        $y = $ci->R[$b];
        if ((\is_int($x) && \is_int($y)) || (\is_float($x) && \is_float($y))) {
            return $x <= $y;
        }
        $ci->savedpc = $pc;
        return Vm::lessEqual($L, $x, $y);
    }

    /** OP_EQK's condition (R[A] == K[B]) and OP_EQI's (R[A] == sB): raw equality, an integer equal to the float of the same value */
    public static function eqK(Coroutine $L, int $pc, int $a, mixed $constant): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        return Vm::rawEquals($ci->R[$a], $constant);
    }

    /** OP_LTI's condition: R[A] < sB ($isFloat: C, whether sB stands for a float) */
    public static function ltI(Coroutine $L, int $pc, int $a, int $immediate, bool $isFloat): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        if (\is_int($x) || \is_float($x)) {
            return $x < $immediate;
        }
        $ci->savedpc = $pc;
        return MetaMethods::callOrderITM($L, $x, $immediate, false, $isFloat, MetaMethods::TM_LT);
    }

    /** OP_LEI's condition: R[A] <= sB */
    public static function leI(Coroutine $L, int $pc, int $a, int $immediate, bool $isFloat): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        if (\is_int($x) || \is_float($x)) {
            return $x <= $immediate;
        }
        $ci->savedpc = $pc;
        return MetaMethods::callOrderITM($L, $x, $immediate, false, $isFloat, MetaMethods::TM_LE);
    }

    /** OP_GTI's condition: R[A] > sB */
    public static function gtI(Coroutine $L, int $pc, int $a, int $immediate, bool $isFloat): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        if (\is_int($x) || \is_float($x)) {
            return $x > $immediate;
        }
        $ci->savedpc = $pc;
        return MetaMethods::callOrderITM($L, $x, $immediate, true, $isFloat, MetaMethods::TM_LT);
    }

    /** OP_GEI's condition: R[A] >= sB */
    public static function geI(Coroutine $L, int $pc, int $a, int $immediate, bool $isFloat): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        if (\is_int($x) || \is_float($x)) {
            return $x >= $immediate;
        }
        $ci->savedpc = $pc;
        return MetaMethods::callOrderITM($L, $x, $immediate, true, $isFloat, MetaMethods::TM_LE);
    }

    /** OP_TEST's condition: R[A] is not nil or false */
    public static function test(Coroutine $L, int $pc, int $a): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $x = $ci->R[$a];
        return $x !== null && $x !== false;
    }

    /**
     * OP_TESTSET: whether it skips the next instruction (the truthiness of
     * R[B] differs from $k), else R[A] := R[B] (and the emitted code does
     * the next instruction's jump)
     */
    public static function testSet(Coroutine $L, int $pc, int $a, int $b, bool $k): bool
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $x = $R[$b];
        if (($x !== null && $x !== false) !== $k) {
            return true;
        }
        $R[$a] = $x;
        return false;
    }

    /**
     * OP_CALL: R[A], ..., R[A+C-2] := R[A](R[A+1], ..., R[A+B-1]); B = 0:
     * the arguments up to $top; C = 0: all results, returning the new top
     * (else $top as it was).
     */
    public static function call(Coroutine $L, int $pc, int $a, int $b, int $c, int $top = 0): int
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc, $top);
        }
        $R = &$ci->R;
        $ci->savedpc = $pc;
        $args = [];
        if ($b === 0) {
            for ($i = $a + 1; $i < $top; $i++) {
                $args[] = $R[$i];
            }
        } else {
            for ($i = 1; $i < $b; $i++) {
                $args[] = $R[$a + $i];
            }
        }
        $f = $R[$a];
        $ret = $f instanceof LuaClosure ? (($f->code)($L, $f, $args) ?? Calls::finishTailCall($L)) : Calls::callNonLua($L, $f, $args);
        if ($c === 0) {  // all results
            $n = \count($ret);
            for ($i = 0; $i < $n; $i++) {
                $R[$a + $i] = $ret[$i];
            }
            return $a + $n;
        }
        for ($i = 0; $i < $c - 1; $i++) {
            $R[$a + $i] = $ret[$i] ?? null;
        }
        return $top;
    }

    /** OP_SETLIST: R[A][$index + i] := R[A+i], 1 <= i <= $n; $n = 0: up to $top */
    public static function setList(Coroutine $L, int $pc, int $a, int $n, int $index, int $top = 0): void
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc, $top);
        }
        $R = &$ci->R;
        if ($n === 0) {
            $n = $top - $a - 1;
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
    }

    /** OP_VARARG: R[A], ..., R[A+$wanted-1] = vararg; $wanted = -1: all of them, returning the new top (else $top as it was) */
    public static function vararg(Coroutine $L, int $pc, int $a, int $wanted, int $top = 0): int
    {
        $ci = $L->ci;
        if ($L->trap) {
            Hooks::traceExec($L, $ci, $pc);
        }
        $R = &$ci->R;
        $va = $ci->varargs;
        if ($wanted < 0) {
            $n = \count($va);
            for ($i = 0; $i < $n; $i++) {
                $R[$a + $i] = $va[$i];
            }
            return $a + $n;
        }
        for ($i = 0; $i < $wanted; $i++) {
            $R[$a + $i] = $va[$i] ?? null;
        }
        return $top;
    }
}
