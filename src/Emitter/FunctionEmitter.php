<?php

declare(strict_types=1);

namespace LuaPhp\Emitter;

use LuaPhp\Compiler\ChunkId;
use LuaPhp\Compiler\OpCodes;
use LuaPhp\Compiler\Proto;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MetaMethods;

/**
 * Emits the PHP closure for one Proto: lvm.c's luaV_execute with the
 * dispatch loop unrolled. Each instruction becomes a few PHP statements
 * preceded by a "// [pc] OPNAME args ; line N" comment; jumps become
 * `goto L<pc>`. Registers are the elements of the PHP array $R; the
 * running CallInfo is $ci, the closure $cl, the thread $L.
 *
 * Fast paths (integer/float arithmetic, plain table access, string
 * concatenation, ...) are inline; everything else calls the runtime
 * (Vm, MetaMethods, Calls, Upvalues), after storing the instruction's
 * index in $ci->savedpc so errors, tracebacks and debug.getinfo see the
 * exact position.
 */
final class FunctionEmitter
{
    private const INDENT = '        ';

    /** @var array<int, true> instructions that are jump targets */
    private array $jumpTargets = [];

    /** @var array<int, true> instructions already emitted as part of the previous one */
    private array $consumed = [];

    public function __construct(
        private readonly Proto $proto,
        private readonly string $path,
    ) {
    }

    public function emit(): string
    {
        $proto = $this->proto;
        $this->findJumpTargets();

        $uses = [];
        foreach (array_keys($proto->p) as $childIndex) {
            $uses[] = '$proto_' . $this->path . '_' . $childIndex;
            $uses[] = '$function_' . $this->path . '_' . $childIndex;
        }
        $useClause = $uses === [] ? '' : ' use (' . implode(', ', $uses) . ')';

        $sourceName = PhpLiteral::commentText(ChunkId::of($proto->source ?? '=?'), 60);
        $code = "    \$function_{$this->path} = static function (Coroutine \$L, LuaClosure \$cl, array \$R, int \$callstatus = 0)$useClause: ?array {\n";
        $code .= self::INDENT . "// function <$sourceName:{$proto->linedefined},{$proto->lastlinedefined}>"
            . " numparams={$proto->numparams}" . ($proto->is_vararg ? ' vararg' : '')
            . " maxstacksize={$proto->maxstacksize} instructions=" . \count($proto->code) . "\n";
        // ldo.c: luaD_precall for a Lua function (CallInfo::push, inline)
        $code .= self::lines(
            '$ci = new CallInfo(); $ci->func = $cl; $ci->callstatus = $callstatus;',
            '$ci->previous = $previous = $L->ci; $ci->top = $previous->top + ' . ($proto->maxstacksize + 1) . '; $ci->frameBytes = $previous->frameBytes + ' . self::frameBytes($proto) . ';',
            'if ($ci->top > $L->stackLimit || $ci->frameBytes > $L->frameBytesLimit) {',
            '    Calls::stackOverflow($L);',
            '}',
            '$L->ci = $ci;',
            '$ci->R = &$R;',
        );
        if (!$proto->is_vararg && $proto->numparams > 0) {
            // complete missing arguments
            $code .= self::INDENT . "if (\\count(\$R) < {$proto->numparams}) {\n"
                . self::INDENT . '    $R += [' . implode(', ', array_fill(0, $proto->numparams, 'null')) . "];\n"
                . self::INDENT . "}\n";
        }
        // lvm.c: luaV_execute's 'trap' (see hookCheck)
        $code .= self::lines('$trap = &$L->trap;');
        if (!$proto->is_vararg) {
            // ldebug.c: luaG_tracecall (vararg functions call the hook at OP_VARARGPREP)
            $code .= self::lines('if ($L->hookmask !== 0) {', '    Hooks::hookCall($L, $ci, 0);', '}');
        }

        $instructionCount = \count($proto->code);
        for ($pc = 0; $pc < $instructionCount; $pc++) {
            if (isset($this->jumpTargets[$pc])) {
                $code .= "    L$pc:\n";
            }
            $code .= $this->comment($pc);
            if (isset($this->consumed[$pc])) {
                continue;
            }
            $code .= $this->hookCheck($pc);
            $code .= $this->emitInstruction($pc);
        }
        $code .= "    };\n";
        return $code;
    }

    /**
     * Estimated PHP memory of one activation of $proto's function: the
     * CallInfo and register array, plus the PHP frame, which for eval'd
     * (unoptimized) code has a slot for every temporary and so grows with
     * the code (measured: about 150-190 bytes per instruction).
     */
    public static function frameBytes(Proto $proto): int
    {
        return 1000 + 16 * $proto->maxstacksize + 160 * \count($proto->code);
    }

    /**
     * The line/count hook check before instruction $pc (lvm.c: vmfetch
     * calling ldebug.c: luaG_traceexec when 'trap' is set). $trap is a
     * reference to $L->trap, so a hook set while the function runs takes
     * effect at its next instruction.
     *
     * Hooks must see exactly the instructions C's vmfetch fetches. It never
     * fetches OP_VARARGPREP with hooks on (luaG_tracecall turns 'trap' off
     * until it has run), nor OP_TFORLOOP (OP_TFORCALL goes straight to it),
     * nor instructions the previous one consumes: OP_EXTRAARG, the OP_MMBIN*
     * after a successful arithmetic instruction (checked in
     * metamethodFallback instead), the OP_JMP after a test when the jump is
     * taken (conditionalJump jumps directly), OP_TFORCALL when entered from
     * OP_TFORPREP (which jumps past this check, to T<pc>).
     */
    private function hookCheck(int $pc): string
    {
        $opcode = OpCodes::GET_OPCODE($this->proto->code[$pc]);
        if ($opcode === OpCodes::OP_VARARGPREP || $opcode === OpCodes::OP_TFORLOOP) {
            return '';
        }
        return self::lines("if (\$trap) { Hooks::traceExec(\$L, \$ci, $pc); }");
    }

