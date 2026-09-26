<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Compiler\ChunkId;
use LuaPhp\Compiler\OpCodes;
use LuaPhp\Compiler\Proto;

/**
 * Port of ldebug.c: line information, symbolic execution for variable and
 * function names, runtime error messages, and lua_getstack/lua_getinfo.
 *
 * Because our bytecode is exactly luac's, the symbolic execution
 * (getobjname, funcnamefromcode) finds the same names as C Lua.
 *
 * C's varinfo() finds where a faulty value lives by comparing pointers.
 * Here the caller says where it came from with a "slot": a register index
 * (>= 0), an upvalue (upvalueSlot($index)), or NO_SLOT (a constant, an
 * immediate, a value inside a metatable, or a call from PHP code).
 */
final class DebugInfo
{
    public const NO_SLOT = PHP_INT_MIN;

    // ldebug.h
    public const ABSLINEINFO = -0x80;
    public const MAXIWTHABS = 128;

    public static function upvalueSlot(int $upvalueIndex): int
    {
        return -1 - $upvalueIndex;
    }

    /*
    ** {======================================================
    ** Line information
    ** =======================================================
    */

    // ldebug.c: getbaseline
    private static function getBaseLine(Proto $f, int $pc, int &$basepc): int
    {
        $absLineInfo = $f->abslineinfo;
        if ($absLineInfo === [] || $pc < $absLineInfo[0]->pc) {
            $basepc = -1;  // start from the beginning
            return $f->linedefined;
        }
        $i = intdiv($pc, self::MAXIWTHABS) - 1;  // get an estimate
        $count = \count($absLineInfo);
        while ($i + 1 < $count && $pc >= $absLineInfo[$i + 1]->pc) {
            $i++;  // low estimate; adjust it
        }
        $basepc = $absLineInfo[$i]->pc;
        return $absLineInfo[$i]->line;
    }

    // ldebug.c: luaG_getfuncline
    public static function getFuncLine(Proto $f, int $pc): int
    {
        if ($f->lineinfo === []) {  // no debug information?
            return -1;
        }
        $basepc = 0;
        $baseline = self::getBaseLine($f, $pc, $basepc);
        while ($basepc++ < $pc) {  // walk until given instruction
            $baseline += $f->lineinfo[$basepc];  // correct line
        }
        return $baseline;
    }

    // ldebug.c: getcurrentline
    public static function currentLine(CallInfo $ci): int
    {
        return self::getFuncLine($ci->func->proto, $ci->savedpc);
    }

    /* }====================================================== */

    /*
    ** {======================================================
    ** Symbolic Execution
    ** =======================================================
    */

    /** lfunc.c: luaF_getlocalname: name of the n-th active local at 'pc' */
    public static function getLocalName(Proto $f, int $localNumber, int $pc): ?string
    {
        foreach ($f->locvars as $locvar) {
            if ($locvar->startpc > $pc) {
                break;
            }
            if ($pc < $locvar->endpc) {  // is variable active?
                $localNumber--;
                if ($localNumber === 0) {
                    return $locvar->varname;
                }
            }
        }
        return null;  // not found
    }

    // ldebug.c: upvalname
    private static function upvalueName(Proto $p, int $upvalueIndex): string
    {
        return $p->upvalues[$upvalueIndex]->name ?? '?';
    }

    // ldebug.c: filterpc
    private static function filterPc(int $pc, int $jumpTarget): int
    {
        return $pc < $jumpTarget ? -1 : $pc;  // is code conditional (inside a jump)?
    }

