<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

use LuaPhp\Compiler\ExpDesc as E;
use LuaPhp\Compiler\OpCodes as O;

/**
 * Port of lcode.c (Lua 5.4.9): the code generator. Every function keeps
 * its C name; C pointer arguments that the callee assigns ('int *l1',
 * 'int *pi') are PHP references, and C pointers to instructions are
 * indices into $fs->f->code.
 *
 * @internal
 */
final class CodeGen
{
    // lcode.h: marks the end of a patch list
    public const NO_JUMP = -1;

    // lua.h: LUA_MULTRET
    public const LUA_MULTRET = -1;

    // lcode.h: enum BinOpr ("ORDER OPR")
    public const OPR_ADD = 0;
    public const OPR_SUB = 1;
    public const OPR_MUL = 2;
    public const OPR_MOD = 3;
    public const OPR_POW = 4;
    public const OPR_DIV = 5;
    public const OPR_IDIV = 6;
    public const OPR_BAND = 7;
    public const OPR_BOR = 8;
    public const OPR_BXOR = 9;
    public const OPR_SHL = 10;
    public const OPR_SHR = 11;
    public const OPR_CONCAT = 12;
    public const OPR_EQ = 13;
    public const OPR_LT = 14;
    public const OPR_LE = 15;
    public const OPR_NE = 16;
    public const OPR_GT = 17;
    public const OPR_GE = 18;
    public const OPR_AND = 19;
    public const OPR_OR = 20;
    public const OPR_NOBINOPR = 21;

    // lcode.h: enum UnOpr
    public const OPR_MINUS = 0;
    public const OPR_BNOT = 1;
    public const OPR_NOT = 2;
    public const OPR_LEN = 3;
    public const OPR_NOUNOPR = 4;

    // ltm.h: TMS values used by the MMBIN instructions
    private const TM_ADD = 6;
    private const TM_SUB = 7;
    private const TM_SHL = 16;
    private const TM_SHR = 17;

    // lcode.c: maximum number of registers in a Lua function (must fit in 8 bits)
    private const MAXREGS = 255;

    // lcode.c: limit for difference between lines in relative line info
    private const LIMLINEDIFF = 0x80;

    // ldebug.h: ABSLINEINFO and MAXIWTHABS
    private const ABSLINEINFO = -0x80;
    private const MAXIWTHABS = 128;

    // lcode.c previousinstruction: '~(Instruction)0', an invalid instruction
    private const INVALID_INSTRUCTION = 0xFFFFFFFF;

    // lua.h: status code of errors raised with luaG_runerror
    public const LUA_ERRRUN = 2;

    // lcode.c: hasjumps
    private static function hasjumps(E $e): bool
    {
        return $e->t !== $e->f;
    }

    // lcode.c: luaK_semerror
    public static function luaK_semerror(Lexer $ls, string $message): never
    {
        $ls->t->token = 0;  // remove "near <token>" from final message
        $ls->luaX_syntaxerror($message);
    }

    /**
     * lmem.c luaM_growaux_ via luaM_growvector: a vector that already holds
     * 'limit' elements cannot grow. luaG_runerror from inside the parser
     * adds no position (the running function is the C function 'load').
     */
    public static function checkVectorLimit(int $elementCount, int $limit, string $what): void
    {
        if ($elementCount >= $limit) {
            throw new CompileError("too many $what (limit is $limit)", self::LUA_ERRRUN);
        }
    }

    /**
     * lcode.c: tonumeral. The numeric constant of 'e', or null if it is not
     * a numeral.
     */
    private static function tonumeral(E $e): int|float|null
    {
        if (self::hasjumps($e)) {
            return null;  // not a numeral
        }
        return match ($e->k) {
            E::VKINT => $e->ival,
            E::VKFLT => $e->nval,
            default => null,
        };
    }

    // lcode.c: const2val
    private static function const2val(FuncState $fs, E $e): null|bool|int|float|string
    {
        return $fs->ls->dyd->actvar[$e->info]->k;
    }

    // lcode.c: luaK_exp2const (fills $value and returns true for a constant)
    public static function luaK_exp2const(FuncState $fs, E $e, null|bool|int|float|string &$value): bool
    {
        if (self::hasjumps($e)) {
            return false;  // not a constant
        }
        switch ($e->k) {
            case E::VFALSE:
                $value = false;
                return true;
            case E::VTRUE:
                $value = true;
                return true;
            case E::VNIL:
                $value = null;
                return true;
            case E::VKSTR:
                $value = $e->strval;
                return true;
            case E::VCONST:
                $value = self::const2val($fs, $e);
                return true;
            default:
                $numeral = self::tonumeral($e);
                if ($numeral === null) {
                    return false;
                }
                $value = $numeral;
                return true;
        }
    }

    /**
     * lcode.c: previousinstruction. The index of the previous instruction,
     * or -1 (C's invalid instruction) if there may be a jump target between
     * it and the current one.
     */
    private static function previousinstruction(FuncState $fs): int
    {
        if ($fs->pc > $fs->lasttarget) {
            return $fs->pc - 1;  // previous instruction
        }
        return -1;
    }

    private static function instructionAt(FuncState $fs, int $index): int
    {
        return $index >= 0 ? $fs->f->code[$index] : self::INVALID_INSTRUCTION;
    }

    // lcode.c: luaK_nil
    public static function luaK_nil(FuncState $fs, int $from, int $n): void
    {
        $last = $from + $n - 1;  // last register to set nil
        $previousIndex = self::previousinstruction($fs);
        $previous = self::instructionAt($fs, $previousIndex);
        if (O::GET_OPCODE($previous) === O::OP_LOADNIL) {  // previous is LOADNIL?
            $previousFrom = O::GETARG_A($previous);  // get previous range
            $previousLast = $previousFrom + O::GETARG_B($previous);
            if (($previousFrom <= $from && $from <= $previousLast + 1)
                || ($from <= $previousFrom && $previousFrom <= $last + 1)) {  // can connect both?
                if ($previousFrom < $from) {
                    $from = $previousFrom;  // from = min(from, pfrom)
                }
                if ($previousLast > $last) {
                    $last = $previousLast;  // l = max(l, pl)
                }
                $previous = O::SETARG_A($previous, $from);
                $fs->f->code[$previousIndex] = O::SETARG_B($previous, $last - $from);
                return;
            }  // else go through
        }
        self::luaK_codeABC($fs, O::OP_LOADNIL, $from, $n - 1, 0);  // else no optimization
    }

    // lcode.c: getjump
    private static function getjump(FuncState $fs, int $pc): int
    {
        $offset = O::GETARG_sJ($fs->f->code[$pc]);
        if ($offset === self::NO_JUMP) {  // point to itself represents end of list
            return self::NO_JUMP;  // end of list
        }
        return ($pc + 1) + $offset;  // turn offset into absolute position
    }

    // lcode.c: fixjump
    private static function fixjump(FuncState $fs, int $pc, int $dest): void
    {
        $offset = $dest - ($pc + 1);
        if (!(-O::OFFSET_sJ <= $offset && $offset <= O::MAXARG_sJ - O::OFFSET_sJ)) {
            $fs->ls->luaX_syntaxerror('control structure too long');
        }
        $fs->f->code[$pc] = O::SETARG_sJ($fs->f->code[$pc], $offset);
    }

    // lcode.c: luaK_concat (concatenate jump-list 'l2' into jump-list 'l1')
    public static function luaK_concat(FuncState $fs, int &$l1, int $l2): void
    {
        if ($l2 === self::NO_JUMP) {
            return;  // nothing to concatenate?
        }
        if ($l1 === self::NO_JUMP) {  // no original list?
            $l1 = $l2;  // 'l1' points to 'l2'
            return;
        }
        $list = $l1;
        while (($next = self::getjump($fs, $list)) !== self::NO_JUMP) {  // find last element
            $list = $next;
        }
        self::fixjump($fs, $list, $l2);  // last element links to 'l2'
    }