    /** marks every instruction some jump can reach */
    private function findJumpTargets(): void
    {
        $code = $this->proto->code;
        foreach ($code as $pc => $instruction) {
            $opcode = OpCodes::GET_OPCODE($instruction);
            switch ($opcode) {
                case OpCodes::OP_JMP:
                    $this->jumpTargets[$pc + 1 + OpCodes::GETARG_sJ($instruction)] = true;
                    break;
                case OpCodes::OP_FORLOOP:
                case OpCodes::OP_TFORLOOP:
                    $this->jumpTargets[$pc + 1 - OpCodes::GETARG_Bx($instruction)] = true;
                    break;
                case OpCodes::OP_FORPREP:
                    $this->jumpTargets[$pc + OpCodes::GETARG_Bx($instruction) + 2] = true;
                    break;
                case OpCodes::OP_LFALSESKIP:
                case OpCodes::OP_EQ:
                case OpCodes::OP_LT:
                case OpCodes::OP_LE:
                case OpCodes::OP_EQK:
                case OpCodes::OP_EQI:
                case OpCodes::OP_LTI:
                case OpCodes::OP_LEI:
                case OpCodes::OP_GTI:
                case OpCodes::OP_GEI:
                case OpCodes::OP_TEST:
                case OpCodes::OP_TESTSET:
                    $this->jumpTargets[$pc + 2] = true;
                    break;
            }
        }
        // an arithmetic instruction followed by its OP_MMBIN* emits the
        // metamethod call in its 'else' branch, unless something jumps there
        foreach ($code as $pc => $instruction) {
            $nextPc = $pc + 1;
            if ($nextPc < \count($code)
                && OpCodes::testMMMode(OpCodes::GET_OPCODE($code[$nextPc]))
                && !isset($this->jumpTargets[$nextPc])) {
                $this->consumed[$nextPc] = true;
            } elseif ($nextPc < \count($code) && OpCodes::testMMMode(OpCodes::GET_OPCODE($code[$nextPc]))) {
                $this->jumpTargets[$pc + 2] = true;  // success path skips the MMBIN with a goto
            }
        }
        foreach ($code as $pc => $instruction) {
            $opcode = OpCodes::GET_OPCODE($instruction);
            if (($opcode === OpCodes::OP_LOADKX || $opcode === OpCodes::OP_NEWTABLE || $opcode === OpCodes::OP_SETLIST)
                && $pc + 1 < \count($code) && OpCodes::GET_OPCODE($code[$pc + 1]) === OpCodes::OP_EXTRAARG) {
                $this->consumed[$pc + 1] = true;
            }
        }
    }

    private function comment(int $pc): string
    {
        $instruction = $this->proto->code[$pc];
        $opcode = OpCodes::GET_OPCODE($instruction);
        $name = OpCodes::OPNAMES[$opcode];
        $arguments = match (OpCodes::getOpMode($opcode)) {
            OpCodes::iABC => OpCodes::GETARG_A($instruction) . ' ' . OpCodes::GETARG_B($instruction) . ' ' . OpCodes::GETARG_C($instruction)
                . (OpCodes::GETARG_k($instruction) ? ' k' : ''),
            OpCodes::iABx => OpCodes::GETARG_A($instruction) . ' ' . OpCodes::GETARG_Bx($instruction),
            OpCodes::iAsBx => OpCodes::GETARG_A($instruction) . ' ' . OpCodes::GETARG_sBx($instruction),
            OpCodes::iAx => (string) OpCodes::GETARG_Ax($instruction),
            OpCodes::isJ => (string) OpCodes::GETARG_sJ($instruction),
        };
        $line = DebugInfo::getFuncLine($this->proto, $pc);
        return self::INDENT . "// [$pc] $name $arguments ; line $line\n";
    }

    private static function r(int $register): string
    {
        return "\$R[$register]";
    }

    /** PHP literal of constant $index */
    private function k(int $index): string
    {
        return PhpLiteral::of($this->proto->k[$index]);
    }

    /** a numeric literal usable as an operand (negative numbers parenthesized) */
    private static function operand(int|float $value): string
    {
        $literal = PhpLiteral::of($value);
        return str_starts_with($literal, '-') ? "($literal)" : $literal;
    }

    /** R[C] or K[C] depending on the k bit */
    private function rkC(int $instruction): string
    {
        $c = OpCodes::GETARG_C($instruction);
        return OpCodes::GETARG_k($instruction) ? $this->k($c) : self::r($c);
    }

    private static function savePc(int $pc): string
    {
        return "\$ci->savedpc = $pc;";
    }

    /**
     * lvm.c: checkGC(L, top) after an allocation: charge its size (a PHP
     * expression) to the collector's debt and run a collection step when
     * the debt becomes positive; registers from $top up are dead.
     */
    private static function checkGc(int $pc, int $top, string $size): string
    {
        return "if ((\$L->globalState->gcDebt += $size) > 0) { " . self::savePc($pc) . " Collector::step(\$L, $top); }";
    }

    /** lines of code, indented */
    private static function lines(string ...$lines): string
    {
        $code = '';
        foreach ($lines as $line) {
            $code .= self::INDENT . $line . "\n";
        }
        return $code;
    }

    /** truthiness test (Lua: not nil and not false) */
    private static function truthy(string $expression): string
    {
        return "($expression !== null && $expression !== false)";
    }

    private static function falsy(string $expression): string
    {
        return "($expression === null || $expression === false)";
    }

    /**
     * The code that runs when an arithmetic fast path fails: the following
     * OP_MMBIN* instruction (lvm.c: OP_MMBIN, OP_MMBINI, OP_MMBINK), which
     * stores the metamethod's result in the arithmetic instruction's A.
     */
    private function metamethodFallback(int $pc): string
    {
        $mmPc = $pc + 1;
        $resultRegister = OpCodes::GETARG_A($this->proto->code[$pc]);
        if (!isset($this->consumed[$mmPc])) {
            return '';  // emitted on its own (it is a jump target)
        }
        // C's vmfetch fetches (and traces) the OP_MMBIN* only when the fast path fails
        return "if (\$trap) { Hooks::traceExec(\$L, \$ci, $mmPc); } " . $this->emitMetamethodCall($mmPc, $resultRegister);
    }