    /** ldebug.c: findsetreg: last instruction before 'lastpc' that modified register 'reg' */
    private static function findSetReg(Proto $p, int $lastpc, int $reg): int
    {
        $setreg = -1;  // keep last instruction that changed 'reg'
        $jumpTarget = 0;  // any code before this address is conditional
        if (OpCodes::testMMMode(OpCodes::GET_OPCODE($p->code[$lastpc]))) {
            $lastpc--;  // previous instruction was not actually executed
        }
        for ($pc = 0; $pc < $lastpc; $pc++) {
            $instruction = $p->code[$pc];
            $opcode = OpCodes::GET_OPCODE($instruction);
            $a = OpCodes::GETARG_A($instruction);
            switch ($opcode) {
                case OpCodes::OP_LOADNIL:  // set registers from 'a' to 'a+b'
                    $b = OpCodes::GETARG_B($instruction);
                    $change = $a <= $reg && $reg <= $a + $b;
                    break;
                case OpCodes::OP_TFORCALL:  // affect all regs above its base
                    $change = $reg >= $a + 2;
                    break;
                case OpCodes::OP_CALL:
                case OpCodes::OP_TAILCALL:  // affect all registers above base
                    $change = $reg >= $a;
                    break;
                case OpCodes::OP_JMP:  // doesn't change registers, but changes 'jmptarget'
                    $destination = $pc + 1 + OpCodes::GETARG_sJ($instruction);
                    // jump does not skip 'lastpc' and is larger than current one?
                    if ($destination <= $lastpc && $destination > $jumpTarget) {
                        $jumpTarget = $destination;  // update 'jmptarget'
                    }
                    $change = false;
                    break;
                default:  // any instruction that sets A
                    $change = OpCodes::testAMode($opcode) && $reg === $a;
                    break;
            }
            if ($change) {
                $setreg = self::filterPc($pc, $jumpTarget);
            }
        }
        return $setreg;
    }

    /**
     * ldebug.c: kname. Returns ["constant", name] for a string constant,
     * [null, "?"] otherwise.
     *
     * @return array{?string, string}
     */
    private static function constantName(Proto $p, int $index): array
    {
        $constant = $p->k[$index];
        if (\is_string($constant)) {
            return ['constant', $constant];
        }
        return [null, '?'];
    }

    /**
     * ldebug.c: basicgetobjname. Returns [kind, name] with kind null when
     * nothing reasonable was found; updates $pc like C's '*ppc'.
     *
     * @return array{?string, ?string}
     */
    private static function basicGetObjName(Proto $p, int &$pc, int $reg): array
    {
        $name = self::getLocalName($p, $reg + 1, $pc);
        if ($name !== null) {  // is a local?
            return ['local', $name];
        }
        // else try symbolic execution
        $pc = self::findSetReg($p, $pc, $reg);
        if ($pc !== -1) {  // could find instruction?
            $instruction = $p->code[$pc];
            switch (OpCodes::GET_OPCODE($instruction)) {
                case OpCodes::OP_MOVE:
                    $b = OpCodes::GETARG_B($instruction);  // move from 'b' to 'a'
                    if ($b < OpCodes::GETARG_A($instruction)) {
                        return self::basicGetObjName($p, $pc, $b);  // get name for 'b'
                    }
                    break;
                case OpCodes::OP_GETUPVAL:
                    return ['upvalue', self::upvalueName($p, OpCodes::GETARG_B($instruction))];
                case OpCodes::OP_LOADK:
                    return self::constantName($p, OpCodes::GETARG_Bx($instruction));
                case OpCodes::OP_LOADKX:
                    return self::constantName($p, OpCodes::GETARG_Ax($p->code[$pc + 1]));
            }
        }
        return [null, null];  // could not find reasonable name
    }

    // ldebug.c: rname: a "name" for register 'c' (only constant names count)
    private static function registerName(Proto $p, int $pc, int $c): string
    {
        [$kind, $name] = self::basicGetObjName($p, $pc, $c);
        return $kind === 'constant' ? $name : '?';
    }

    // ldebug.c: rkname
    private static function rkName(Proto $p, int $pc, int $instruction): string
    {
        $c = OpCodes::GETARG_C($instruction);  // key index
        if (OpCodes::GETARG_k($instruction)) {  // is 'c' a constant?
            return self::constantName($p, $c)[1];
        }
        return self::registerName($p, $pc, $c);  // 'c' is a register
    }

    // ldebug.c: isEnv
    private static function isEnvironment(Proto $p, int $pc, int $instruction, bool $isUpvalue): string
    {
        $t = OpCodes::GETARG_B($instruction);  // table index
        if ($isUpvalue) {  // is 't' an upvalue?
            $name = self::upvalueName($p, $t);
        } else {  // 't' is a register
            [$kind, $name] = self::basicGetObjName($p, $pc, $t);
            if ($kind !== 'local' && $kind !== 'upvalue') {
                $name = null;  // cannot be the variable _ENV
            }
        }
        return $name === Lua::LUA_ENV ? 'global' : 'field';
    }