    // lcode.c: luaK_jump
    public static function luaK_jump(FuncState $fs): int
    {
        return self::codesJ($fs, O::OP_JMP, self::NO_JUMP, 0);
    }

    // lcode.c: luaK_ret
    public static function luaK_ret(FuncState $fs, int $first, int $nret): void
    {
        $op = match ($nret) {
            0 => O::OP_RETURN0,
            1 => O::OP_RETURN1,
            default => O::OP_RETURN,
        };
        self::luaK_codeABC($fs, $op, $first, $nret + 1, 0);
    }

    // lcode.c: condjump
    private static function condjump(FuncState $fs, int $op, int $a, int $b, int $c, int $k): int
    {
        self::luaK_codeABCk($fs, $op, $a, $b, $c, $k);
        return self::luaK_jump($fs);
    }

    // lcode.c: luaK_getlabel
    public static function luaK_getlabel(FuncState $fs): int
    {
        $fs->lasttarget = $fs->pc;
        return $fs->pc;
    }

    // lcode.c: getjumpcontrol (index of the instruction controlling a jump)
    private static function getjumpcontrol(FuncState $fs, int $pc): int
    {
        if ($pc >= 1 && O::testTMode(O::GET_OPCODE($fs->f->code[$pc - 1]))) {
            return $pc - 1;
        }
        return $pc;
    }

    // lcode.c: patchtestreg
    private static function patchtestreg(FuncState $fs, int $node, int $reg): bool
    {
        $index = self::getjumpcontrol($fs, $node);
        $instruction = $fs->f->code[$index];
        if (O::GET_OPCODE($instruction) !== O::OP_TESTSET) {
            return false;  // cannot patch other instructions
        }
        if ($reg !== O::NO_REG && $reg !== O::GETARG_B($instruction)) {
            $fs->f->code[$index] = O::SETARG_A($instruction, $reg);
        } else {
            // no register to put value or register already has the value;
            // change instruction to simple test
            $fs->f->code[$index] = O::CREATE_ABCk(O::OP_TEST, O::GETARG_B($instruction), 0, 0, O::GETARG_k($instruction));
        }
        return true;
    }

    // lcode.c: removevalues
    private static function removevalues(FuncState $fs, int $list): void
    {
        for (; $list !== self::NO_JUMP; $list = self::getjump($fs, $list)) {
            self::patchtestreg($fs, $list, O::NO_REG);
        }
    }

    // lcode.c: patchlistaux
    private static function patchlistaux(FuncState $fs, int $list, int $vtarget, int $reg, int $dtarget): void
    {
        while ($list !== self::NO_JUMP) {
            $next = self::getjump($fs, $list);
            if (self::patchtestreg($fs, $list, $reg)) {
                self::fixjump($fs, $list, $vtarget);
            } else {
                self::fixjump($fs, $list, $dtarget);  // jump to default target
            }
            $list = $next;
        }
    }

    // lcode.c: luaK_patchlist
    public static function luaK_patchlist(FuncState $fs, int $list, int $target): void
    {
        self::patchlistaux($fs, $list, $target, O::NO_REG, $target);
    }

    // lcode.c: luaK_patchtohere
    public static function luaK_patchtohere(FuncState $fs, int $list): void
    {
        $here = self::luaK_getlabel($fs);  // mark "here" as a jump target
        self::luaK_patchlist($fs, $list, $here);
    }

    // lcode.c: luaK_jumpto (lcode.h macro)
    public static function luaK_jumpto(FuncState $fs, int $target): void
    {
        self::luaK_patchlist($fs, self::luaK_jump($fs), $target);
    }

    // lcode.c: savelineinfo
    private static function savelineinfo(FuncState $fs, Proto $f, int $line): void
    {
        $linedif = $line - $fs->previousline;
        $pc = $fs->pc - 1;  // last instruction coded
        if (abs($linedif) >= self::LIMLINEDIFF || $fs->iwthabs++ >= self::MAXIWTHABS) {
            $f->abslineinfo[$fs->nabslineinfo++] = new AbsLineInfo($pc, $line);
            $linedif = self::ABSLINEINFO;  // signal that there is absolute information
            $fs->iwthabs = 1;  // restart counter
        }
        $f->lineinfo[$pc] = $linedif;
        $fs->previousline = $line;  // last line saved
    }

    // lcode.c: removelastlineinfo
    private static function removelastlineinfo(FuncState $fs): void
    {
        $f = $fs->f;
        $pc = $fs->pc - 1;  // last instruction coded
        if ($f->lineinfo[$pc] !== self::ABSLINEINFO) {  // relative line info?
            $fs->previousline -= $f->lineinfo[$pc];  // correct last line saved
            $fs->iwthabs--;  // undo previous increment
        } else {  // absolute line information
            $fs->nabslineinfo--;  // remove it
            $fs->iwthabs = self::MAXIWTHABS + 1;  // force next line info to be absolute
        }
    }

    // lcode.c: removelastinstruction
    private static function removelastinstruction(FuncState $fs): void
    {
        self::removelastlineinfo($fs);
        $fs->pc--;
    }

    // lcode.c: luaK_code
    public static function luaK_code(FuncState $fs, int $instruction): int
    {
        $f = $fs->f;
        $f->code[$fs->pc++] = $instruction;  // put new instruction in code array
        self::savelineinfo($fs, $f, $fs->ls->lastline);
        return $fs->pc - 1;  // index of new instruction
    }

    // lcode.c: luaK_codeABCk
    public static function luaK_codeABCk(FuncState $fs, int $op, int $a, int $b, int $c, int $k): int
    {
        return self::luaK_code($fs, O::CREATE_ABCk($op, $a, $b, $c, $k));
    }

    // lcode.h: luaK_codeABC
    public static function luaK_codeABC(FuncState $fs, int $op, int $a, int $b, int $c): int
    {
        return self::luaK_code($fs, O::CREATE_ABCk($op, $a, $b, $c, 0));
    }

    // lcode.c: luaK_codeABx
    public static function luaK_codeABx(FuncState $fs, int $op, int $a, int $bc): int
    {
        return self::luaK_code($fs, O::CREATE_ABx($op, $a, $bc));
    }

    // lcode.c: codeAsBx
    private static function codeAsBx(FuncState $fs, int $op, int $a, int $bc): int
    {
        return self::luaK_code($fs, O::CREATE_ABx($op, $a, $bc + O::OFFSET_sBx));
    }

    // lcode.c: codesJ
    private static function codesJ(FuncState $fs, int $op, int $sj, int $k): int
    {
        return self::luaK_code($fs, O::CREATE_sJ($op, $sj + O::OFFSET_sJ, $k));
    }

    // lcode.c: codeextraarg
    private static function codeextraarg(FuncState $fs, int $a): int
    {
        return self::luaK_code($fs, O::CREATE_Ax(O::OP_EXTRAARG, $a));
    }

    // lcode.c: luaK_codek
    private static function luaK_codek(FuncState $fs, int $reg, int $k): int
    {
        if ($k <= O::MAXARG_Bx) {
            return self::luaK_codeABx($fs, O::OP_LOADK, $reg, $k);
        }
        $p = self::luaK_codeABx($fs, O::OP_LOADKX, $reg, 0);
        self::codeextraarg($fs, $k);
        return $p;
    }