    private function emitMetamethodCall(int $mmPc, int $resultRegister): string
    {
        $instruction = $this->proto->code[$mmPc];
        $a = OpCodes::GETARG_A($instruction);
        $event = OpCodes::GETARG_C($instruction);
        $flip = OpCodes::GETARG_k($instruction) ? 'true' : 'false';
        $call = match (OpCodes::GET_OPCODE($instruction)) {
            OpCodes::OP_MMBIN => 'MetaMethods::tryBinTM($L, ' . self::r($a) . ', ' . self::r(OpCodes::GETARG_B($instruction)) . ", $event, $a, " . OpCodes::GETARG_B($instruction) . ')',
            OpCodes::OP_MMBINI => 'MetaMethods::tryBinITM($L, ' . self::r($a) . ', ' . OpCodes::GETARG_sB($instruction) . ", $flip, $event, $a)",
            OpCodes::OP_MMBINK => 'MetaMethods::tryBinAssocTM($L, ' . self::r($a) . ', ' . $this->k(OpCodes::GETARG_B($instruction)) . ", $flip, $event, $a)",
        };
        return self::savePc($mmPc) . ' ' . self::r($resultRegister) . " = $call;";
    }

    /**
     * An arithmetic instruction with its fast paths; $fastPaths are
     * [condition, statement] pairs tried in order, then the metamethod.
     *
     * @param list<array{string, string}> $fastPaths
     */
    private function arithmetic(int $pc, string $load, array $fastPaths): string
    {
        $code = self::lines($load);
        $first = true;
        foreach ($fastPaths as [$condition, $statement]) {
            $code .= self::INDENT . ($first ? '' : '} else') . "if ($condition) {\n";
            $code .= self::INDENT . "    $statement\n";
            $first = false;
        }
        $fallback = $this->metamethodFallback($pc);
        if ($fallback === '') {
            // the MMBIN at pc + 1 is emitted on its own (it is a jump
            // target): fall into it on failure, skip it on success
            $code .= self::INDENT . "} else {\n" . self::INDENT . '    goto L' . ($pc + 1) . ";\n" . self::INDENT . "}\n";
            return $code . self::lines('goto L' . ($pc + 2) . ';');
        }
        return $code . self::INDENT . "} else {\n" . self::INDENT . "    $fallback\n" . self::INDENT . "}\n";
    }