    /**
     * ldebug.c: getobjname. Returns [kind, name]; kind is null when no
     * reasonable name was found.
     *
     * @return array{?string, ?string}
     */
    public static function getObjName(Proto $p, int $lastpc, int $reg): array
    {
        [$kind, $name] = self::basicGetObjName($p, $lastpc, $reg);
        if ($kind !== null) {
            return [$kind, $name];
        }
        if ($lastpc !== -1) {  // could find instruction?
            $instruction = $p->code[$lastpc];
            switch (OpCodes::GET_OPCODE($instruction)) {
                case OpCodes::OP_GETTABUP:
                    $name = self::constantName($p, OpCodes::GETARG_C($instruction))[1];
                    return [self::isEnvironment($p, $lastpc, $instruction, true), $name];
                case OpCodes::OP_GETTABLE:
                    $name = self::registerName($p, $lastpc, OpCodes::GETARG_C($instruction));
                    return [self::isEnvironment($p, $lastpc, $instruction, false), $name];
                case OpCodes::OP_GETI:
                    return ['field', 'integer index'];
                case OpCodes::OP_GETFIELD:
                    $name = self::constantName($p, OpCodes::GETARG_C($instruction))[1];
                    return [self::isEnvironment($p, $lastpc, $instruction, false), $name];
                case OpCodes::OP_SELF:
                    return ['method', self::rkName($p, $lastpc, $instruction)];
            }
        }
        return [null, null];  // could not find reasonable name
    }

    /**
     * ldebug.c: funcnamefromcode: a name for the function called by the
     * instruction at 'pc'.
     *
     * @return array{string, string}|null [namewhat, name]
     */
    private static function funcNameFromCode(Proto $p, int $pc): ?array
    {
        $instruction = $p->code[$pc];  // calling instruction
        switch (OpCodes::GET_OPCODE($instruction)) {
            case OpCodes::OP_CALL:
            case OpCodes::OP_TAILCALL:
                [$kind, $name] = self::getObjName($p, $pc, OpCodes::GETARG_A($instruction));  // get function name
                return $kind === null ? null : [$kind, $name];
            case OpCodes::OP_TFORCALL:  // for iterator
                return ['for iterator', 'for iterator'];
            // other instructions can do calls through metamethods
            case OpCodes::OP_SELF:
            case OpCodes::OP_GETTABUP:
            case OpCodes::OP_GETTABLE:
            case OpCodes::OP_GETI:
            case OpCodes::OP_GETFIELD:
                $tm = MetaMethods::TM_INDEX;
                break;
            case OpCodes::OP_SETTABUP:
            case OpCodes::OP_SETTABLE:
            case OpCodes::OP_SETI:
            case OpCodes::OP_SETFIELD:
                $tm = MetaMethods::TM_NEWINDEX;
                break;
            case OpCodes::OP_MMBIN:
            case OpCodes::OP_MMBINI:
            case OpCodes::OP_MMBINK:
                $tm = OpCodes::GETARG_C($instruction);
                break;
            case OpCodes::OP_UNM:
                $tm = MetaMethods::TM_UNM;
                break;
            case OpCodes::OP_BNOT:
                $tm = MetaMethods::TM_BNOT;
                break;
            case OpCodes::OP_LEN:
                $tm = MetaMethods::TM_LEN;
                break;
            case OpCodes::OP_CONCAT:
                $tm = MetaMethods::TM_CONCAT;
                break;
            case OpCodes::OP_EQ:
                $tm = MetaMethods::TM_EQ;
                break;
            // no cases for OP_EQI and OP_EQK, as they don't call metamethods
            case OpCodes::OP_LT:
            case OpCodes::OP_LTI:
            case OpCodes::OP_GTI:
                $tm = MetaMethods::TM_LT;
                break;
            case OpCodes::OP_LE:
            case OpCodes::OP_LEI:
            case OpCodes::OP_GEI:
                $tm = MetaMethods::TM_LE;
                break;
            case OpCodes::OP_CLOSE:
            case OpCodes::OP_RETURN:
                $tm = MetaMethods::TM_CLOSE;
                break;
            default:
                return null;  // cannot find a reasonable name
        }
        return ['metamethod', substr(MetaMethods::EVENT_NAMES[$tm], 2)];
    }