    // lcode.c: luaK_checkstack
    public static function luaK_checkstack(FuncState $fs, int $n): void
    {
        $newstack = $fs->freereg + $n;
        if ($newstack > $fs->f->maxstacksize) {
            if ($newstack >= self::MAXREGS) {
                $fs->ls->luaX_syntaxerror('function or expression needs too many registers');
            }
            $fs->f->maxstacksize = $newstack;
        }
    }

    // lcode.c: luaK_reserveregs
    public static function luaK_reserveregs(FuncState $fs, int $n): void
    {
        self::luaK_checkstack($fs, $n);
        $fs->freereg += $n;
    }

    // lcode.c: freereg
    private static function freereg(FuncState $fs, int $reg): void
    {
        if ($reg >= Parser::luaY_nvarstack($fs)) {
            $fs->freereg--;
        }
    }

    // lcode.c: freeregs
    private static function freeregs(FuncState $fs, int $r1, int $r2): void
    {
        if ($r1 > $r2) {
            self::freereg($fs, $r1);
            self::freereg($fs, $r2);
        } else {
            self::freereg($fs, $r2);
            self::freereg($fs, $r1);
        }
    }

    // lcode.c: freeexp
    private static function freeexp(FuncState $fs, E $e): void
    {
        if ($e->k === E::VNONRELOC) {
            self::freereg($fs, $e->info);
        }
    }

    // lcode.c: freeexps
    private static function freeexps(FuncState $fs, E $e1, E $e2): void
    {
        $r1 = ($e1->k === E::VNONRELOC) ? $e1->info : -1;
        $r2 = ($e2->k === E::VNONRELOC) ? $e2->info : -1;
        self::freeregs($fs, $r1, $r2);
    }

    /**
     * lcode.c: addk. $key is the scanner-table key (see constantKey); the
     * cache is shared by all functions, so a cached index may belong to
     * another function and must be checked.
     */
    private static function addk(FuncState $fs, string $key, null|bool|int|float|string $value): int
    {
        $f = $fs->f;
        $cachedIndex = $fs->ls->constantCache[$key] ?? null;
        if ($cachedIndex !== null) {  // is there an index there?
            // correct value? (warning: must distinguish floats from integers!)
            if ($cachedIndex < $fs->nk && $f->k[$cachedIndex] === $value) {
                return $cachedIndex;  // reuse index
            }
        }
        // constant not found; create a new entry
        $index = $fs->nk;
        $fs->ls->constantCache[$key] = $index;
        self::checkVectorLimit($index, O::MAXARG_Ax, 'constants');
        $f->k[$index] = $value;
        $fs->nk++;
        return $index;
    }

    /**
     * The key a Lua table would use for float $n: ltable.c luaH_get turns
     * floats with integral values into integer keys.
     */
    private static function floatKey(float $n): string
    {
        $integer = RawArith::luaV_flttointeger($n);
        if ($integer !== null) {
            return 'i' . $integer;
        }
        return 'f' . pack('E', $n);
    }

    // lcode.c: stringK
    private static function stringK(FuncState $fs, string $s): int
    {
        return self::addk($fs, 's' . $s, $s);  // use string itself as key
    }

    // lcode.c: luaK_intK
    private static function luaK_intK(FuncState $fs, int $n): int
    {
        return self::addk($fs, 'i' . $n, $n);  // use integer itself as key
    }

    /**
     * lcode.c: luaK_numberK. Floats with integral values need a different
     * key, to avoid collision with actual integers: add to the number its
     * smaller power-of-two fraction that is still significant in its scale.
     */
    private static function luaK_numberK(FuncState $fs, float $r): int
    {
        $integer = RawArith::luaV_flttointeger($r);
        if ($integer === null) {  // not an integral value?
            return self::addk($fs, self::floatKey($r), $r);  // use number itself as key
        }
        $q = 2.0 ** -52;  // ldexp(1.0, -nbm + 1) with nbm = DBL_MANT_DIG = 53
        $alternativeKey = ($integer === 0) ? $q : $r + $r * $q;  // new key
        return self::addk($fs, self::floatKey($alternativeKey), $r);
    }

    // lcode.c: boolF
    private static function boolF(FuncState $fs): int
    {
        return self::addk($fs, 'false', false);  // use boolean itself as key
    }

    // lcode.c: boolT
    private static function boolT(FuncState $fs): int
    {
        return self::addk($fs, 'true', true);  // use boolean itself as key
    }

    // lcode.c: nilK (nil cannot be a key; the scanner table itself stands for it)
    private static function nilK(FuncState $fs): int
    {
        return self::addk($fs, 'scanner table', null);
    }

    // lcode.c: fitsC (-OFFSET_sC <= i <= MAXARG_C - OFFSET_sC)
    private static function fitsC(int $i): bool
    {
        return $i >= -O::OFFSET_sC && $i <= O::MAXARG_C - O::OFFSET_sC;
    }

    // lcode.c: fitsBx
    private static function fitsBx(int $i): bool
    {
        return -O::OFFSET_sBx <= $i && $i <= O::MAXARG_Bx - O::OFFSET_sBx;
    }

    // lcode.c: luaK_int
    public static function luaK_int(FuncState $fs, int $reg, int $i): void
    {
        if (self::fitsBx($i)) {
            self::codeAsBx($fs, O::OP_LOADI, $reg, $i);
        } else {
            self::luaK_codek($fs, $reg, self::luaK_intK($fs, $i));
        }
    }

    // lcode.c: luaK_float
    private static function luaK_float(FuncState $fs, int $reg, float $f): void
    {
        $integer = RawArith::luaV_flttointeger($f);
        if ($integer !== null && self::fitsBx($integer)) {
            self::codeAsBx($fs, O::OP_LOADF, $reg, $integer);
        } else {
            self::luaK_codek($fs, $reg, self::luaK_numberK($fs, $f));
        }
    }

    // lcode.c: const2exp
    private static function const2exp(null|bool|int|float|string $value, E $e): void
    {
        if (is_int($value)) {
            $e->k = E::VKINT;
            $e->ival = $value;
        } elseif (is_float($value)) {
            $e->k = E::VKFLT;
            $e->nval = $value;
        } elseif ($value === false) {
            $e->k = E::VFALSE;
        } elseif ($value === true) {
            $e->k = E::VTRUE;
        } elseif ($value === null) {
            $e->k = E::VNIL;
        } else {
            $e->k = E::VKSTR;
            $e->strval = $value;
        }
    }

    // lcode.c: luaK_setreturns
    public static function luaK_setreturns(FuncState $fs, E $e, int $nresults): void
    {
        $f = $fs->f;
        if ($e->k === E::VCALL) {  // expression is an open function call?
            $f->code[$e->info] = O::SETARG_C($f->code[$e->info], $nresults + 1);
            return;
        }
        $instruction = O::SETARG_C($f->code[$e->info], $nresults + 1);
        $f->code[$e->info] = O::SETARG_A($instruction, $fs->freereg);
        self::luaK_reserveregs($fs, 1);
    }

    // lcode.h: luaK_setmultret
    public static function luaK_setmultret(FuncState $fs, E $e): void
    {
        self::luaK_setreturns($fs, $e, self::LUA_MULTRET);
    }

    // lcode.c: str2K
    private static function str2K(FuncState $fs, E $e): void
    {
        $e->info = self::stringK($fs, $e->strval);
        $e->k = E::VK;
    }

    // lcode.c: luaK_setoneret
    public static function luaK_setoneret(FuncState $fs, E $e): void
    {
        if ($e->k === E::VCALL) {  // expression is an open function call?
            // already returns 1 value
            $e->k = E::VNONRELOC;  // result has fixed position
            $e->info = O::GETARG_A($fs->f->code[$e->info]);
        } elseif ($e->k === E::VVARARG) {
            $fs->f->code[$e->info] = O::SETARG_C($fs->f->code[$e->info], 2);
            $e->k = E::VRELOC;  // can relocate its simple result
        }
    }

