<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

use LuaPhp\Runtime\NumberFormat;

/**
 * Port of luac.c's bytecode printer (PrintFunction and helpers), used by
 * `luac -l` (full = false) and `luac -l -l` (full = true).
 *
 * Output is identical to luac5.4's except the '%p' addresses: C prints the
 * Proto's memory address, this prints a stable per-object id in the same
 * "0x..." shape, so CLOSURE comments still match function headers.
 *
 * @internal
 */
final class Listing
{
    // ltm.c: luaT_eventname (ORDER TM), indexed by the C argument of OP_MMBIN*
    private const EVENT_NAMES = [
        '__index', '__newindex',
        '__gc', '__mode', '__len', '__eq',
        '__add', '__sub', '__mul', '__mod', '__pow',
        '__div', '__idiv',
        '__band', '__bor', '__bxor', '__shl', '__shr',
        '__unm', '__bnot', '__lt', '__le',
        '__concat', '__call', '__close',
    ];

    // ldebug.h: MAXIWTHABS
    private const MAXIWTHABS = 128;

    private const COMMENT = "\t; ";

    // luac.c: PrintFunction
    public static function printFunction(Proto $f, bool $full): string
    {
        $output = self::printHeader($f);
        $output .= self::printCode($f);
        if ($full) {
            $output .= self::printDebug($f);
        }
        foreach ($f->p as $childProto) {
            $output .= self::printFunction($childProto, $full);
        }
        return $output;
    }