    /**
     * ldebug.c: funcnamefromcall: a name for the function being called by
     * the function running in $ci.
     *
     * @return array{string, string}|null [namewhat, name]
     */
    public static function funcNameFromCall(CallInfo $ci): ?array
    {
        if ($ci->callstatus & Lua::CIST_HOOKED) {  // was it called inside a hook?
            return ['hook', '?'];
        }
        if ($ci->callstatus & Lua::CIST_FIN) {  // was it called as a finalizer?
            return ['metamethod', '__gc'];  // report it as such
        }
        if ($ci->func instanceof LuaClosure) {
            return self::funcNameFromCode($ci->func->proto, $ci->savedpc);
        }
        return null;
    }

    /**
     * ldebug.c: getfuncname: a name for the function running in $ci, from
     * the code of its caller.
     *
     * @return array{string, string}|null [namewhat, name]
     */
    public static function getFuncName(?CallInfo $ci): ?array
    {
        // calling function is a known function?
        if ($ci !== null && !($ci->callstatus & Lua::CIST_TAIL) && $ci->previous !== null) {
            return self::funcNameFromCall($ci->previous);
        }
        return null;  // no way to find a name
    }

    /* }====================================================== */

    /*
    ** {======================================================
    ** Error messages
    ** =======================================================
    */

    // ldebug.c: formatvarinfo
    private static function formatVarInfo(?string $kind, ?string $name): string
    {
        if ($kind === null) {
            return '';  // no information
        }
        return " ($kind '" . self::cString($name) . "')";
    }

    /** ldebug.c: varinfo: a description like " (local 'x')" for the value in $slot */
    public static function varInfo(Coroutine $L, int $slot): string
    {
        $ci = $L->ci;
        if ($slot === self::NO_SLOT || !($ci->func instanceof LuaClosure)) {
            return '';
        }
        $proto = $ci->func->proto;
        if ($slot < 0) {  // an upvalue
            return self::formatVarInfo('upvalue', self::upvalueName($proto, -1 - $slot));
        }
        [$kind, $name] = self::getObjName($proto, $ci->savedpc, $slot);
        return self::formatVarInfo($kind, $name);
    }

    /** ldebug.c: luaG_typeerror */
    public static function typeError(Coroutine $L, mixed $value, string $operation, int $slot): never
    {
        $typeName = MetaMethods::objectTypeName($L, $value);
        self::runError($L, "attempt to $operation a " . self::cString($typeName) . ' value' . self::varInfo($L, $slot));
    }

    /** ldebug.c: luaG_callerror */
    public static function callError(Coroutine $L, mixed $value): never
    {
        $nameInfo = self::funcNameFromCall($L->ci);
        $extra = $nameInfo !== null ? self::formatVarInfo($nameInfo[0], $nameInfo[1]) : '';
        $typeName = MetaMethods::objectTypeName($L, $value);
        self::runError($L, 'attempt to call a ' . self::cString($typeName) . ' value' . $extra);
    }

    /** ldebug.c: luaG_forerror */
    public static function forError(Coroutine $L, mixed $value, string $what): never
    {
        $typeName = MetaMethods::objectTypeName($L, $value);
        self::runError($L, "bad 'for' $what (number expected, got " . self::cString($typeName) . ')');
    }

    /** ldebug.c: luaG_concaterror */
    public static function concatError(Coroutine $L, mixed $p1, mixed $p2, int $slot1, int $slot2): never
    {
        if (\is_string($p1) || \is_int($p1) || \is_float($p1)) {
            $p1 = $p2;
            $slot1 = $slot2;
        }
        self::typeError($L, $p1, 'concatenate', $slot1);
    }

    /** ldebug.c: luaG_opinterror */
    public static function opIntError(Coroutine $L, mixed $p1, mixed $p2, string $message, int $slot1, int $slot2): never
    {
        if (!(\is_int($p1) || \is_float($p1))) {  // first operand is wrong?
            $p2 = $p1;  // now second is wrong
            $slot2 = $slot1;
        }
        self::typeError($L, $p2, $message, $slot2);
    }

    /** ldebug.c: luaG_tointerror: both are numbers, but not both are integral */
    public static function toIntError(Coroutine $L, mixed $p1, mixed $p2, int $slot1, int $slot2): never
    {
        if (Vm::toIntegerNoString($p1) === null) {
            $p2 = $p1;
            $slot2 = $slot1;
        }
        self::runError($L, 'number' . self::varInfo($L, $slot2) . ' has no integer representation');
    }