    // lcode.c: luaK_dischargevars
    public static function luaK_dischargevars(FuncState $fs, E $e): void
    {
        switch ($e->k) {
            case E::VCONST:
                self::const2exp(self::const2val($fs, $e), $e);
                break;
            case E::VLOCAL:  // already in a register
                $e->info = $e->varRidx;
                $e->k = E::VNONRELOC;  // becomes a non-relocatable value
                break;
            case E::VUPVAL:  // move value to some (pending) register
                $e->info = self::luaK_codeABC($fs, O::OP_GETUPVAL, 0, $e->info, 0);
                $e->k = E::VRELOC;
                break;
            case E::VINDEXUP:
                $e->info = self::luaK_codeABC($fs, O::OP_GETTABUP, 0, $e->indT, $e->indIdx);
                $e->k = E::VRELOC;
                break;
            case E::VINDEXI:
                self::freereg($fs, $e->indT);
                $e->info = self::luaK_codeABC($fs, O::OP_GETI, 0, $e->indT, $e->indIdx);
                $e->k = E::VRELOC;
                break;
            case E::VINDEXSTR:
                self::freereg($fs, $e->indT);
                $e->info = self::luaK_codeABC($fs, O::OP_GETFIELD, 0, $e->indT, $e->indIdx);
                $e->k = E::VRELOC;
                break;
            case E::VINDEXED:
                self::freeregs($fs, $e->indT, $e->indIdx);
                $e->info = self::luaK_codeABC($fs, O::OP_GETTABLE, 0, $e->indT, $e->indIdx);
                $e->k = E::VRELOC;
                break;
            case E::VVARARG:
            case E::VCALL:
                self::luaK_setoneret($fs, $e);
                break;
            default:
                break;  // there is one value available (somewhere)
        }
    }

    // lcode.c: discharge2reg
    private static function discharge2reg(FuncState $fs, E $e, int $reg): void
    {
        self::luaK_dischargevars($fs, $e);
        switch ($e->k) {
            case E::VNIL:
                self::luaK_nil($fs, $reg, 1);
                break;
            case E::VFALSE:
                self::luaK_codeABC($fs, O::OP_LOADFALSE, $reg, 0, 0);
                break;
            case E::VTRUE:
                self::luaK_codeABC($fs, O::OP_LOADTRUE, $reg, 0, 0);
                break;
            case E::VKSTR:
                self::str2K($fs, $e);
                self::luaK_codek($fs, $reg, $e->info);
                break;
            case E::VK:
                self::luaK_codek($fs, $reg, $e->info);
                break;
            case E::VKFLT:
                self::luaK_float($fs, $reg, $e->nval);
                break;
            case E::VKINT:
                self::luaK_int($fs, $reg, $e->ival);
                break;
            case E::VRELOC:
                $fs->f->code[$e->info] = O::SETARG_A($fs->f->code[$e->info], $reg);  // instruction will put result in 'reg'
                break;
            case E::VNONRELOC:
                if ($reg !== $e->info) {
                    self::luaK_codeABC($fs, O::OP_MOVE, $reg, $e->info, 0);
                }
                break;
            default:  // VJMP
                return;  // nothing to do...
        }
        $e->info = $reg;
        $e->k = E::VNONRELOC;
    }

    // lcode.c: discharge2anyreg
    private static function discharge2anyreg(FuncState $fs, E $e): void
    {
        if ($e->k !== E::VNONRELOC) {  // no fixed register yet?
            self::luaK_reserveregs($fs, 1);  // get a register
            self::discharge2reg($fs, $e, $fs->freereg - 1);  // put value there
        }
    }

    // lcode.c: code_loadbool
    private static function code_loadbool(FuncState $fs, int $a, int $op): int
    {
        self::luaK_getlabel($fs);  // those instructions may be jump targets
        return self::luaK_codeABC($fs, $op, $a, 0, 0);
    }

    // lcode.c: need_value
    private static function need_value(FuncState $fs, int $list): bool
    {
        for (; $list !== self::NO_JUMP; $list = self::getjump($fs, $list)) {
            $instruction = $fs->f->code[self::getjumpcontrol($fs, $list)];
            if (O::GET_OPCODE($instruction) !== O::OP_TESTSET) {
                return true;
            }
        }
        return false;  // not found
    }

    // lcode.c: exp2reg
    private static function exp2reg(FuncState $fs, E $e, int $reg): void
    {
        self::discharge2reg($fs, $e, $reg);
        if ($e->k === E::VJMP) {  // expression itself is a test?
            self::luaK_concat($fs, $e->t, $e->info);  // put this jump in 't' list
        }
        if (self::hasjumps($e)) {
            $loadFalsePosition = self::NO_JUMP;  // position of an eventual LOAD false
            $loadTruePosition = self::NO_JUMP;  // position of an eventual LOAD true
            if (self::need_value($fs, $e->t) || self::need_value($fs, $e->f)) {
                $fj = ($e->k === E::VJMP) ? self::NO_JUMP : self::luaK_jump($fs);
                $loadFalsePosition = self::code_loadbool($fs, $reg, O::OP_LFALSESKIP);  // skip next inst.
                $loadTruePosition = self::code_loadbool($fs, $reg, O::OP_LOADTRUE);
                // jump around these booleans if 'e' is not a test
                self::luaK_patchtohere($fs, $fj);
            }
            $final = self::luaK_getlabel($fs);  // position after whole expression
            self::patchlistaux($fs, $e->f, $final, $reg, $loadFalsePosition);
            self::patchlistaux($fs, $e->t, $final, $reg, $loadTruePosition);
        }
        $e->f = $e->t = self::NO_JUMP;
        $e->info = $reg;
        $e->k = E::VNONRELOC;
    }

    // lcode.c: luaK_exp2nextreg
    public static function luaK_exp2nextreg(FuncState $fs, E $e): void
    {
        self::luaK_dischargevars($fs, $e);
        self::freeexp($fs, $e);
        self::luaK_reserveregs($fs, 1);
        self::exp2reg($fs, $e, $fs->freereg - 1);
    }

    // lcode.c: luaK_exp2anyreg
    public static function luaK_exp2anyreg(FuncState $fs, E $e): int
    {
        self::luaK_dischargevars($fs, $e);
        if ($e->k === E::VNONRELOC) {  // expression already has a register?
            if (!self::hasjumps($e)) {  // no jumps?
                return $e->info;  // result is already in a register
            }
            if ($e->info >= Parser::luaY_nvarstack($fs)) {  // reg. is not a local?
                self::exp2reg($fs, $e, $e->info);  // put final result in it
                return $e->info;
            }
            // else expression has jumps and cannot change its register
            // to hold the jump values, because it is a local variable.
            // Go through to the default case.
        }
        self::luaK_exp2nextreg($fs, $e);  // default: use next available register
        return $e->info;
    }

    // lcode.c: luaK_exp2anyregup
    public static function luaK_exp2anyregup(FuncState $fs, E $e): void
    {
        if ($e->k !== E::VUPVAL || self::hasjumps($e)) {
            self::luaK_exp2anyreg($fs, $e);
        }
    }

    // lcode.c: luaK_exp2val
    public static function luaK_exp2val(FuncState $fs, E $e): void
    {
        if ($e->k === E::VJMP || self::hasjumps($e)) {
            self::luaK_exp2anyreg($fs, $e);
        } else {
            self::luaK_dischargevars($fs, $e);
        }
    }