    private function emitInstruction(int $pc): string
    {
        $proto = $this->proto;
        $instruction = $proto->code[$pc];
        $opcode = OpCodes::GET_OPCODE($instruction);
        $a = OpCodes::GETARG_A($instruction);
        $b = OpCodes::GETARG_B($instruction);
        $c = OpCodes::GETARG_C($instruction);
        $k = OpCodes::GETARG_k($instruction);
        $ra = self::r($a);

        switch ($opcode) {
            case OpCodes::OP_MOVE:
                return self::lines("$ra = " . self::r($b) . ';');

            case OpCodes::OP_LOADI:
                return self::lines("$ra = " . PhpLiteral::of(OpCodes::GETARG_sBx($instruction)) . ';');

            case OpCodes::OP_LOADF:
                return self::lines("$ra = " . PhpLiteral::of((float) OpCodes::GETARG_sBx($instruction)) . ';');

            case OpCodes::OP_LOADK:
                return self::lines("$ra = " . $this->k(OpCodes::GETARG_Bx($instruction)) . ';');

            case OpCodes::OP_LOADKX:
                return self::lines("$ra = " . $this->k(OpCodes::GETARG_Ax($proto->code[$pc + 1])) . ';');

            case OpCodes::OP_LOADFALSE:
                return self::lines("$ra = false;");

            case OpCodes::OP_LFALSESKIP:
                return self::lines("$ra = false;", 'goto L' . ($pc + 2) . ';');

            case OpCodes::OP_LOADTRUE:
                return self::lines("$ra = true;");

            case OpCodes::OP_LOADNIL:
                $targets = [];
                for ($i = 0; $i <= $b; $i++) {
                    $targets[] = self::r($a + $i);
                }
                return self::lines(implode(' = ', $targets) . ' = null;');

            case OpCodes::OP_GETUPVAL:
                return self::lines("$ra = \$cl->upvals[$b]->v;");

            case OpCodes::OP_SETUPVAL:
                return self::lines("\$cl->upvals[$b]->v = $ra;");

            case OpCodes::OP_GETTABUP:
                return $this->emitGetField($pc, $ra, "\$cl->upvals[$b]->v", $this->k($c), DebugInfo::upvalueSlot($b));

            case OpCodes::OP_GETTABLE:
                return self::lines(
                    '$t = ' . self::r($b) . '; $key = ' . self::r($c) . ';',
                    "if (\$t instanceof LuaTable && ((\$v = (\\is_int(\$key) ? (\$t->arr[\$key] ?? null) : (\\is_string(\$key) ? (\$t->hash[\$key] ?? null) : \$t->get(\$key)))) !== null || \$t->metatable === null)) {",
                    "    $ra = \$v;",
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = Vm::finishGet(\$L, \$t, \$key, $b);",
                    '}',
                );

            case OpCodes::OP_GETI:
                return self::lines(
                    '$t = ' . self::r($b) . ';',
                    "if (\$t instanceof LuaTable && ((\$v = \$t->arr[$c] ?? null) !== null || \$t->metatable === null)) {",
                    "    $ra = \$v;",
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = Vm::finishGet(\$L, \$t, $c, $b);",
                    '}',
                );

            case OpCodes::OP_GETFIELD:
                return $this->emitGetField($pc, $ra, self::r($b), $this->k($c), $b);

            case OpCodes::OP_SETTABUP:
                return $this->emitSetField($pc, "\$cl->upvals[$a]->v", $this->k($b), $this->rkC($instruction), DebugInfo::upvalueSlot($a), $k && $this->proto->k[$c] !== null);

            case OpCodes::OP_SETTABLE:
                return self::lines(
                    "\$t = $ra; \$key = " . self::r($b) . '; $v = ' . $this->rkC($instruction) . ';',
                    'if ($t instanceof LuaTable && \is_int($key) && ($t->metatable === null || isset($t->arr[$key]))) {',
                    '    if ($v !== null) { $t->arr[$key] = $v; } else { unset($t->arr[$key]); }',
                    '} elseif ($t instanceof LuaTable && \is_string($key) && ($t->metatable === null || isset($t->hash[$key]))) {',
                    '    if ($v !== null) { $t->hash[$key] = $v; } else { unset($t->hash[$key]); }',
                    '} else {',
                    '    ' . self::savePc($pc) . " Vm::setTable(\$L, \$t, \$key, \$v, $a);",
                    '}',
                );

            case OpCodes::OP_SETI:
                return self::lines(
                    "\$t = $ra; \$v = " . $this->rkC($instruction) . ';',
                    "if (\$t instanceof LuaTable && (\$t->metatable === null || isset(\$t->arr[$b]))) {",
                    "    if (\$v !== null) { \$t->arr[$b] = \$v; } else { unset(\$t->arr[$b]); }",
                    '} else {',
                    '    ' . self::savePc($pc) . " Vm::setTable(\$L, \$t, $b, \$v, $a);",
                    '}',
                );

            case OpCodes::OP_SETFIELD:
                return $this->emitSetField($pc, $ra, $this->k($b), $this->rkC($instruction), $a, $k && $this->proto->k[$c] !== null);

            case OpCodes::OP_NEWTABLE:
                $arraySize = $c;
                if ($k) {  // non-zero extra argument?
                    $arraySize += OpCodes::GETARG_Ax($proto->code[$pc + 1]) * (OpCodes::MAXARG_C + 1);
                }
                $hashSize = $b > 0 ? 1 << ($b - 1) : 0;  // size is 2^(b - 1)
                return self::lines(
                    "$ra = new LuaTable($arraySize);",
                    self::checkGc($pc, $a + 1, (string) Collector::tableSize($arraySize, $hashSize)),
                );

            case OpCodes::OP_SELF:
                $key = $k ? $this->k($c) : self::r($c);
                return self::lines(
                    '$t = ' . self::r($b) . '; $key = ' . $key . ';',
                    self::r($a + 1) . ' = $t;',
                    "if (\$t instanceof LuaTable && ((\$v = \$t->hash[\$key] ?? null) !== null || \$t->metatable === null)) {",
                    "    $ra = \$v;",
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = Vm::finishGet(\$L, \$t, \$key, $b);",
                    '}',
                );

            case OpCodes::OP_ADDI:
                $immediate = OpCodes::GETARG_sC($instruction);
                return $this->arithmetic($pc, '$x = ' . self::r($b) . ';', [
                    ['\is_int($x)', '$v = $x + ' . self::operand($immediate) . "; $ra = \\is_int(\$v) ? \$v : Vm::addWrap(\$x, " . self::operand($immediate) . ');'],
                    ['\is_float($x)', "$ra = \$x + " . self::operand((float) $immediate) . ';'],
                ]);

            case OpCodes::OP_ADDK:
            case OpCodes::OP_SUBK:
            case OpCodes::OP_MULK:
            case OpCodes::OP_MODK:
            case OpCodes::OP_POWK:
            case OpCodes::OP_DIVK:
            case OpCodes::OP_IDIVK:
                return $this->emitArithmeticConstant($pc, $opcode, $ra, $b, $proto->k[$c]);

            case OpCodes::OP_BANDK:
            case OpCodes::OP_BORK:
            case OpCodes::OP_BXORK:
                $operator = [OpCodes::OP_BANDK => '&', OpCodes::OP_BORK => '|', OpCodes::OP_BXORK => '^'][$opcode];
                $constant = self::operand($proto->k[$c]);
                return $this->arithmetic($pc, '$x = ' . self::r($b) . ';', [
                    ['\is_int($x)', "$ra = \$x $operator $constant;"],
                    ['($i1 = Vm::toIntegerNoString($x)) !== null', "$ra = \$i1 $operator $constant;"],
                ]);

            case OpCodes::OP_SHRI:
                $immediate = OpCodes::GETARG_sC($instruction);
                return $this->arithmetic($pc, '$x = ' . self::r($b) . ';', [
                    ['\is_int($x)', "$ra = Vm::shiftLeft(\$x, " . self::operand(-$immediate) . ');'],
                    ['($i1 = Vm::toIntegerNoString($x)) !== null', "$ra = Vm::shiftLeft(\$i1, " . self::operand(-$immediate) . ');'],
                ]);

            case OpCodes::OP_SHLI:
                $immediate = OpCodes::GETARG_sC($instruction);
                return $this->arithmetic($pc, '$x = ' . self::r($b) . ';', [
                    ['\is_int($x)', "$ra = Vm::shiftLeft(" . self::operand($immediate) . ', $x);'],
                    ['($i1 = Vm::toIntegerNoString($x)) !== null', "$ra = Vm::shiftLeft(" . self::operand($immediate) . ', $i1);'],
                ]);

            case OpCodes::OP_ADD:
            case OpCodes::OP_SUB:
            case OpCodes::OP_MUL:
                $operator = [OpCodes::OP_ADD => '+', OpCodes::OP_SUB => '-', OpCodes::OP_MUL => '*'][$opcode];
                $wrap = [OpCodes::OP_ADD => 'addWrap', OpCodes::OP_SUB => 'subWrap', OpCodes::OP_MUL => 'mulWrap'][$opcode];
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['\is_int($x) && \is_int($y)', "\$v = \$x $operator \$y; $ra = \\is_int(\$v) ? \$v : Vm::$wrap(\$x, \$y);"],
                    ['(\is_float($x) || \is_int($x)) && (\is_float($y) || \is_int($y))', "$ra = \$x $operator \$y;"],
                ]);