    /** ldebug.c: luaG_ordererror */
    public static function orderError(Coroutine $L, mixed $p1, mixed $p2): never
    {
        $t1 = self::cString(MetaMethods::objectTypeName($L, $p1));
        $t2 = self::cString(MetaMethods::objectTypeName($L, $p2));
        if ($t1 === $t2) {
            self::runError($L, "attempt to compare two $t1 values");
        }
        self::runError($L, "attempt to compare $t1 with $t2");
    }

    /** ldebug.c: luaG_addinfo: "short_src:line: msg" */
    public static function addInfo(string $message, ?string $source, int $line): string
    {
        $shortSource = $source === null ? '?' : ChunkId::of($source);
        return "$shortSource:$line: $message";
    }

    /**
     * ldebug.c: luaG_runerror: raise $message, prefixed with the position
     * of the running Lua function (if the current function is Lua).
     */
    public static function runError(Coroutine $L, string $message): never
    {
        $ci = $L->ci;
        if ($ci->func instanceof LuaClosure) {  // if Lua function, add source:line information
            $message = self::addInfo($message, $ci->func->proto->source, self::currentLine($ci));
        }
        throw new LuaError($message);
    }

    /** '%s' in luaO_pushfstring stops at the first '\0' */
    public static function cString(?string $text): string
    {
        if ($text === null) {
            return '(null)';
        }
        $nulPosition = strpos($text, "\0");
        return $nulPosition === false ? $text : substr($text, 0, $nulPosition);
    }

    /* }====================================================== */

    /*
    ** {======================================================
    ** lua_getstack / lua_getinfo / luaG_findlocal
    ** =======================================================
    */

    /** ldebug.c: lua_getstack: the CallInfo at $level (0 = current running function), or null */
    public static function getStack(Coroutine $L, int $level): ?CallInfo
    {
        if ($level < 0) {
            return null;  // invalid (negative) level
        }
        $ci = $L->ci;
        for (; $level > 0 && $ci !== $L->baseCi; $ci = $ci->previous) {
            $level--;
        }
        if ($level === 0 && $ci !== $L->baseCi) {  // level found?
            return $ci;
        }
        return null;  // no such level
    }

    /**
     * ldebug.c: lua_getinfo / auxgetinfo. With $ci, describes the function
     * running there; with $ci null (C: the '>' option), describes $function.
     * Returns the lua_Debug fields asked for by $what ('S', 'l', 'u', 'n',
     * 't', 'r', 'L' -> 'activelines', 'f' -> 'func'), or null if $what has
     * an invalid option.
     *
     * @return array<string, mixed>|null
     */
    public static function getInfo(Coroutine $L, string $what, ?CallInfo $ci, mixed $function = null): ?array
    {
        if ($ci !== null) {
            $function = $ci->func;
        }
        $closure = $function instanceof LuaClosure ? $function : null;
        $info = [];
        $status = true;
        $length = \strlen($what);
        for ($i = 0; $i < $length; $i++) {
            switch ($what[$i]) {
                case 'S':  // ldebug.c: funcinfo
                    if ($closure === null) {
                        $info['source'] = '=[C]';
                        $info['linedefined'] = -1;
                        $info['lastlinedefined'] = -1;
                        $info['what'] = 'C';
                    } else {
                        $proto = $closure->proto;
                        $info['source'] = $proto->source ?? '=?';
                        $info['linedefined'] = $proto->linedefined;
                        $info['lastlinedefined'] = $proto->lastlinedefined;
                        $info['what'] = $proto->linedefined === 0 ? 'main' : 'Lua';
                    }
                    $info['short_src'] = ChunkId::of($info['source']);
                    break;
                case 'l':
                    $info['currentline'] = ($ci !== null && $ci->func instanceof LuaClosure) ? self::currentLine($ci) : -1;
                    break;
                case 'u':
                    if ($closure === null) {
                        $info['nups'] = $function instanceof NativeFunction ? \count($function->upvalues) : 0;
                        $info['isvararg'] = true;
                        $info['nparams'] = 0;
                    } else {
                        $info['nups'] = \count($closure->proto->upvalues);
                        $info['isvararg'] = $closure->proto->is_vararg;
                        $info['nparams'] = $closure->proto->numparams;
                    }
                    break;
                case 't':
                    $info['istailcall'] = $ci !== null && ($ci->callstatus & Lua::CIST_TAIL) !== 0;
                    break;
                case 'n':
                    $nameInfo = self::getFuncName($ci);
                    $info['namewhat'] = $nameInfo[0] ?? '';
                    $info['name'] = $nameInfo[1] ?? null;
                    break;
                case 'r':
                    if ($ci === null || !($ci->callstatus & Lua::CIST_TRAN)) {
                        $info['ftransfer'] = 0;
                        $info['ntransfer'] = 0;
                    } else {
                        $info['ftransfer'] = $L->ftransfer;
                        $info['ntransfer'] = $L->ntransfer;
                    }
                    break;
                case 'L':
                    $info['activelines'] = $closure === null ? null : self::collectValidLines($closure->proto);
                    break;
                case 'f':
                    $info['func'] = $function;
                    break;
                default:
                    $status = false;  // invalid option
            }
        }
        return $status ? $info : null;
    }