    // lcode.c: luaK_exp2K
    private static function luaK_exp2K(FuncState $fs, E $e): bool
    {
        if (!self::hasjumps($e)) {
            switch ($e->k) {  // move constants to 'k'
                case E::VTRUE: $info = self::boolT($fs); break;
                case E::VFALSE: $info = self::boolF($fs); break;
                case E::VNIL: $info = self::nilK($fs); break;
                case E::VKINT: $info = self::luaK_intK($fs, $e->ival); break;
                case E::VKFLT: $info = self::luaK_numberK($fs, $e->nval); break;
                case E::VKSTR: $info = self::stringK($fs, $e->strval); break;
                case E::VK: $info = $e->info; break;
                default: return false;  // not a constant
            }
            if ($info <= O::MAXINDEXRK) {  // does constant fit in 'argC'?
                $e->k = E::VK;  // make expression a 'K' expression
                $e->info = $info;
                return true;
            }
        }
        // else, expression doesn't fit; leave it unchanged
        return false;
    }

    // lcode.c: exp2RK
    private static function exp2RK(FuncState $fs, E $e): bool
    {
        if (self::luaK_exp2K($fs, $e)) {
            return true;
        }
        // not a constant in the right range: put it in a register
        self::luaK_exp2anyreg($fs, $e);
        return false;
    }

    // lcode.c: codeABRK
    private static function codeABRK(FuncState $fs, int $op, int $a, int $b, E $ec): void
    {
        $k = self::exp2RK($fs, $ec);
        self::luaK_codeABCk($fs, $op, $a, $b, $ec->info, $k ? 1 : 0);
    }

    // lcode.c: luaK_storevar
    public static function luaK_storevar(FuncState $fs, E $var, E $ex): void
    {
        switch ($var->k) {
            case E::VLOCAL:
                self::freeexp($fs, $ex);
                self::exp2reg($fs, $ex, $var->varRidx);  // compute 'ex' into proper place
                return;
            case E::VUPVAL:
                $register = self::luaK_exp2anyreg($fs, $ex);
                self::luaK_codeABC($fs, O::OP_SETUPVAL, $register, $var->info, 0);
                break;
            case E::VINDEXUP:
                self::codeABRK($fs, O::OP_SETTABUP, $var->indT, $var->indIdx, $ex);
                break;
            case E::VINDEXI:
                self::codeABRK($fs, O::OP_SETI, $var->indT, $var->indIdx, $ex);
                break;
            case E::VINDEXSTR:
                self::codeABRK($fs, O::OP_SETFIELD, $var->indT, $var->indIdx, $ex);
                break;
            case E::VINDEXED:
                self::codeABRK($fs, O::OP_SETTABLE, $var->indT, $var->indIdx, $ex);
                break;
            default:
                throw new \LogicException('luaK_storevar: invalid var kind to store');
        }
        self::freeexp($fs, $ex);
    }

    // lcode.c: luaK_self
    public static function luaK_self(FuncState $fs, E $e, E $key): void
    {
        self::luaK_exp2anyreg($fs, $e);
        $ereg = $e->info;  // register where 'e' was placed
        self::freeexp($fs, $e);
        $e->info = $fs->freereg;  // base register for op_self
        $e->k = E::VNONRELOC;  // self expression has a fixed register
        self::luaK_reserveregs($fs, 2);  // function and 'self' produced by op_self
        self::codeABRK($fs, O::OP_SELF, $e->info, $ereg, $key);
        self::freeexp($fs, $key);
    }

    // lcode.c: negatecondition
    private static function negatecondition(FuncState $fs, E $e): void
    {
        $index = self::getjumpcontrol($fs, $e->info);
        $instruction = $fs->f->code[$index];
        $fs->f->code[$index] = O::SETARG_k($instruction, O::GETARG_k($instruction) ^ 1);
    }

    // lcode.c: jumponcond
    private static function jumponcond(FuncState $fs, E $e, int $cond): int
    {
        if ($e->k === E::VRELOC) {
            $instruction = $fs->f->code[$e->info];
            if (O::GET_OPCODE($instruction) === O::OP_NOT) {
                self::removelastinstruction($fs);  // remove previous OP_NOT
                return self::condjump($fs, O::OP_TEST, O::GETARG_B($instruction), 0, 0, $cond === 0 ? 1 : 0);
            }
            // else go through
        }
        self::discharge2anyreg($fs, $e);
        self::freeexp($fs, $e);
        return self::condjump($fs, O::OP_TESTSET, O::NO_REG, $e->info, 0, $cond);
    }

    // lcode.c: luaK_goiftrue
    public static function luaK_goiftrue(FuncState $fs, E $e): void
    {
        self::luaK_dischargevars($fs, $e);
        switch ($e->k) {
            case E::VJMP:  // condition?
                self::negatecondition($fs, $e);  // jump when it is false
                $pc = $e->info;  // save jump position
                break;
            case E::VK:
            case E::VKFLT:
            case E::VKINT:
            case E::VKSTR:
            case E::VTRUE:
                $pc = self::NO_JUMP;  // always true; do nothing
                break;
            default:
                $pc = self::jumponcond($fs, $e, 0);  // jump when false
                break;
        }
        self::luaK_concat($fs, $e->f, $pc);  // insert new jump in false list
        self::luaK_patchtohere($fs, $e->t);  // true list jumps to here (to go through)
        $e->t = self::NO_JUMP;
    }

    // lcode.c: luaK_goiffalse
    public static function luaK_goiffalse(FuncState $fs, E $e): void
    {
        self::luaK_dischargevars($fs, $e);
        switch ($e->k) {
            case E::VJMP:
                $pc = $e->info;  // already jump if true
                break;
            case E::VNIL:
            case E::VFALSE:
                $pc = self::NO_JUMP;  // always false; do nothing
                break;
            default:
                $pc = self::jumponcond($fs, $e, 1);  // jump if true
                break;
        }
        self::luaK_concat($fs, $e->t, $pc);  // insert new jump in 't' list
        self::luaK_patchtohere($fs, $e->f);  // false list jumps to here (to go through)
        $e->f = self::NO_JUMP;
    }

    // lcode.c: codenot
    private static function codenot(FuncState $fs, E $e): void
    {
        switch ($e->k) {
            case E::VNIL:
            case E::VFALSE:
                $e->k = E::VTRUE;  // true == not nil == not false
                break;
            case E::VK:
            case E::VKFLT:
            case E::VKINT:
            case E::VKSTR:
            case E::VTRUE:
                $e->k = E::VFALSE;  // false == not "x" == not 0.5 == not 1 == not true
                break;
            case E::VJMP:
                self::negatecondition($fs, $e);
                break;
            case E::VRELOC:
            case E::VNONRELOC:
                self::discharge2anyreg($fs, $e);
                self::freeexp($fs, $e);
                $e->info = self::luaK_codeABC($fs, O::OP_NOT, 0, $e->info, 0);
                $e->k = E::VRELOC;
                break;
            default:
                throw new \LogicException('codenot: cannot happen');
        }
        // interchange true and false lists
        $temporary = $e->f;
        $e->f = $e->t;
        $e->t = $temporary;
        self::removevalues($fs, $e->f);  // values are useless when negated
        self::removevalues($fs, $e->t);
    }

    // lcode.c: isKstr (a short literal string in 'k' that fits in argument B)
    private static function isKstr(FuncState $fs, E $e): bool
    {
        return $e->k === E::VK && !self::hasjumps($e) && $e->info <= O::MAXARG_B
            && is_string($fs->f->k[$e->info]) && strlen($fs->f->k[$e->info]) <= Undump::LUAI_MAXSHORTLEN;
    }

    // lcode.c: isKint
    private static function isKint(E $e): bool
    {
        return $e->k === E::VKINT && !self::hasjumps($e);
    }

    // lcode.c: isCint (literal integer that fits in register C, as unsigned)
    private static function isCint(E $e): bool
    {
        return self::isKint($e) && $e->ival >= 0 && $e->ival <= O::MAXARG_C;
    }