    // luac.c: PrintString
    private static function printString(string $string): string
    {
        $output = '"';
        $length = strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($string[$i]);
            $output .= match ($byte) {
                0x22 => '\\"',
                0x5c => '\\\\',
                0x07 => '\\a',
                0x08 => '\\b',
                0x0c => '\\f',
                0x0a => '\\n',
                0x0d => '\\r',
                0x09 => '\\t',
                0x0b => '\\v',
                // isprint() in the C locale
                default => ($byte >= 0x20 && $byte <= 0x7e) ? chr($byte) : sprintf('\\%03d', $byte),
            };
        }
        return $output . '"';
    }

    // luac.c: PrintType
    private static function printType(Proto $f, int $i): string
    {
        $constant = $f->k[$i] ?? null;
        $typeLetter = match (true) {
            $constant === null => 'N',
            is_bool($constant) => 'B',
            is_float($constant) => 'F',
            is_int($constant) => 'I',
            default => 'S',
        };
        return $typeLetter . "\t";
    }

    // luac.c: PrintConstant
    private static function printConstant(Proto $f, int $i): string
    {
        $constant = $f->k[$i] ?? null;
        return match (true) {
            $constant === null => 'nil',
            $constant === false => 'false',
            $constant === true => 'true',
            is_float($constant) => NumberFormat::luaNumberToString($constant),
            is_int($constant) => (string) $constant,
            default => self::printString($constant),
        };
    }

    // luac.c: UPVALNAME
    private static function upvalueName(Proto $f, int $index): string
    {
        return $f->upvalues[$index]->name ?? '-';
    }

    // luac.c: VOID(p) printed with '%p'
    private static function pointer(Proto $f): string
    {
        return sprintf('0x%08x', spl_object_id($f));
    }

    // luac.c: PrintCode
    private static function printCode(Proto $f): string
    {
        $output = '';
        $code = $f->code;
        $instructionCount = count($code);
        for ($pc = 0; $pc < $instructionCount; $pc++) {
            $i = $code[$pc];
            $o = OpCodes::GET_OPCODE($i);
            $a = OpCodes::GETARG_A($i);
            $b = OpCodes::GETARG_B($i);
            $c = OpCodes::GETARG_C($i);
            $ax = OpCodes::GETARG_Ax($i);
            $bx = OpCodes::GETARG_Bx($i);
            $sb = OpCodes::GETARG_sB($i);
            $sc = OpCodes::GETARG_sC($i);
            $sbx = OpCodes::GETARG_sBx($i);
            $isk = OpCodes::GETARG_k($i);
            $kSuffix = $isk !== 0 ? 'k' : '';  // luac.c: ISK
            // luac.c: EXTRAARG / EXTRAARGC (argument of the following OP_EXTRAARG)
            $extraArg = OpCodes::GETARG_Ax($code[$pc + 1] ?? 0);
            $extraArgC = $extraArg * (OpCodes::MAXARG_C + 1);
            $line = self::getFuncLine($f, $pc);

            $output .= sprintf("\t%d\t", $pc + 1);
            $output .= $line > 0 ? sprintf("[%d]\t", $line) : "[-]\t";
            $output .= sprintf("%-9s\t", OpCodes::OPNAMES[$o] ?? '');
            switch ($o) {
                case OpCodes::OP_MOVE:
                    $output .= "$a $b";
                    break;
                case OpCodes::OP_LOADI:
                case OpCodes::OP_LOADF:
                    $output .= "$a $sbx";
                    break;
                case OpCodes::OP_LOADK:
                    $output .= "$a $bx";
                    $output .= self::COMMENT . self::printConstant($f, $bx);
                    break;
                case OpCodes::OP_LOADKX:
                    $output .= "$a";
                    $output .= self::COMMENT . self::printConstant($f, $extraArg);
                    break;
                case OpCodes::OP_LOADFALSE:
                case OpCodes::OP_LFALSESKIP:
                case OpCodes::OP_LOADTRUE:
                    $output .= "$a";
                    break;
                case OpCodes::OP_LOADNIL:
                    $output .= "$a $b";
                    $output .= self::COMMENT . ($b + 1) . ' out';
                    break;
                case OpCodes::OP_GETUPVAL:
                case OpCodes::OP_SETUPVAL:
                    $output .= "$a $b";
                    $output .= self::COMMENT . self::upvalueName($f, $b);
                    break;
                case OpCodes::OP_GETTABUP:
                    $output .= "$a $b $c";
                    $output .= self::COMMENT . self::upvalueName($f, $b);
                    $output .= ' ' . self::printConstant($f, $c);
                    break;
                case OpCodes::OP_GETTABLE:
                case OpCodes::OP_GETI:
                    $output .= "$a $b $c";
                    break;
                case OpCodes::OP_GETFIELD:
                    $output .= "$a $b $c";
                    $output .= self::COMMENT . self::printConstant($f, $c);
                    break;
                case OpCodes::OP_SETTABUP:
                    $output .= "$a $b $c$kSuffix";
                    $output .= self::COMMENT . self::upvalueName($f, $a);
                    $output .= ' ' . self::printConstant($f, $b);
                    if ($isk !== 0) {
                        $output .= ' ' . self::printConstant($f, $c);
                    }
                    break;
                case OpCodes::OP_SETTABLE:
                case OpCodes::OP_SETI:
                    $output .= "$a $b $c$kSuffix";
                    if ($isk !== 0) {
                        $output .= self::COMMENT . self::printConstant($f, $c);
                    }
                    break;
                case OpCodes::OP_SETFIELD:
                    $output .= "$a $b $c$kSuffix";
                    $output .= self::COMMENT . self::printConstant($f, $b);
                    if ($isk !== 0) {
                        $output .= ' ' . self::printConstant($f, $c);
                    }
                    break;
                case OpCodes::OP_NEWTABLE:
                    $output .= "$a $b $c";
                    $output .= self::COMMENT . ($c + $extraArgC);
                    break;
                case OpCodes::OP_SELF:
                    $output .= "$a $b $c$kSuffix";
                    if ($isk !== 0) {
                        $output .= self::COMMENT . self::printConstant($f, $c);
                    }
                    break;
                case OpCodes::OP_ADDI:
                case OpCodes::OP_SHRI:
                case OpCodes::OP_SHLI:
                    $output .= "$a $b $sc";
                    break;
                case OpCodes::OP_ADDK:
                case OpCodes::OP_SUBK:
                case OpCodes::OP_MULK:
                case OpCodes::OP_MODK:
                case OpCodes::OP_POWK:
                case OpCodes::OP_DIVK:
                case OpCodes::OP_IDIVK:
                case OpCodes::OP_BANDK:
                case OpCodes::OP_BORK:
                case OpCodes::OP_BXORK:
                    $output .= "$a $b $c";
                    $output .= self::COMMENT . self::printConstant($f, $c);
                    break;
                case OpCodes::OP_ADD:
                case OpCodes::OP_SUB:
                case OpCodes::OP_MUL:
                case OpCodes::OP_MOD:
                case OpCodes::OP_POW:
                case OpCodes::OP_DIV:
                case OpCodes::OP_IDIV:
                case OpCodes::OP_BAND:
                case OpCodes::OP_BOR:
                case OpCodes::OP_BXOR:
                case OpCodes::OP_SHL:
                case OpCodes::OP_SHR:
                    $output .= "$a $b $c";
                    break;
                case OpCodes::OP_MMBIN:
                    $output .= "$a $b $c";
                    $output .= self::COMMENT . (self::EVENT_NAMES[$c] ?? '');
                    break;
                case OpCodes::OP_MMBINI:
                    $output .= "$a $sb $c $isk";
                    $output .= self::COMMENT . (self::EVENT_NAMES[$c] ?? '');
                    if ($isk !== 0) {
                        $output .= ' flip';
                    }
                    break;
                case OpCodes::OP_MMBINK:
                    $output .= "$a $b $c $isk";
                    $output .= self::COMMENT . (self::EVENT_NAMES[$c] ?? '') . ' ' . self::printConstant($f, $b);
                    if ($isk !== 0) {
                        $output .= ' flip';
                    }
                    break;
                case OpCodes::OP_UNM:
                case OpCodes::OP_BNOT:
                case OpCodes::OP_NOT:
                case OpCodes::OP_LEN:
                case OpCodes::OP_CONCAT:
                    $output .= "$a $b";
                    break;
                case OpCodes::OP_CLOSE:
                case OpCodes::OP_TBC:
                    $output .= "$a";
                    break;
                case OpCodes::OP_JMP:
                    $sj = OpCodes::GETARG_sJ($i);
                    $output .= "$sj";
                    $output .= self::COMMENT . 'to ' . ($sj + $pc + 2);
                    break;
                case OpCodes::OP_EQ:
                case OpCodes::OP_LT:
                case OpCodes::OP_LE:
                    $output .= "$a $b $isk";
                    break;
                case OpCodes::OP_EQK:
                    $output .= "$a $b $isk";
                    $output .= self::COMMENT . self::printConstant($f, $b);
                    break;
                case OpCodes::OP_EQI:
                case OpCodes::OP_LTI:
                case OpCodes::OP_LEI:
                case OpCodes::OP_GTI:
                case OpCodes::OP_GEI:
                    $output .= "$a $sb $isk";
                    break;
                case OpCodes::OP_TEST:
                    $output .= "$a $isk";
                    break;
                case OpCodes::OP_TESTSET:
                    $output .= "$a $b $isk";
                    break;
                case OpCodes::OP_CALL:
                    $output .= "$a $b $c";
                    $output .= self::COMMENT;
                    $output .= $b === 0 ? 'all in ' : ($b - 1) . ' in ';
                    $output .= $c === 0 ? 'all out' : ($c - 1) . ' out';
                    break;
                case OpCodes::OP_TAILCALL:
                    $output .= "$a $b $c$kSuffix";
                    $output .= self::COMMENT . ($b - 1) . ' in';
                    break;
                case OpCodes::OP_RETURN:
                    $output .= "$a $b $c$kSuffix";
                    $output .= self::COMMENT;
                    $output .= $b === 0 ? 'all out' : ($b - 1) . ' out';
                    break;
                case OpCodes::OP_RETURN0:
                    break;
                case OpCodes::OP_RETURN1:
                    $output .= "$a";
                    break;
                case OpCodes::OP_FORLOOP:
                    $output .= "$a $bx";
                    $output .= self::COMMENT . 'to ' . ($pc - $bx + 2);
                    break;
                case OpCodes::OP_FORPREP:
                    $output .= "$a $bx";
                    $output .= self::COMMENT . 'exit to ' . ($pc + $bx + 3);
                    break;
                case OpCodes::OP_TFORPREP:
                    $output .= "$a $bx";
                    $output .= self::COMMENT . 'to ' . ($pc + $bx + 2);
                    break;
                case OpCodes::OP_TFORCALL:
                    $output .= "$a $c";
                    break;
                case OpCodes::OP_TFORLOOP:
                    $output .= "$a $bx";
                    $output .= self::COMMENT . 'to ' . ($pc - $bx + 2);
                    break;
                case OpCodes::OP_SETLIST:
                    $output .= "$a $b $c";
                    if ($isk !== 0) {
                        $output .= self::COMMENT . ($c + $extraArgC);
                    }
                    break;
                case OpCodes::OP_CLOSURE:
                    $output .= "$a $bx";
                    $output .= self::COMMENT . (isset($f->p[$bx]) ? self::pointer($f->p[$bx]) : '(nil)');
                    break;
                case OpCodes::OP_VARARG:
                    $output .= "$a $c";
                    $output .= self::COMMENT;
                    $output .= $c === 0 ? 'all out' : ($c - 1) . ' out';
                    break;
                case OpCodes::OP_VARARGPREP:
                    $output .= "$a";
                    break;
                case OpCodes::OP_EXTRAARG:
                    $output .= "$ax";
                    break;
            }
            $output .= "\n";
        }
        return $output;
    }

    // luac.c: SS (plural suffix)
    private static function pluralSuffix(int $count): string
    {
        return $count === 1 ? '' : 's';
    }

    // luac.c: PrintHeader
    private static function printHeader(Proto $f): string
    {
        $source = $f->source ?? '=?';
        if ($source !== '' && ($source[0] === '@' || $source[0] === '=')) {
            $source = substr($source, 1);
        } elseif ($source !== '' && $source[0] === Undump::LUA_SIGNATURE[0]) {
            $source = '(bstring)';
        } else {
            $source = '(string)';
        }
        $instructionCount = count($f->code);
        $slotCount = $f->maxstacksize;
        $upvalueCount = count($f->upvalues);
        $localCount = count($f->locvars);
        $constantCount = count($f->k);
        $functionCount = count($f->p);
        return sprintf(
            "\n%s <%s:%d,%d> (%d instruction%s at %s)\n",
            $f->linedefined === 0 ? 'main' : 'function',
            $source,
            $f->linedefined,
            $f->lastlinedefined,
            $instructionCount,
            self::pluralSuffix($instructionCount),
            self::pointer($f),
        ) . sprintf(
            '%d%s param%s, %d slot%s, %d upvalue%s, ',
            $f->numparams,
            $f->is_vararg ? '+' : '',
            self::pluralSuffix($f->numparams),
            $slotCount,
            self::pluralSuffix($slotCount),
            $upvalueCount,
            self::pluralSuffix($upvalueCount),
        ) . sprintf(
            "%d local%s, %d constant%s, %d function%s\n",
            $localCount,
            self::pluralSuffix($localCount),
            $constantCount,
            self::pluralSuffix($constantCount),
            $functionCount,
            self::pluralSuffix($functionCount),
        );
    }

    // luac.c: PrintDebug
    private static function printDebug(Proto $f): string
    {
        $output = sprintf("constants (%d) for %s:\n", count($f->k), self::pointer($f));
        foreach (array_keys($f->k) as $i) {
            $output .= "\t$i\t" . self::printType($f, $i) . self::printConstant($f, $i) . "\n";
        }
        $output .= sprintf("locals (%d) for %s:\n", count($f->locvars), self::pointer($f));
        foreach ($f->locvars as $i => $locvar) {
            $output .= sprintf("\t%d\t%s\t%d\t%d\n", $i, $locvar->varname ?? '', $locvar->startpc + 1, $locvar->endpc + 1);
        }
        $output .= sprintf("upvalues (%d) for %s:\n", count($f->upvalues), self::pointer($f));
        foreach ($f->upvalues as $i => $upvalue) {
            $output .= sprintf("\t%d\t%s\t%d\t%d\n", $i, self::upvalueName($f, $i), $upvalue->instack ? 1 : 0, $upvalue->idx);
        }
        return $output;
    }

    /**
     * ldebug.c: luaG_getfuncline (with getbaseline). Line of instruction
     * $pc, or -1 when the function has no line information (stripped).
     */
    private static function getFuncLine(Proto $f, int $pc): int
    {
        if ($f->lineinfo === []) {  // no debug information?
            return -1;
        }
        // ldebug.c: getbaseline
        $abslineinfo = $f->abslineinfo;
        $abslineinfoCount = count($abslineinfo);
        if ($abslineinfoCount === 0 || $pc < $abslineinfo[0]->pc) {
            $basepc = -1;  // start from the beginning
            $baseline = $f->linedefined;
        } else {
            $i = intdiv($pc, self::MAXIWTHABS) - 1;  // get an estimate
            while ($i + 1 < $abslineinfoCount && $pc >= $abslineinfo[$i + 1]->pc) {
                $i++;  // low estimate; adjust it
            }
            $basepc = $abslineinfo[$i]->pc;
            $baseline = $abslineinfo[$i]->line;
        }
        while ($basepc < $pc) {  // walk until given instruction
            $basepc++;
            $baseline += $f->lineinfo[$basepc];  // correct line
        }
        return $baseline;
    }
}