    // ldebug.c: nextline
    private static function nextLine(Proto $p, int $currentLine, int $pc): int
    {
        if ($p->lineinfo[$pc] !== self::ABSLINEINFO) {
            return $currentLine + $p->lineinfo[$pc];
        }
        return self::getFuncLine($p, $pc);
    }

    // ldebug.c: collectvalidlines
    private static function collectValidLines(Proto $p): LuaTable
    {
        $lines = new LuaTable();
        if ($p->lineinfo === []) {  // proto without debug information?
            return $lines;
        }
        $currentLine = $p->linedefined;
        if (!$p->is_vararg) {  // regular function?
            $i = 0;  // consider all instructions
        } else {  // vararg function
            $currentLine = self::nextLine($p, $currentLine, 0);
            $i = 1;  // skip first instruction (OP_VARARGPREP)
        }
        $count = \count($p->lineinfo);
        for (; $i < $count; $i++) {  // for each instruction
            $currentLine = self::nextLine($p, $currentLine, $i);  // get its line
            $lines->arr[$currentLine] = true;  // table[line] = true
        }
        return $lines;
    }

    /**
     * ldebug.c: luaG_findlocal: the name of local $n of the function running
     * in $ci ("(temporary)"/"(C temporary)" for other valid slots,
     * "(vararg)" for negative $n), or null.
     */
    public static function findLocal(Coroutine $L, CallInfo $ci, int $n): ?string
    {
        if ($ci->func instanceof LuaClosure) {
            if ($n < 0) {  // access to vararg values?
                return ($ci->func->proto->is_vararg && -$n <= \count($ci->varargs)) ? '(vararg)' : null;
            }
            $name = self::getLocalName($ci->func->proto, $n, $ci->savedpc);
            if ($name !== null) {
                return $name;
            }
        }
        [$ownSlots, $above] = self::frameSlots($L, $ci);
        if ($n > 0 && $n <= $ownSlots + \count($above)) {  // is 'n' inside 'ci' stack?
            return $ci->func instanceof LuaClosure ? '(temporary)' : '(C temporary)';
        }
        return null;
    }

    /**
     * lapi.c: lua_getlocal's value (after findLocal found local $n): a
     * register, a vararg (negative $n), a native function's argument, or a
     * slot above the frame (see frameSlots).
     */
    public static function localValue(Coroutine $L, CallInfo $ci, int $n): mixed
    {
        if ($n < 0) {
            return $ci->varargs[-$n - 1] ?? null;
        }
        [$ownSlots, $above] = self::frameSlots($L, $ci);
        if ($n > $ownSlots) {
            return $above[$n - $ownSlots - 1] ?? null;
        }
        return $ci->R[$n - 1] ?? null;
    }

    /**
     * lapi.c: lua_setlocal's store (after findLocal found local $n). Writes
     * through $ci->R and $ci->varargs, which the running function uses, and
     * to what the slots above the frame hold: the hook table's slot, a
     * vararg callee's extra arguments (its fixed parameters and function
     * there are dead copies in C: a store to them is dropped).
     */
    public static function setLocalValue(Coroutine $L, CallInfo $ci, int $n, mixed $value): void
    {
        if ($n < 0) {
            $ci->varargs[-$n - 1] = $value;
            return;
        }
        [$ownSlots, $above, $next] = self::frameSlots($L, $ci);
        if ($n <= $ownSlots || $above === []) {
            $ci->R[$n - 1] = $value;
            return;
        }
        $slot = $n - $ownSlots - 1;  // in $above
        $hookedFrames = self::hookedFrames();
        if (isset($hookedFrames[$ci])) {
            if ($slot === 0) {  // the hook table's slot
                $hookedFrames[$ci] = [$hookedFrames[$ci][0], $value];
                return;
            }
            $slot--;
        }
        $extra = $slot - 1 - $next->func->proto->numparams;  // after the function and its fixed parameters
        if ($extra >= 0) {
            $next->varargs[$extra] = $value;
        }
    }