    // lcode.c: isSCint
    private static function isSCint(E $e): bool
    {
        return self::isKint($e) && self::fitsC($e->ival);
    }

    /**
     * lcode.c: isSCnumber. Like C, sets $isfloat for an integral float even
     * when the value then does not fit (callers pass that flag on).
     */
    private static function isSCnumber(E $e, int &$pi, int &$isfloat): bool
    {
        if ($e->k === E::VKINT) {
            $i = $e->ival;
        } elseif ($e->k === E::VKFLT && ($i = RawArith::luaV_flttointeger($e->nval)) !== null) {
            $isfloat = 1;
        } else {
            return false;  // not a number
        }
        if (!self::hasjumps($e) && self::fitsC($i)) {
            $pi = O::int2sC($i);
            return true;
        }
        return false;
    }

    // lcode.c: luaK_indexed
    public static function luaK_indexed(FuncState $fs, E $t, E $k): void
    {
        if ($k->k === E::VKSTR) {
            self::str2K($fs, $k);
        }
        if ($t->k === E::VUPVAL && !self::isKstr($fs, $k)) {  // upvalue indexed by non 'Kstr'?
            self::luaK_exp2anyreg($fs, $t);  // put it in a register
        }
        if ($t->k === E::VUPVAL) {
            $t->indT = $t->info;  // upvalue index
            $t->indIdx = $k->info;  // literal short string
            $t->k = E::VINDEXUP;
            return;
        }
        // register index of the table
        $t->indT = ($t->k === E::VLOCAL) ? $t->varRidx : $t->info;
        if (self::isKstr($fs, $k)) {
            $t->indIdx = $k->info;  // literal short string
            $t->k = E::VINDEXSTR;
        } elseif (self::isCint($k)) {
            $t->indIdx = $k->ival;  // int. constant in proper range
            $t->k = E::VINDEXI;
        } else {
            $t->indIdx = self::luaK_exp2anyreg($fs, $k);  // register
            $t->k = E::VINDEXED;
        }
    }

    // lcode.c: validop (false if folding can raise an error)
    private static function validop(int $op, int|float $v1, int|float $v2): bool
    {
        switch ($op) {
            case RawArith::LUA_OPBAND:
            case RawArith::LUA_OPBOR:
            case RawArith::LUA_OPBXOR:
            case RawArith::LUA_OPSHL:
            case RawArith::LUA_OPSHR:
            case RawArith::LUA_OPBNOT:  // conversion errors
                return (is_int($v1) || RawArith::luaV_flttointeger($v1) !== null)
                    && (is_int($v2) || RawArith::luaV_flttointeger($v2) !== null);
            case RawArith::LUA_OPDIV:
            case RawArith::LUA_OPIDIV:
            case RawArith::LUA_OPMOD:  // division by 0
                return (float) $v2 != 0.0;
            default:
                return true;  // everything else is valid
        }
    }

    // lcode.c: constfolding
    private static function constfolding(FuncState $fs, int $op, E $e1, E $e2): bool
    {
        $v1 = self::tonumeral($e1);
        $v2 = self::tonumeral($e2);
        if ($v1 === null || $v2 === null || !self::validop($op, $v1, $v2)) {
            return false;  // non-numeric operands or not safe to fold
        }
        $result = RawArith::luaO_rawarith($op, $v1, $v2);  // does operation
        if (is_int($result)) {
            $e1->k = E::VKINT;
            $e1->ival = $result;
            return true;
        }
        // folds neither NaN nor 0.0 (to avoid problems with -0.0)
        if (is_nan($result) || $result == 0.0) {
            return false;
        }
        $e1->k = E::VKFLT;
        $e1->nval = $result;
        return true;
    }

    // lcode.c: binopr2op
    private static function binopr2op(int $opr, int $baser, int $base): int
    {
        return ($opr - $baser) + $base;
    }

    // lcode.c: unopr2op
    private static function unopr2op(int $opr): int
    {
        return ($opr - self::OPR_MINUS) + O::OP_UNM;
    }

    // lcode.c: binopr2TM
    private static function binopr2TM(int $opr): int
    {
        return ($opr - self::OPR_ADD) + self::TM_ADD;
    }

    // lcode.c: codeunexpval
    private static function codeunexpval(FuncState $fs, int $op, E $e, int $line): void
    {
        $r = self::luaK_exp2anyreg($fs, $e);  // opcodes operate only on registers
        self::freeexp($fs, $e);
        $e->info = self::luaK_codeABC($fs, $op, 0, $r, 0);  // generate opcode
        $e->k = E::VRELOC;  // all those operations are relocatable
        self::luaK_fixline($fs, $line);
    }

    // lcode.c: finishbinexpval
    private static function finishbinexpval(FuncState $fs, E $e1, E $e2, int $op, int $v2, int $flip, int $line, int $mmop, int $event): void
    {
        $v1 = self::luaK_exp2anyreg($fs, $e1);
        $pc = self::luaK_codeABCk($fs, $op, 0, $v1, $v2, 0);
        self::freeexps($fs, $e1, $e2);
        $e1->info = $pc;
        $e1->k = E::VRELOC;  // all those operations are relocatable
        self::luaK_fixline($fs, $line);
        self::luaK_codeABCk($fs, $mmop, $v1, $v2, $event, $flip);  // to call metamethod
        self::luaK_fixline($fs, $line);
    }

    // lcode.c: codebinexpval
    private static function codebinexpval(FuncState $fs, int $opr, E $e1, E $e2, int $line): void
    {
        $op = self::binopr2op($opr, self::OPR_ADD, O::OP_ADD);
        $v2 = self::luaK_exp2anyreg($fs, $e2);  // make sure 'e2' is in a register
        self::finishbinexpval($fs, $e1, $e2, $op, $v2, 0, $line, O::OP_MMBIN, self::binopr2TM($opr));
    }

    // lcode.c: codebini
    private static function codebini(FuncState $fs, int $op, E $e1, E $e2, int $flip, int $line, int $event): void
    {
        $v2 = O::int2sC($e2->ival);  // immediate operand
        self::finishbinexpval($fs, $e1, $e2, $op, $v2, $flip, $line, O::OP_MMBINI, $event);
    }

    // lcode.c: codebinK
    private static function codebinK(FuncState $fs, int $opr, E $e1, E $e2, int $flip, int $line): void
    {
        $event = self::binopr2TM($opr);
        $v2 = $e2->info;  // K index
        $op = self::binopr2op($opr, self::OPR_ADD, O::OP_ADDK);
        self::finishbinexpval($fs, $e1, $e2, $op, $v2, $flip, $line, O::OP_MMBINK, $event);
    }

    // lcode.c: finishbinexpneg (code a binary operator negating its 2nd operand)
    private static function finishbinexpneg(FuncState $fs, E $e1, E $e2, int $op, int $line, int $event): bool
    {
        if (!self::isKint($e2)) {
            return false;  // not an integer constant
        }
        $i2 = $e2->ival;
        if (!(self::fitsC($i2) && self::fitsC(-$i2))) {
            return false;  // not in the proper range
        }
        // operating a small integer constant
        self::finishbinexpval($fs, $e1, $e2, $op, O::int2sC(-$i2), 0, $line, O::OP_MMBINI, $event);
        // correct metamethod argument
        $fs->f->code[$fs->pc - 1] = O::SETARG_B($fs->f->code[$fs->pc - 1], O::int2sC($i2));
        return true;  // successfully coded
    }

    // lcode.c: swapexps
    private static function swapexps(E $e1, E $e2): void
    {
        $temporary = new E();
        $temporary->copyFrom($e1);
        $e1->copyFrom($e2);
        $e2->copyFrom($temporary);
    }