            case OpCodes::OP_MOD:
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['\is_int($x) && \is_int($y)', self::savePc($pc) . " $ra = Vm::mod(\$L, \$x, \$y);"],
                    ['(\is_float($x) || \is_int($x)) && (\is_float($y) || \is_int($y))', "$ra = Vm::modf(\$x, \$y);"],
                ]);

            case OpCodes::OP_IDIV:
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['\is_int($x) && \is_int($y)', self::savePc($pc) . " $ra = Vm::idiv(\$L, \$x, \$y);"],
                    ['(\is_float($x) || \is_int($x)) && (\is_float($y) || \is_int($y))', "$ra = \\floor(\\fdiv(\$x, \$y));"],
                ]);

            case OpCodes::OP_POW:
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['(\is_float($x) || \is_int($x)) && (\is_float($y) || \is_int($y))', "$ra = Vm::floatPow(\$x, \$y);"],
                ]);

            case OpCodes::OP_DIV:
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['(\is_float($x) || \is_int($x)) && (\is_float($y) || \is_int($y))', "$ra = \\fdiv(\$x, \$y);"],
                ]);

            case OpCodes::OP_BAND:
            case OpCodes::OP_BOR:
            case OpCodes::OP_BXOR:
                $operator = [OpCodes::OP_BAND => '&', OpCodes::OP_BOR => '|', OpCodes::OP_BXOR => '^'][$opcode];
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['\is_int($x) && \is_int($y)', "$ra = \$x $operator \$y;"],
                    ['($i1 = Vm::toIntegerNoString($x)) !== null && ($i2 = Vm::toIntegerNoString($y)) !== null', "$ra = \$i1 $operator \$i2;"],
                ]);

            case OpCodes::OP_SHL:
            case OpCodes::OP_SHR:
                $function = $opcode === OpCodes::OP_SHL ? 'shiftLeft' : 'shiftRight';
                return $this->arithmetic($pc, '$x = ' . self::r($b) . '; $y = ' . self::r($c) . ';', [
                    ['\is_int($x) && \is_int($y)', "$ra = Vm::$function(\$x, \$y);"],
                    ['($i1 = Vm::toIntegerNoString($x)) !== null && ($i2 = Vm::toIntegerNoString($y)) !== null', "$ra = Vm::$function(\$i1, \$i2);"],
                ]);

            case OpCodes::OP_MMBIN:
            case OpCodes::OP_MMBINI:
            case OpCodes::OP_MMBINK:
                // reached only when the preceding arithmetic instruction failed its fast paths
                return self::lines($this->emitMetamethodCall($pc, OpCodes::GETARG_A($proto->code[$pc - 1])));

            case OpCodes::OP_UNM:
                return self::lines(
                    '$x = ' . self::r($b) . ';',
                    'if (\is_int($x)) {',
                    "    $ra = \$x === \\PHP_INT_MIN ? \$x : -\$x;",
                    '} elseif (\is_float($x)) {',
                    "    $ra = -\$x;",
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = MetaMethods::tryBinTM(\$L, \$x, \$x, " . MetaMethods::TM_UNM . ", $b, $b);",
                    '}',
                );

            case OpCodes::OP_BNOT:
                return self::lines(
                    '$x = ' . self::r($b) . ';',
                    'if (\is_int($x)) {',
                    "    $ra = ~\$x;",
                    '} elseif (($i1 = Vm::toIntegerNoString($x)) !== null) {',
                    "    $ra = ~\$i1;",
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = MetaMethods::tryBinTM(\$L, \$x, \$x, " . MetaMethods::TM_BNOT . ", $b, $b);",
                    '}',
                );

            case OpCodes::OP_NOT:
                return self::lines('$x = ' . self::r($b) . ';', "$ra = \$x === null || \$x === false;");

            case OpCodes::OP_LEN:
                return self::lines(
                    '$x = ' . self::r($b) . ';',
                    'if (\is_string($x)) {',
                    "    $ra = \\strlen(\$x);",
                    '} elseif ($x instanceof LuaTable && $x->metatable === null) {',
                    "    $ra = \$x->length();",
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = Vm::objectLength(\$L, \$x, $b);",
                    '}',
                );

            case OpCodes::OP_CONCAT:
                $operands = [];
                $conditions = [];
                for ($i = 0; $i < $b; $i++) {
                    $operands[] = self::r($a + $i);
                    $conditions[] = '\is_string(' . self::r($a + $i) . ')';
                }
                return self::lines(
                    'if (' . implode(' && ', $conditions) . ') {',
                    "    $ra = " . implode(' . ', $operands) . ';',
                    '} else {',
                    '    ' . self::savePc($pc) . " $ra = Vm::concat(\$L, [" . implode(', ', $operands) . "], $a);",
                    '}',
                    self::checkGc($pc, $a + 1, "(\\is_string($ra) ? " . Collector::STRING_OVERHEAD . " + \\strlen($ra) : 0)"),
                );

            case OpCodes::OP_CLOSE:
                return self::lines(
                    'if ($ci->openupval !== [] || $ci->tbclist !== []) {',
                    '    ' . self::savePc($pc) . " Upvalues::close(\$L, \$ci, $a);",
                    '}',
                );

            case OpCodes::OP_TBC:
                return self::lines(self::savePc($pc) . " Upvalues::newTbc(\$L, \$ci, $a);");

            case OpCodes::OP_JMP:
                return self::lines('goto L' . ($pc + 1 + OpCodes::GETARG_sJ($instruction)) . ';');

            case OpCodes::OP_EQ:
                return self::lines(
                    "\$x = $ra; \$y = " . self::r($b) . ';',
                    'if ($x === $y) {',
                    '    $c = true;',
                    '} elseif (\is_object($x) || \is_float($x) || \is_float($y)) {',
                    '    ' . self::savePc($pc) . ' $c = Vm::equalObjects($L, $x, $y);',
                    '} else {',
                    '    $c = false;',
                    '}',
                ) . $this->conditionalJump($pc, '$c', $k);

            case OpCodes::OP_LT:
            case OpCodes::OP_LE:
                $operator = $opcode === OpCodes::OP_LT ? '<' : '<=';
                $function = $opcode === OpCodes::OP_LT ? 'lessThan' : 'lessEqual';
                return self::lines(
                    "\$x = $ra; \$y = " . self::r($b) . ';',
                    'if ((\is_int($x) && \is_int($y)) || (\is_float($x) && \is_float($y))) {',
                    "    \$c = \$x $operator \$y;",
                    '} else {',
                    '    ' . self::savePc($pc) . " \$c = Vm::$function(\$L, \$x, \$y);",
                    '}',
                ) . $this->conditionalJump($pc, '$c', $k);

            case OpCodes::OP_EQK:
                return self::lines("\$x = $ra;") . $this->conditionalJump($pc, $this->equalsConstant('$x', $proto->k[$b]), $k);

            case OpCodes::OP_EQI:
                $immediate = OpCodes::GETARG_sB($instruction);
                return self::lines("\$x = $ra;") . $this->conditionalJump($pc, $this->equalsConstant('$x', $immediate), $k);

            case OpCodes::OP_LTI:
            case OpCodes::OP_LEI:
            case OpCodes::OP_GTI:
            case OpCodes::OP_GEI:
                $immediate = OpCodes::GETARG_sB($instruction);
                [$operator, $flip, $event] = match ($opcode) {
                    OpCodes::OP_LTI => ['<', 'false', MetaMethods::TM_LT],
                    OpCodes::OP_LEI => ['<=', 'false', MetaMethods::TM_LE],
                    OpCodes::OP_GTI => ['>', 'true', MetaMethods::TM_LT],
                    OpCodes::OP_GEI => ['>=', 'true', MetaMethods::TM_LE],
                };
                $isFloat = $c ? 'true' : 'false';
                return self::lines(
                    "\$x = $ra;",
                    'if (\is_int($x) || \is_float($x)) {',
                    "    \$c = \$x $operator " . self::operand($immediate) . ';',
                    '} else {',
                    '    ' . self::savePc($pc) . " \$c = MetaMethods::callOrderITM(\$L, \$x, " . self::operand($immediate) . ", $flip, $isFloat, $event);",
                    '}',
                ) . $this->conditionalJump($pc, '$c', $k);

            case OpCodes::OP_TEST:
                // jump (the next instruction) when truthiness equals k; else skip it
                return self::lines('if ' . ($k ? self::falsy($ra) : self::truthy($ra)) . ' goto L' . ($pc + 2) . ';', $this->nextJump($pc));

            case OpCodes::OP_TESTSET:
                return self::lines(
                    '$x = ' . self::r($b) . ';',
                    'if ' . ($k ? self::falsy('$x') : self::truthy('$x')) . ' goto L' . ($pc + 2) . ';',
                    "$ra = \$x;",
                    $this->nextJump($pc),
                );

            case OpCodes::OP_CALL:
                return $this->emitCall($pc, $a, $b, $c);

            case OpCodes::OP_TAILCALL:
                $code = self::lines(self::savePc($pc));
                if ($k) {  // close upvalues from current call
                    $code .= self::lines('if ($ci->openupval !== []) {', '    Upvalues::closeUpvalues($ci, 0);', '}');
                }
                $code .= $this->argumentList($a, $b);
                $code .= self::lines("return Calls::tailCall(\$L, \$ci, $ra, \$args, $a);");
                return $code;

            case OpCodes::OP_RETURN:
                $code = '';
                if ($k) {  // may there be open upvalues?
                    $code .= self::lines(
                        'if ($ci->openupval !== [] || $ci->tbclist !== []) {',
                        '    ' . self::savePc($pc) . ' Upvalues::close($L, $ci, 0);',
                        '}',
                    );
                }
                if ($b === 0) {  // results up to top
                    $code .= $this->returnHook($pc, $a, "\$top - $a");
                    $code .= self::lines(
                        '$ret = [];',
                        "for (\$i = $a; \$i < \$top; \$i++) {",
                        '    $ret[] = $R[$i];',
                        '}',
                        '$L->ci = $ci->previous;',
                        'return $ret;',
                    );
                    return $code;
                }
                $results = [];
                for ($i = 0; $i < $b - 1; $i++) {
                    $results[] = self::r($a + $i);
                }
                return $code . $this->returnHook($pc, $a, (string) ($b - 1))
                    . self::lines('$L->ci = $ci->previous;', 'return [' . implode(', ', $results) . '];');

            case OpCodes::OP_RETURN0:
                return $this->returnHook($pc, $a, '0') . self::lines('$L->ci = $ci->previous;', 'return [];');

            case OpCodes::OP_RETURN1:
                return $this->returnHook($pc, $a, '1') . self::lines('$L->ci = $ci->previous;', "return [$ra];");

            case OpCodes::OP_FORLOOP:
                $loopStart = $pc + 1 - OpCodes::GETARG_Bx($instruction);
                // integer loop: R[a+1] is the unsigned count of iterations left
                // (a count >= 2^63 would stop being an integer after 2^63 iterations)
                return self::lines(
                    '$y = ' . self::r($a + 2) . ';',
                    'if (\is_int($y)) {',
                    '    $n = ' . self::r($a + 1) . ';',
                    '    if ($n !== 0) {',
                    '        ' . self::r($a + 1) . ' = $n - 1;',
                    "        \$x = $ra + \$y;",
                    "        $ra = \$x; " . self::r($a + 3) . ' = $x;',
                    "        goto L$loopStart;",
                    '    }',
                    "} elseif (Vm::floatForLoop(\$R, $a)) {",
                    "    goto L$loopStart;",
                    '}',
                );

            case OpCodes::OP_FORPREP:
                return self::lines(
                    self::savePc($pc),
                    "if (Vm::forPrep(\$L, \$R, $a)) {",
                    '    goto L' . ($pc + OpCodes::GETARG_Bx($instruction) + 2) . ';',
                    '}',
                );

            case OpCodes::OP_TFORPREP:
                return self::lines(
                    'if (' . self::r($a + 3) . ' !== null && ' . self::r($a + 3) . ' !== false) {',
                    '    ' . self::savePc($pc) . ' Upvalues::newTbc($L, $ci, ' . ($a + 3) . ');',
                    '}',
                    'goto T' . ($pc + OpCodes::GETARG_Bx($instruction) + 1) . ';',  // to OP_TFORCALL, past its hook check
                );

            case OpCodes::OP_TFORCALL:
                $code = "    T$pc:\n" . self::lines(
                    self::savePc($pc),
                    "\$ret = Calls::call(\$L, $ra, [" . self::r($a + 1) . ', ' . self::r($a + 2) . ']);',
                );
                for ($i = 0; $i < $c; $i++) {
                    $code .= self::lines(self::r($a + 4 + $i) . " = \$ret[$i] ?? null;");
                }
                return $code;

            case OpCodes::OP_TFORLOOP:
                return self::lines(
                    'if (($v = ' . self::r($a + 4) . ') !== null) {',
                    '    ' . self::r($a + 2) . ' = $v;',
                    '    goto L' . ($pc + 1 - OpCodes::GETARG_Bx($instruction)) . ';',
                    '}',
                );

            case OpCodes::OP_SETLIST:
                $last = $c;
                if ($k) {
                    $last += OpCodes::GETARG_Ax($proto->code[$pc + 1]) * (OpCodes::MAXARG_C + 1);
                }
                $code = self::lines("\$t = $ra;");
                if ($b === 0) {  // get up to the top
                    return $code . self::lines(
                        "\$n = \$top - $a - 1;",
                        'for ($i = 1; $i <= $n; $i++) {',
                        "    \$v = \$R[$a + \$i];",
                        "    if (\$v !== null) { \$t->arr[$last + \$i] = \$v; } else { unset(\$t->arr[$last + \$i]); }",
                        '}',
                        "if (\$t->sizearray < $last + \$n) {",
                        "    \$t->sizearray = $last + \$n;",
                        '}',
                    );
                }
                for ($i = 1; $i <= $b; $i++) {
                    $index = $last + $i;
                    $code .= self::lines(
                        '$v = ' . self::r($a + $i) . ';',
                        "if (\$v !== null) { \$t->arr[$index] = \$v; } else { unset(\$t->arr[$index]); }",
                    );
                }
                return $code . self::lines('if ($t->sizearray < ' . ($last + $b) . ') {', '    $t->sizearray = ' . ($last + $b) . ';', '}');

            case OpCodes::OP_CLOSURE:
                $childIndex = OpCodes::GETARG_Bx($instruction);
                $child = $proto->p[$childIndex];
                $upvalues = [];
                foreach ($child->upvalues as $upvalueDescription) {
                    if ($upvalueDescription->instack) {  // upvalue refers to local variable?
                        $upvalues[] = "Upvalues::find(\$ci, {$upvalueDescription->idx})";
                    } else {  // get upvalue from enclosing function
                        $upvalues[] = "\$cl->upvals[{$upvalueDescription->idx}]";
                    }
                }
                $childPath = $this->path . '_' . $childIndex;
                return self::lines(
                    "$ra = new LuaClosure(\$proto_$childPath, \$function_$childPath, [" . implode(', ', $upvalues) . ']);',
                    self::checkGc($pc, $a + 1, (string) Collector::closureSize(\count($upvalues))),
                );

            case OpCodes::OP_VARARG:
                $wanted = $c - 1;  // required results
                if ($wanted < 0) {  // get all extra arguments available
                    return self::lines(
                        '$va = $ci->varargs; $n = \count($va);',
                        'for ($i = 0; $i < $n; $i++) {',
                        "    \$R[$a + \$i] = \$va[\$i];",
                        '}',
                        "\$top = $a + \$n;",
                    );
                }
                $code = self::lines('$va = $ci->varargs;');
                for ($i = 0; $i < $wanted; $i++) {
                    $code .= self::lines(self::r($a + $i) . " = \$va[$i] ?? null;");
                }
                return $code;

            case OpCodes::OP_VARARGPREP:
                return self::lines(
                    "Calls::adjustVarargs(\$ci, \$R, {$proto->numparams});",
                    'if ($L->hookmask !== 0) {',
                    '    Hooks::hookCall($L, $ci, 1);',
                    '    $L->oldpc = 1;  // next opcode will be seen as a "new" line',
                    '}',
                );

            case OpCodes::OP_EXTRAARG:
                return '';
        }
        throw new \LogicException('unknown opcode ' . $opcode);
    }

    /**
     * ldo.c: luaD_poscall's return hook for OP_RETURN* (before the results
     * are collected, so a hook changing them with debug.setlocal changes
     * the returned values, as in C). The $count results start at register
     * $a, slot $a + 1 in debug.getlocal's numbering.
     */
    private function returnHook(int $pc, int $a, string $count): string
    {
        return self::lines(
            'if ($L->hookmask !== 0) {',
            '    ' . self::savePc($pc) . ' Hooks::retHook($L, $ci, ' . ($a + 1) . ", $count);",
            '}',
        );
    }

    /** OP_GETTABUP / OP_GETFIELD: t[k] with a constant string key */
    private function emitGetField(int $pc, string $ra, string $tableExpression, string $keyLiteral, int $slot): string
    {
        return self::lines(
            "\$t = $tableExpression;",
            "if (\$t instanceof LuaTable && ((\$v = \$t->hash[$keyLiteral] ?? null) !== null || \$t->metatable === null)) {",
            "    $ra = \$v;",
            '} else {',
            '    ' . self::savePc($pc) . " $ra = Vm::finishGet(\$L, \$t, $keyLiteral, $slot);",
            '}',
        );
    }

    /** OP_SETTABUP / OP_SETFIELD: t[k] = v with a constant string key */
    private function emitSetField(int $pc, string $tableExpression, string $keyLiteral, string $valueExpression, int $slot, bool $valueIsNonNilConstant): string
    {
        $rawSet = $valueIsNonNilConstant
            ? "    \$t->hash[$keyLiteral] = \$v;"
            : "    if (\$v !== null) { \$t->hash[$keyLiteral] = \$v; } else { unset(\$t->hash[$keyLiteral]); }";
        return self::lines(
            "\$t = $tableExpression; \$v = $valueExpression;",
            "if (\$t instanceof LuaTable && (\$t->metatable === null || isset(\$t->hash[$keyLiteral]))) {",
            $rawSet,
            '} else {',
            '    ' . self::savePc($pc) . " Vm::setTable(\$L, \$t, $keyLiteral, \$v, $slot);",
            '}',
        );
    }

    /** OP_ADDK ... OP_IDIVK: arithmetic with a numeric constant (lvm.c: op_arithK, op_arithfK) */
    private function emitArithmeticConstant(int $pc, int $opcode, string $ra, int $b, int|float $constant): string
    {
        $load = '$x = ' . self::r($b) . ';';
        $floatConstant = self::operand((float) $constant);
        $isNumber = '\is_float($x) || \is_int($x)';
        switch ($opcode) {
            case OpCodes::OP_POWK:
                return $this->arithmetic($pc, $load, [[$isNumber, "$ra = Vm::floatPow(\$x, $floatConstant);"]]);
            case OpCodes::OP_DIVK:
                return $this->arithmetic($pc, $load, [[$isNumber, "$ra = \\fdiv(\$x, $floatConstant);"]]);
        }
        if (\is_float($constant)) {  // float constant: always a float operation
            $floatOperation = match ($opcode) {
                OpCodes::OP_ADDK => "$ra = \$x + $floatConstant;",
                OpCodes::OP_SUBK => "$ra = \$x - $floatConstant;",
                OpCodes::OP_MULK => "$ra = \$x * $floatConstant;",
                OpCodes::OP_MODK => "$ra = Vm::modf(\$x, $floatConstant);",
                OpCodes::OP_IDIVK => "$ra = \\floor(\\fdiv(\$x, $floatConstant));",
            };
            return $this->arithmetic($pc, $load, [[$isNumber, $floatOperation]]);
        }
        $integerConstant = self::operand($constant);
        $integerOperation = match ($opcode) {
            OpCodes::OP_ADDK => "\$v = \$x + $integerConstant; $ra = \\is_int(\$v) ? \$v : Vm::addWrap(\$x, $integerConstant);",
            OpCodes::OP_SUBK => "\$v = \$x - $integerConstant; $ra = \\is_int(\$v) ? \$v : Vm::subWrap(\$x, $integerConstant);",
            OpCodes::OP_MULK => "\$v = \$x * $integerConstant; $ra = \\is_int(\$v) ? \$v : Vm::mulWrap(\$x, $integerConstant);",
            OpCodes::OP_MODK => ($constant === 0 || $constant === -1)
                ? self::savePc($pc) . " $ra = Vm::mod(\$L, \$x, $integerConstant);"
                : "\$v = \$x % $integerConstant; $ra = (\$v !== 0 && (\$v ^ $integerConstant) < 0) ? \$v + $integerConstant : \$v;",
            OpCodes::OP_IDIVK => self::savePc($pc) . " $ra = Vm::idiv(\$L, \$x, $integerConstant);",
        };
        $floatOperation = match ($opcode) {
            OpCodes::OP_ADDK => "$ra = \$x + $floatConstant;",
            OpCodes::OP_SUBK => "$ra = \$x - $floatConstant;",
            OpCodes::OP_MULK => "$ra = \$x * $floatConstant;",
            OpCodes::OP_MODK => "$ra = Vm::modf(\$x, $floatConstant);",
            OpCodes::OP_IDIVK => "$ra = \\floor(\\fdiv(\$x, $floatConstant));",
        };
        return $this->arithmetic($pc, $load, [['\is_int($x)', $integerOperation], ['\is_float($x)', $floatOperation]]);
    }

    /**
     * Raw equality with a constant (OP_EQK, OP_EQI): an integer equals
     * the float with the same value and vice versa.
     */
    private function equalsConstant(string $variable, mixed $constant): string
    {
        $alternatives = ["$variable === " . PhpLiteral::of($constant)];
        if (\is_int($constant) && (float) $constant !== 9.2233720368547758E18 && (int) (float) $constant === $constant) {
            $alternatives[] = "$variable === " . PhpLiteral::of((float) $constant);
        } elseif (\is_float($constant)) {
            $integerValue = LuaTable::floatToInteger($constant);
            if ($integerValue !== null) {
                $alternatives[] = "$variable === " . PhpLiteral::of($integerValue);
            }
        }
        return '(' . implode(' || ', $alternatives) . ')';
    }

    /**
     * lvm.c: docondjump: if the condition differs from k, skip the next
     * instruction (a jump); otherwise fall through into it.
     */
    private function conditionalJump(int $pc, string $condition, int $k): string
    {
        $skip = 'goto L' . ($pc + 2) . ';';
        return self::lines(($k ? "if (!$condition) " : "if ($condition) ") . $skip, $this->nextJump($pc));
    }

    /**
     * lvm.c: donextjump: do the jump at $pc + 1 without fetching it (so no
     * hook sees that OP_JMP).
     */
    private function nextJump(int $pc): string
    {
        $jump = $this->proto->code[$pc + 1];
        return 'goto L' . ($pc + 2 + OpCodes::GETARG_sJ($jump)) . ';';
    }

    /** builds $args from R[a+1 .. a+b-1], or up to $top when b is 0 */
    private function argumentList(int $a, int $b): string
    {
        if ($b === 0) {
            return self::lines(
                '$args = [];',
                'for ($i = ' . ($a + 1) . '; $i < $top; $i++) {',
                '    $args[] = $R[$i];',
                '}',
            );
        }
        $arguments = [];
        for ($i = 1; $i < $b; $i++) {
            $arguments[] = self::r($a + $i);
        }
        return self::lines('$args = [' . implode(', ', $arguments) . '];');
    }

    /** OP_CALL: A B C (lvm.c: OP_CALL, ldo.c: luaD_precall/luaD_poscall) */
    private function emitCall(int $pc, int $a, int $b, int $c): string
    {
        $ra = self::r($a);
        $code = self::lines(self::savePc($pc));
        $code .= $this->argumentList($a, $b);
        $code .= self::lines(
            "\$f = $ra;",
            '$ret = $f instanceof LuaClosure ? (($f->code)($L, $f, $args) ?? Calls::finishTailCall($L)) : Calls::callNonLua($L, $f, $args);',
        );
        $wanted = $c - 1;
        if ($wanted < 0) {  // all results
            return $code . self::lines(
                '$n = \count($ret);',
                'for ($i = 0; $i < $n; $i++) {',
                "    \$R[$a + \$i] = \$ret[\$i];",
                '}',
                "\$top = $a + \$n;",
            );
        }
        for ($i = 0; $i < $wanted; $i++) {
            $code .= self::lines(self::r($a + $i) . " = \$ret[$i] ?? null;");
        }
        return $code;
    }
}