    /**
     * Frames a hook function runs on (see DebugLib::hookf): the frame's
     * stack top when the hook was called (C: L->top - base, see
     * Hooks::hook) and the hook table ldblib.c's hookf pushed above it.
     *
     * @var \WeakMap<CallInfo, array{int, mixed}>|null
     */
    private static ?\WeakMap $hookedFrames = null;

    /** @return \WeakMap<CallInfo, array{int, mixed}> */
    public static function hookedFrames(): \WeakMap
    {
        return self::$hookedFrames ??= new \WeakMap();
    }

    /**
     * ldebug.c: luaG_findlocal's limit: the slots of $ci's frame
     * debug.getlocal reaches (C: limit - base), as [$ownSlots, $above,
     * $next]: slots 1 .. $ownSlots are the frame's own ($ci->R), the values
     * in $above come after them, and $next is the frame running above (C:
     * ci->next), if any. C's limit is L->top for the running frame, else
     * the next frame's function, with what C's stack holds below it:
     * - a Lua frame calls at A (OP_CALL, OP_TAILCALL), A + 4 (OP_TFORCALL),
     *   runs a finalizer from the collection step after an allocation at
     *   A + 1 (lvm.c: checkGC(L, ra + 1)), and a metamethod at its top
     *   (Protect: L->top = ci->top);
     * - a hook function runs above the hooked frame's stack top, with the
     *   hook table below it (see hookedFrames);
     * - a vararg Lua function moves its frame above its arguments (ltm.c:
     *   luaT_adjustvarargs): the frame below then ends with the function,
     *   its fixed parameters (erased) and its extra arguments.
     * A native's frame is its argument list (and its results in a return
     * hook); where it calls a function in C depends on how that native
     * uses its stack.
     *
     * @return array{int, list<mixed>, ?CallInfo}
     */
    private static function frameSlots(Coroutine $L, CallInfo $ci): array
    {
        $next = null;
        $frame = $L->ci;
        while ($frame !== $ci && $frame !== null) {
            $next = $frame;
            $frame = $frame->previous;
        }
        $function = $ci->func;
        if ($next === null || $frame === null) {  // the running frame: up to L->top
            return [$function instanceof LuaClosure ? $function->proto->maxstacksize : \count($ci->R), [], null];
        }
        $above = [];
        $calleeSlotKnown = true;
        $hookedFrames = self::hookedFrames();
        if (isset($hookedFrames[$ci])) {
            [$ownSlots, $above[]] = $hookedFrames[$ci];
        } elseif ($function instanceof LuaClosure) {
            $instruction = $function->proto->code[$ci->savedpc];
            $a = OpCodes::GETARG_A($instruction);
            $ownSlots = match (OpCodes::GET_OPCODE($instruction)) {
                OpCodes::OP_CALL, OpCodes::OP_TAILCALL => $a,
                OpCodes::OP_TFORCALL => $a + 4,
                OpCodes::OP_NEWTABLE, OpCodes::OP_CONCAT, OpCodes::OP_CLOSURE => ($ci->callstatus & Lua::CIST_FIN) ? $a + 1 : $function->proto->maxstacksize,
                default => $function->proto->maxstacksize,
            };
        } else {
            $ownSlots = \count($ci->R);
            $calleeSlotKnown = false;
        }
        $callee = $next->func;
        if ($calleeSlotKnown && $callee instanceof LuaClosure && $callee->proto->is_vararg) {
            $above[] = $callee;
            for ($i = 0; $i < $callee->proto->numparams; $i++) {
                $above[] = null;  // erased original parameter
            }
            foreach ($next->varargs as $value) {
                $above[] = $value;
            }
        }
        return [$ownSlots, $above, $next];
    }

    /* }====================================================== */
}