    // lcode.c: codebinNoK
    private static function codebinNoK(FuncState $fs, int $opr, E $e1, E $e2, int $flip, int $line): void
    {
        if ($flip) {
            self::swapexps($e1, $e2);  // back to original order
        }
        self::codebinexpval($fs, $opr, $e1, $e2, $line);  // use standard operators
    }

    // lcode.c: codearith
    private static function codearith(FuncState $fs, int $opr, E $e1, E $e2, int $flip, int $line): void
    {
        if (self::tonumeral($e2) !== null && self::luaK_exp2K($fs, $e2)) {  // K operand?
            self::codebinK($fs, $opr, $e1, $e2, $flip, $line);
        } else {  // 'e2' is neither an immediate nor a K operand
            self::codebinNoK($fs, $opr, $e1, $e2, $flip, $line);
        }
    }

    // lcode.c: codecommutative
    private static function codecommutative(FuncState $fs, int $op, E $e1, E $e2, int $line): void
    {
        $flip = 0;
        if (self::tonumeral($e1) !== null) {  // is first operand a numeric constant?
            self::swapexps($e1, $e2);  // change order
            $flip = 1;
        }
        if ($op === self::OPR_ADD && self::isSCint($e2)) {  // immediate operand?
            self::codebini($fs, O::OP_ADDI, $e1, $e2, $flip, $line, self::TM_ADD);
        } else {
            self::codearith($fs, $op, $e1, $e2, $flip, $line);
        }
    }

    // lcode.c: codebitwise
    private static function codebitwise(FuncState $fs, int $opr, E $e1, E $e2, int $line): void
    {
        $flip = 0;
        if ($e1->k === E::VKINT) {
            self::swapexps($e1, $e2);  // 'e2' will be the constant operand
            $flip = 1;
        }
        if ($e2->k === E::VKINT && self::luaK_exp2K($fs, $e2)) {  // K operand?
            self::codebinK($fs, $opr, $e1, $e2, $flip, $line);
        } else {  // no constants
            self::codebinNoK($fs, $opr, $e1, $e2, $flip, $line);
        }
    }

    // lcode.c: codeorder
    private static function codeorder(FuncState $fs, int $opr, E $e1, E $e2): void
    {
        $im = 0;
        $isfloat = 0;
        if (self::isSCnumber($e2, $im, $isfloat)) {
            // use immediate operand
            $r1 = self::luaK_exp2anyreg($fs, $e1);
            $r2 = $im;
            $op = self::binopr2op($opr, self::OPR_LT, O::OP_LTI);
        } elseif (self::isSCnumber($e1, $im, $isfloat)) {
            // transform (A < B) to (B > A) and (A <= B) to (B >= A)
            $r1 = self::luaK_exp2anyreg($fs, $e2);
            $r2 = $im;
            $op = self::binopr2op($opr, self::OPR_LT, O::OP_GTI);
        } else {  // regular case, compare two registers
            $r1 = self::luaK_exp2anyreg($fs, $e1);
            $r2 = self::luaK_exp2anyreg($fs, $e2);
            $op = self::binopr2op($opr, self::OPR_LT, O::OP_LT);
        }
        self::freeexps($fs, $e1, $e2);
        $e1->info = self::condjump($fs, $op, $r1, $r2, $isfloat, 1);
        $e1->k = E::VJMP;
    }

    // lcode.c: codeeq ('e1' was already put as RK by 'luaK_infix')
    private static function codeeq(FuncState $fs, int $opr, E $e1, E $e2): void
    {
        $im = 0;
        $isfloat = 0;  // not needed here, but kept for symmetry
        if ($e1->k !== E::VNONRELOC) {
            self::swapexps($e1, $e2);
        }
        $r1 = self::luaK_exp2anyreg($fs, $e1);  // 1st expression must be in register
        if (self::isSCnumber($e2, $im, $isfloat)) {
            $op = O::OP_EQI;
            $r2 = $im;  // immediate operand
        } elseif (self::exp2RK($fs, $e2)) {  // 2nd expression is constant?
            $op = O::OP_EQK;
            $r2 = $e2->info;  // constant index
        } else {
            $op = O::OP_EQ;  // will compare two registers
            $r2 = self::luaK_exp2anyreg($fs, $e2);
        }
        self::freeexps($fs, $e1, $e2);
        $e1->info = self::condjump($fs, $op, $r1, $r2, $isfloat, $opr === self::OPR_EQ ? 1 : 0);
        $e1->k = E::VJMP;
    }

    // lcode.c: luaK_prefix
    public static function luaK_prefix(FuncState $fs, int $opr, E $e, int $line): void
    {
        self::luaK_dischargevars($fs, $e);
        switch ($opr) {
            case self::OPR_MINUS:
            case self::OPR_BNOT:  // use 'ef' (integer 0) as fake 2nd operand
                $fakeOperand = new E();
                $fakeOperand->k = E::VKINT;
                if (self::constfolding($fs, $opr + RawArith::LUA_OPUNM, $e, $fakeOperand)) {
                    break;
                }
                self::codeunexpval($fs, self::unopr2op($opr), $e, $line);
                break;
            case self::OPR_LEN:
                self::codeunexpval($fs, self::unopr2op($opr), $e, $line);
                break;
            case self::OPR_NOT:
                self::codenot($fs, $e);
                break;
        }
    }

    // lcode.c: luaK_infix (1st operand of a binary operation, before the 2nd is read)
    public static function luaK_infix(FuncState $fs, int $op, E $v): void
    {
        self::luaK_dischargevars($fs, $v);
        switch ($op) {
            case self::OPR_AND:
                self::luaK_goiftrue($fs, $v);  // go ahead only if 'v' is true
                break;
            case self::OPR_OR:
                self::luaK_goiffalse($fs, $v);  // go ahead only if 'v' is false
                break;
            case self::OPR_CONCAT:
                self::luaK_exp2nextreg($fs, $v);  // operand must be on the stack
                break;
            case self::OPR_ADD:
            case self::OPR_SUB:
            case self::OPR_MUL:
            case self::OPR_DIV:
            case self::OPR_IDIV:
            case self::OPR_MOD:
            case self::OPR_POW:
            case self::OPR_BAND:
            case self::OPR_BOR:
            case self::OPR_BXOR:
            case self::OPR_SHL:
            case self::OPR_SHR:
                if (self::tonumeral($v) === null) {
                    self::luaK_exp2anyreg($fs, $v);
                }
                // else keep numeral, which may be folded or used as an immediate operand
                break;
            case self::OPR_EQ:
            case self::OPR_NE:
                if (self::tonumeral($v) === null) {
                    self::exp2RK($fs, $v);
                }
                // else keep numeral, which may be an immediate operand
                break;
            case self::OPR_LT:
            case self::OPR_LE:
            case self::OPR_GT:
            case self::OPR_GE:
                $dummy = 0;
                $dummy2 = 0;
                if (!self::isSCnumber($v, $dummy, $dummy2)) {
                    self::luaK_exp2anyreg($fs, $v);
                }
                // else keep numeral, which may be an immediate operand
                break;
        }
    }

    /**
     * lcode.c: codeconcat. For '(e1 .. e2.1 .. e2.2)' (which is
     * '(e1 .. (e2.1 .. e2.2))', because concatenation is right associative),
     * merge both CONCATs.
     */
    private static function codeconcat(FuncState $fs, E $e1, E $e2, int $line): void
    {
        $previousIndex = self::previousinstruction($fs);
        $previous = self::instructionAt($fs, $previousIndex);
        if (O::GET_OPCODE($previous) === O::OP_CONCAT) {  // is 'e2' a concatenation?
            $n = O::GETARG_B($previous);  // # of elements concatenated in 'e2'
            self::freeexp($fs, $e2);
            $previous = O::SETARG_A($previous, $e1->info);  // correct first element ('e1')
            $fs->f->code[$previousIndex] = O::SETARG_B($previous, $n + 1);  // will concatenate one more element
            return;
        }
        // 'e2' is not a concatenation
        self::luaK_codeABC($fs, O::OP_CONCAT, $e1->info, 2, 0);  // new concat opcode
        self::freeexp($fs, $e2);
        self::luaK_fixline($fs, $line);
    }

    // lcode.c: luaK_posfix (finalize code for binary operation, after reading 2nd operand)
    public static function luaK_posfix(FuncState $fs, int $opr, E $e1, E $e2, int $line): void
    {
        self::luaK_dischargevars($fs, $e2);
        if ($opr <= self::OPR_SHR && self::constfolding($fs, $opr + RawArith::LUA_OPADD, $e1, $e2)) {  // foldbinop
            return;  // done by folding
        }
        switch ($opr) {
            case self::OPR_AND:
                self::luaK_concat($fs, $e2->f, $e1->f);
                $e1->copyFrom($e2);
                break;
            case self::OPR_OR:
                self::luaK_concat($fs, $e2->t, $e1->t);
                $e1->copyFrom($e2);
                break;
            case self::OPR_CONCAT:  // e1 .. e2
                self::luaK_exp2nextreg($fs, $e2);
                self::codeconcat($fs, $e1, $e2, $line);
                break;
            case self::OPR_ADD:
            case self::OPR_MUL:
                self::codecommutative($fs, $opr, $e1, $e2, $line);
                break;
            case self::OPR_SUB:
                if (self::finishbinexpneg($fs, $e1, $e2, O::OP_ADDI, $line, self::TM_SUB)) {
                    break;  // coded as (r1 + -I)
                }
                self::codearith($fs, $opr, $e1, $e2, 0, $line);
                break;
            case self::OPR_DIV:
            case self::OPR_IDIV:
            case self::OPR_MOD:
            case self::OPR_POW:
                self::codearith($fs, $opr, $e1, $e2, 0, $line);
                break;
            case self::OPR_BAND:
            case self::OPR_BOR:
            case self::OPR_BXOR:
                self::codebitwise($fs, $opr, $e1, $e2, $line);
                break;
            case self::OPR_SHL:
                if (self::isSCint($e1)) {
                    self::swapexps($e1, $e2);
                    self::codebini($fs, O::OP_SHLI, $e1, $e2, 1, $line, self::TM_SHL);  // I << r2
                } elseif (self::finishbinexpneg($fs, $e1, $e2, O::OP_SHRI, $line, self::TM_SHL)) {
                    // coded as (r1 >> -I)
                } else {  // regular case (two registers)
                    self::codebinexpval($fs, $opr, $e1, $e2, $line);
                }
                break;
            case self::OPR_SHR:
                if (self::isSCint($e2)) {
                    self::codebini($fs, O::OP_SHRI, $e1, $e2, 0, $line, self::TM_SHR);  // r1 >> I
                } else {  // regular case (two registers)
                    self::codebinexpval($fs, $opr, $e1, $e2, $line);
                }
                break;
            case self::OPR_EQ:
            case self::OPR_NE:
                self::codeeq($fs, $opr, $e1, $e2);
                break;
            case self::OPR_GT:
            case self::OPR_GE:
                // '(a > b)' <=> '(b < a)';  '(a >= b)' <=> '(b <= a)'
                self::swapexps($e1, $e2);
                self::codeorder($fs, ($opr - self::OPR_GT) + self::OPR_LT, $e1, $e2);
                break;
            case self::OPR_LT:
            case self::OPR_LE:
                self::codeorder($fs, $opr, $e1, $e2);
                break;
        }
    }

    // lcode.c: luaK_fixline
    public static function luaK_fixline(FuncState $fs, int $line): void
    {
        self::removelastlineinfo($fs);
        self::savelineinfo($fs, $fs->f, $line);
    }

    // lcode.c: luaK_settablesize
    public static function luaK_settablesize(FuncState $fs, int $pc, int $ra, int $asize, int $hsize): void
    {
        $rb = ($hsize !== 0) ? self::luaO_ceillog2($hsize) + 1 : 0;  // hash size
        $extra = intdiv($asize, O::MAXARG_C + 1);  // higher bits of array size
        $rc = $asize % (O::MAXARG_C + 1);  // lower bits of array size
        $k = ($extra > 0) ? 1 : 0;  // true iff needs extra argument
        $fs->f->code[$pc] = O::CREATE_ABCk(O::OP_NEWTABLE, $ra, $rb, $rc, $k);
        $fs->f->code[$pc + 1] = O::CREATE_Ax(O::OP_EXTRAARG, $extra);
    }

    // lobject.c: luaO_ceillog2 (ceil(log2(x)))
    private static function luaO_ceillog2(int $x): int
    {
        $log = 0;
        $x--;
        while ($x > 0) {
            $log++;
            $x >>= 1;
        }
        return $log;
    }

    /**
     * lcode.c: luaK_setlist. 'base' is register that keeps table; 'nelems'
     * is #table plus those to be stored now; 'tostore' is number of values
     * (in registers 'base + 1',...) to add to table (or LUA_MULTRET to add
     * up to stack top).
     */
    public static function luaK_setlist(FuncState $fs, int $base, int $nelems, int $tostore): void
    {
        if ($tostore === self::LUA_MULTRET) {
            $tostore = 0;
        }
        if ($nelems <= O::MAXARG_C) {
            self::luaK_codeABC($fs, O::OP_SETLIST, $base, $tostore, $nelems);
        } else {
            $extra = intdiv($nelems, O::MAXARG_C + 1);
            $nelems %= (O::MAXARG_C + 1);
            self::luaK_codeABCk($fs, O::OP_SETLIST, $base, $tostore, $nelems, 1);
            self::codeextraarg($fs, $extra);
        }
        $fs->freereg = $base + 1;  // free registers with list values
    }

    // lcode.c: finaltarget (the final target of a jump, skipping jumps to jumps)
    private static function finaltarget(array $code, int $i): int
    {
        for ($count = 0; $count < 100; $count++) {  // avoid infinite loops
            $instruction = $code[$i];
            if (O::GET_OPCODE($instruction) !== O::OP_JMP) {
                break;
            }
            $i += O::GETARG_sJ($instruction) + 1;
        }
        return $i;
    }

    // lcode.c: luaK_finish (final pass: small peephole optimizations and adjustments)
    public static function luaK_finish(FuncState $fs): void
    {
        $p = $fs->f;
        for ($i = 0; $i < $fs->pc; $i++) {
            $instruction = $p->code[$i];
            switch (O::GET_OPCODE($instruction)) {
                case O::OP_RETURN0:
                case O::OP_RETURN1:
                    if (!($fs->needclose || $p->is_vararg)) {
                        break;  // no extra work
                    }
                    // else use OP_RETURN to do the extra work
                    $instruction = O::SET_OPCODE($instruction, O::OP_RETURN);
                    // FALLTHROUGH
                case O::OP_RETURN:
                case O::OP_TAILCALL:
                    if ($fs->needclose) {
                        $instruction = O::SETARG_k($instruction, 1);  // signal that it needs to close
                    }
                    if ($p->is_vararg) {
                        $instruction = O::SETARG_C($instruction, $p->numparams + 1);  // signal that it is vararg
                    }
                    $p->code[$i] = $instruction;
                    break;
                case O::OP_JMP:
                    $target = self::finaltarget($p->code, $i);
                    self::fixjump($fs, $i, $target);
                    break;
                default:
                    break;
            }
        }
    }
}
