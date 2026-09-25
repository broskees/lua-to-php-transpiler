<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

use LuaPhp\Compiler\CodeGen as K;
use LuaPhp\Compiler\ExpDesc as E;
use LuaPhp\Compiler\Lexer as X;
use LuaPhp\Compiler\OpCodes as O;

/**
 * Port of lparser.c (Lua 5.4.9): the recursive-descent parser. Every
 * function keeps its C name. C's 'ls' is $this->ls; C pointer arguments
 * that the callee assigns are PHP references.
 */
final class Parser
{
    // lparser.c: maximum number of local variables per function
    private const MAXVARS = 200;

    // lfunc.h: MAXUPVAL
    private const MAXUPVAL = 255;

    // llimits.h: LUAI_MAXCCALLS
    private const LUAI_MAXCCALLS = 200;

    // lua.h: status of an error while running the message handler
    private const LUA_ERRERR = 5;

    // limits.h / llimits.h
    private const SHRT_MAX = 32767;
    private const MAX_INT = 2147483647;
    private const INT_MAX = 2147483647;

    // lparser.c: priority for unary operators
    private const UNARY_PRIORITY = 12;

    // lparser.c: priority table for binary operators, {left, right} ("ORDER OPR")
    private const PRIORITY = [
        [10, 10], [10, 10],          // '+' '-'
        [11, 11], [11, 11],          // '*' '%'
        [14, 13],                    // '^' (right associative)
        [11, 11], [11, 11],          // '/' '//'
        [6, 6], [4, 4], [5, 5],      // '&' '|' '~'
        [7, 7], [7, 7],              // '<<' '>>'
        [9, 8],                      // '..' (right associative)
        [3, 3], [3, 3], [3, 3],      // ==, <, <=
        [3, 3], [3, 3], [3, 3],      // ~=, >, >=
        [2, 2], [1, 1],              // and, or
    ];

    private function __construct(
        private Lexer $ls,
    ) {
    }

    /**
     * lparser.c: luaY_parser. Compiles the main function, which is a
     * regular vararg function with an upvalue named LUA_ENV.
     *
     * @throws CompileError
     */
    public static function luaY_parser(string $input, string $chunkname, int $nCcallsAtEntry, ?int &$nesting = null): Proto
    {
        $chunkname = Lexer::asCString($chunkname);  // C receives the name as a 'const char *'
        $mainProto = new Proto();
        $mainProto->source = $chunkname;
        $lexState = new Lexer($input, $chunkname, $nCcallsAtEntry);
        $parser = new self($lexState);
        try {
            $parser->mainfunc(new FuncState($mainProto, $lexState));
        } finally {
            $nesting = $lexState->deepestNCcalls - $nCcallsAtEntry;
        }
        return $mainProto;
    }

    // lparser.c: error_expected
    private function error_expected(int $token): never
    {
        $this->ls->luaX_syntaxerror($this->ls->luaX_token2str($token) . ' expected');
    }

    // lparser.c: errorlimit
    private function errorlimit(FuncState $fs, int $limit, string $what): never
    {
        $line = $fs->f->linedefined;
        $where = ($line === 0) ? 'main function' : "function at line $line";
        $this->ls->luaX_syntaxerror("too many $what (limit is $limit) in $where");
    }

    // lparser.c: checklimit
    private function checklimit(FuncState $fs, int $v, int $l, string $what): void
    {
        if ($v > $l) {
            $this->errorlimit($fs, $l, $what);
        }
    }

    // lparser.c: testnext (test whether next token is 'c'; if so, skip it)
    private function testnext(int $c): bool
    {
        if ($this->ls->t->token === $c) {
            $this->ls->luaX_next();
            return true;
        }
        return false;
    }

    // lparser.c: check
    private function check(int $c): void
    {
        if ($this->ls->t->token !== $c) {
            $this->error_expected($c);
        }
    }

    // lparser.c: checknext
    private function checknext(int $c): void
    {
        $this->check($c);
        $this->ls->luaX_next();
    }

    // lparser.c: check_condition
    private function check_condition(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->ls->luaX_syntaxerror($message);
        }
    }

    // lparser.c: check_match
    private function check_match(int $what, int $who, int $where): void
    {
        if (!$this->testnext($what)) {
            if ($where === $this->ls->linenumber) {  // all in the same line?
                $this->error_expected($what);  // do not need a complex message
            }
            $this->ls->luaX_syntaxerror($this->ls->luaX_token2str($what) . ' expected (to close '
                . $this->ls->luaX_token2str($who) . " at line $where)");
        }
    }

    // lparser.c: str_checkname
    private function str_checkname(): string
    {
        $this->check(X::TK_NAME);
        $name = $this->ls->t->seminfo;
        $this->ls->luaX_next();
        return $name;
    }

    // lparser.c: init_exp
    private static function init_exp(E $e, int $k, int $info): void
    {
        $e->f = $e->t = K::NO_JUMP;
        $e->k = $k;
        $e->info = $info;
    }

    // lparser.c: codestring
    private static function codestring(E $e, string $s): void
    {
        $e->f = $e->t = K::NO_JUMP;
        $e->k = E::VKSTR;
        $e->strval = $s;
    }

    // lparser.c: codename
    private function codename(E $e): void
    {
        self::codestring($e, $this->str_checkname());
    }

    // lparser.c: registerlocalvar (debug information)
    private function registerlocalvar(FuncState $fs, string $varname): int
    {
        K::checkVectorLimit($fs->ndebugvars, self::SHRT_MAX, 'local variables');
        $fs->f->locvars[$fs->ndebugvars] = new LocVar($varname, $fs->pc, 0);
        return $fs->ndebugvars++;
    }

    // lparser.c: new_localvar (returns its index in the function)
    private function new_localvar(string $name): int
    {
        $fs = $this->ls->fs;
        $dyd = $this->ls->dyd;
        $this->checklimit($fs, $dyd->actvarCount + 1 - $fs->firstlocal, self::MAXVARS, 'local variables');
        K::checkVectorLimit($dyd->actvarCount + 1, self::SHRT_MAX, 'local variables');
        $var = $dyd->actvar[$dyd->actvarCount] ?? null;
        if ($var === null) {
            $dyd->actvar[$dyd->actvarCount] = new Vardesc($name);
        } else {  // C reuses the slot: only 'kind' and 'name' are reset
            $var->kind = Vardesc::VDKREG;  // default
            $var->name = $name;
        }
        $dyd->actvarCount++;
        return $dyd->actvarCount - 1 - $fs->firstlocal;
    }

    // lparser.c: getlocalvardesc
    public static function getlocalvardesc(FuncState $fs, int $vidx): Vardesc
    {
        return $fs->ls->dyd->actvar[$fs->firstlocal + $vidx];
    }

    /**
     * lparser.c: reglevel. Convert 'nvar', a compiler index level, to its
     * corresponding register: the register of the highest variable below
     * that level that is in a register, plus one.
     */
    private static function reglevel(FuncState $fs, int $nvar): int
    {
        while ($nvar-- > 0) {
            $vd = self::getlocalvardesc($fs, $nvar);  // get previous variable
            if ($vd->kind !== Vardesc::RDKCTC) {  // is in a register?
                return $vd->ridx + 1;
            }
        }
        return 0;  // no variables in registers
    }

    // lparser.c: luaY_nvarstack (number of variables in the register stack)
    public static function luaY_nvarstack(FuncState $fs): int
    {
        return self::reglevel($fs, $fs->nactvar);
    }

    // lparser.c: localdebuginfo
    private static function localdebuginfo(FuncState $fs, int $vidx): ?LocVar
    {
        $vd = self::getlocalvardesc($fs, $vidx);
        if ($vd->kind === Vardesc::RDKCTC) {
            return null;  // no debug info. for constants
        }
        return $fs->f->locvars[$vd->pidx];
    }

    // lparser.c: init_var
    private static function init_var(FuncState $fs, E $e, int $vidx): void
    {
        $e->f = $e->t = K::NO_JUMP;
        $e->k = E::VLOCAL;
        $e->varVidx = $vidx;
        $e->varRidx = self::getlocalvardesc($fs, $vidx)->ridx;
    }

    // lparser.c: check_readonly
    private function check_readonly(E $e): void
    {
        $fs = $this->ls->fs;
        $varname = null;  // to be set if variable is const
        switch ($e->k) {
            case E::VCONST:
                $varname = $this->ls->dyd->actvar[$e->info]->name;
                break;
            case E::VLOCAL:
                $vardesc = self::getlocalvardesc($fs, $e->varVidx);
                if ($vardesc->kind !== Vardesc::VDKREG) {  // not a regular variable?
                    $varname = $vardesc->name;
                }
                break;
            case E::VUPVAL:
                $upvalue = $fs->f->upvalues[$e->info];
                if ($upvalue->kind !== Vardesc::VDKREG) {
                    $varname = $upvalue->name;
                }
                break;
            default:
                return;  // other cases cannot be read-only
        }
        if ($varname !== null) {
            K::luaK_semerror($this->ls, "attempt to assign to const variable '$varname'");
        }
    }

    // lparser.c: adjustlocalvars (start the scope for the last 'nvars' created variables)
    private function adjustlocalvars(int $nvars): void
    {
        $fs = $this->ls->fs;
        $reglevel = self::luaY_nvarstack($fs);
        for ($i = 0; $i < $nvars; $i++) {
            $vidx = $fs->nactvar++;
            $var = self::getlocalvardesc($fs, $vidx);
            $var->ridx = $reglevel++;
            $var->pidx = $this->registerlocalvar($fs, $var->name);
        }
    }

    // lparser.c: removevars (close the scope for all variables up to level 'tolevel')
    private static function removevars(FuncState $fs, int $tolevel): void
    {
        $fs->ls->dyd->actvarCount -= ($fs->nactvar - $tolevel);
        while ($fs->nactvar > $tolevel) {
            $var = self::localdebuginfo($fs, --$fs->nactvar);
            if ($var !== null) {  // does it have debug information?
                $var->endpc = $fs->pc;
            }
        }
    }

    // lparser.c: searchupvalue
    private static function searchupvalue(FuncState $fs, string $name): int
    {
        for ($i = 0; $i < $fs->nups; $i++) {
            if ($fs->f->upvalues[$i]->name === $name) {
                return $i;
            }
        }
        return -1;  // not found
    }

    // lparser.c: allocupvalue
    private function allocupvalue(FuncState $fs): UpvalDesc
    {
        $this->checklimit($fs, $fs->nups + 1, self::MAXUPVAL, 'upvalues');
        $upvalue = new UpvalDesc(null, false, 0, Vardesc::VDKREG);
        $fs->f->upvalues[$fs->nups++] = $upvalue;
        return $upvalue;
    }

    // lparser.c: newupvalue
    private function newupvalue(FuncState $fs, string $name, E $v): int
    {
        $upvalue = $this->allocupvalue($fs);
        $prev = $fs->prev;
        if ($v->k === E::VLOCAL) {
            $upvalue->instack = true;
            $upvalue->idx = $v->varRidx;
            $upvalue->kind = self::getlocalvardesc($prev, $v->varVidx)->kind;
        } else {
            $upvalue->instack = false;
            $upvalue->idx = $v->info;
            $upvalue->kind = $prev->f->upvalues[$v->info]->kind;
        }
        $upvalue->name = $name;
        return $fs->nups - 1;
    }

    /**
     * lparser.c: searchvar. Look for an active local variable named 'n';
     * if found, initialize 'var' with it and return its expression kind,
     * otherwise -1.
     */
    private static function searchvar(FuncState $fs, string $n, E $var): int
    {
        for ($i = $fs->nactvar - 1; $i >= 0; $i--) {
            $vd = self::getlocalvardesc($fs, $i);
            if ($n === $vd->name) {  // found?
                if ($vd->kind === Vardesc::RDKCTC) {  // compile-time constant?
                    self::init_exp($var, E::VCONST, $fs->firstlocal + $i);
                } else {  // real variable
                    self::init_var($fs, $var, $i);
                }
                return $var->k;
            }
        }
        return -1;  // not found
    }

    // lparser.c: markupval (mark block where variable at given level was defined)
    private static function markupval(FuncState $fs, int $level): void
    {
        $bl = $fs->bl;
        while ($bl->nactvar > $level) {
            $bl = $bl->previous;
        }
        $bl->upval = true;
        $fs->needclose = true;
    }

    // lparser.c: marktobeclosed (current block has a to-be-closed variable)
    private static function marktobeclosed(FuncState $fs): void
    {
        $bl = $fs->bl;
        $bl->upval = true;
        $bl->insidetbc = true;
        $fs->needclose = true;
    }

    /**
     * lparser.c: singlevaraux. Find a variable named 'n'; if it is an
     * upvalue, add it into all intermediate functions; if it is a global,
     * set 'var' as VVOID.
     */
    private function singlevaraux(?FuncState $fs, string $n, E $var, bool $base): void
    {
        if ($fs === null) {  // no more levels?
            self::init_exp($var, E::VVOID, 0);  // default is global
            return;
        }
        $kind = self::searchvar($fs, $n, $var);  // look up locals at current level
        if ($kind >= 0) {  // found?
            if ($kind === E::VLOCAL && !$base) {
                self::markupval($fs, $var->varVidx);  // local will be used as an upval
            }
            return;
        }
        // not found as local at current level; try upvalues
        $idx = self::searchupvalue($fs, $n);  // try existing upvalues
        if ($idx < 0) {  // not found?
            $this->singlevaraux($fs->prev, $n, $var, false);  // try upper levels
            if ($var->k !== E::VLOCAL && $var->k !== E::VUPVAL) {
                return;  // it is a global or a constant: nothing to do at this level
            }
            $idx = $this->newupvalue($fs, $n, $var);  // will be a new upvalue
        }
        self::init_exp($var, E::VUPVAL, $idx);  // new or old upvalue
    }

    // lparser.c: singlevar (find a variable, handling global variables too)
    private function singlevar(E $var): void
    {
        $varname = $this->str_checkname();
        $fs = $this->ls->fs;
        $this->singlevaraux($fs, $varname, $var, true);
        if ($var->k === E::VVOID) {  // global name?
            $key = new E();
            $this->singlevaraux($fs, $this->ls->envn, $var, true);  // get environment variable
            K::luaK_exp2anyregup($fs, $var);  // but could be a constant
            self::codestring($key, $varname);  // key is variable name
            K::luaK_indexed($fs, $var, $key);  // env[varname]
        }
    }

    // lparser.c: hasmultret
    private static function hasmultret(int $k): bool
    {
        return $k === E::VCALL || $k === E::VVARARG;
    }

    // lparser.c: adjust_assign (adjust 'nexps' expressions, the last one 'e', to 'nvars' values)
    private function adjust_assign(int $nvars, int $nexps, E $e): void
    {
        $fs = $this->ls->fs;
        $needed = $nvars - $nexps;  // extra values needed
        if (self::hasmultret($e->k)) {  // last expression has multiple returns?
            $extra = $needed + 1;  // discount last expression itself
            if ($extra < 0) {
                $extra = 0;
            }
            K::luaK_setreturns($fs, $e, $extra);  // last exp. provides the difference
        } else {
            if ($e->k !== E::VVOID) {  // at least one expression?
                K::luaK_exp2nextreg($fs, $e);  // close last expression
            }
            if ($needed > 0) {  // missing values?
                K::luaK_nil($fs, $fs->freereg, $needed);  // complete with nils
            }
        }
        if ($needed > 0) {
            K::luaK_reserveregs($fs, $needed);  // registers for extra values
        } else {  // adding 'needed' is actually a subtraction
            $fs->freereg += $needed;  // remove extra values
        }
    }

    /**
     * lparser.c: enterlevel (lstate.c luaE_incCstack and luaE_checkcstack).
     * The C stack limit is a runtime error raised inside the parser: no
     * position is added, and lua.c's message handler would see it. Depths
     * just past the limit are room for handling that error.
     */
    private function enterlevel(): void
    {
        $this->ls->nCcalls++;
        if ($this->ls->nCcalls > $this->ls->deepestNCcalls) {
            $this->ls->deepestNCcalls = $this->ls->nCcalls;
        }
        if ($this->ls->nCcalls < self::LUAI_MAXCCALLS) {
            return;
        }
        if ($this->ls->nCcalls === self::LUAI_MAXCCALLS) {
            throw new CompileError('C stack overflow', K::LUA_ERRRUN);
        }
        if ($this->ls->nCcalls >= intdiv(self::LUAI_MAXCCALLS, 10) * 11) {
            throw new CompileError('error in error handling', self::LUA_ERRERR);  // ldo.c: luaD_errerr
        }
    }

    // lparser.c: leavelevel
    private function leavelevel(): void
    {
        $this->ls->nCcalls--;
    }

    // lparser.c: jumpscopeerror
    private function jumpscopeerror(Labeldesc $gt): never
    {
        $varname = self::getlocalvardesc($this->ls->fs, $gt->nactvar)->name;
        K::luaK_semerror($this->ls, "<goto {$gt->name}> at line {$gt->line} jumps into the scope of local '$varname'");
    }

    // lparser.c: solvegoto (solve pending goto 'g' to 'label' and remove it)
    private function solvegoto(int $g, Labeldesc $label): void
    {
        $gotoList = &$this->ls->dyd->gt;
        $gt = $gotoList[$g];  // goto to be resolved
        if ($gt->nactvar < $label->nactvar) {  // enter some scope?
            $this->jumpscopeerror($gt);
        }
        K::luaK_patchlist($this->ls->fs, $gt->pc, $label->pc);
        array_splice($gotoList, $g, 1);  // remove goto from pending list
    }

    // lparser.c: findlabel (search for an active label with the given name)
    private function findlabel(string $name): ?Labeldesc
    {
        $labels = $this->ls->dyd->label;
        $labelCount = count($labels);
        // check labels in current function for a match
        for ($i = $this->ls->fs->firstlabel; $i < $labelCount; $i++) {
            if ($labels[$i]->name === $name) {  // correct label?
                return $labels[$i];
            }
        }
        return null;  // label not found
    }

    /**
     * lparser.c: newlabelentry (adds a new label/goto in the corresponding list)
     *
     * @param list<Labeldesc> $list
     */
    private function newlabelentry(array &$list, string $name, int $line, int $pc): int
    {
        $n = count($list);
        K::checkVectorLimit($n, self::SHRT_MAX, 'labels/gotos');
        $list[] = new Labeldesc($name, $pc, $line, $this->ls->fs->nactvar);
        return $n;
    }

    // lparser.c: newgotoentry
    private function newgotoentry(string $name, int $line, int $pc): int
    {
        return $this->newlabelentry($this->ls->dyd->gt, $name, $line, $pc);
    }

    /**
     * lparser.c: solvegotos. Solves pending gotos of the current block that
     * match new label 'lb'; true if any of them needs to close upvalues.
     */
    private function solvegotos(Labeldesc $lb): bool
    {
        $i = $this->ls->fs->bl->firstgoto;
        $needsclose = false;
        while ($i < count($this->ls->dyd->gt)) {
            $gt = $this->ls->dyd->gt[$i];
            if ($gt->name === $lb->name) {
                $needsclose = $needsclose || $gt->close;
                $this->solvegoto($i, $lb);  // will remove 'i' from the list
            } else {
                $i++;
            }
        }
        return $needsclose;
    }

    /**
     * lparser.c: createlabel. 'last' tells whether the label is the last
     * non-op statement in its block. Returns true iff it added a close.
     */
    private function createlabel(string $name, int $line, bool $last): bool
    {
        $fs = $this->ls->fs;
        $l = $this->newlabelentry($this->ls->dyd->label, $name, $line, K::luaK_getlabel($fs));
        $label = $this->ls->dyd->label[$l];
        if ($last) {  // label is last no-op statement in the block?
            // assume that locals are already out of scope
            $label->nactvar = $fs->bl->nactvar;
        }
        if ($this->solvegotos($label)) {  // need close?
            K::luaK_codeABC($fs, O::OP_CLOSE, self::luaY_nvarstack($fs), 0, 0);
            return true;
        }
        return false;
    }

    // lparser.c: movegotosout (adjust pending gotos to outer level of a block)
    private static function movegotosout(FuncState $fs, BlockCnt $bl): void
    {
        $gotoList = $fs->ls->dyd->gt;
        $gotoCount = count($gotoList);
        // correct pending gotos to current block
        for ($i = $bl->firstgoto; $i < $gotoCount; $i++) {  // for each pending goto
            $gt = $gotoList[$i];
            // leaving a variable scope?
            if (self::reglevel($fs, $gt->nactvar) > self::reglevel($fs, $bl->nactvar)) {
                $gt->close = $gt->close || $bl->upval;  // jump may need a close
            }
            $gt->nactvar = $bl->nactvar;  // update goto level
        }
    }

    // lparser.c: enterblock
    private static function enterblock(FuncState $fs, BlockCnt $bl, bool $isloop): void
    {
        $bl->isloop = $isloop;
        $bl->nactvar = $fs->nactvar;
        $bl->firstlabel = count($fs->ls->dyd->label);
        $bl->firstgoto = count($fs->ls->dyd->gt);
        $bl->upval = false;
        $bl->insidetbc = ($fs->bl !== null && $fs->bl->insidetbc);
        $bl->previous = $fs->bl;
        $fs->bl = $bl;
    }

    // lparser.c: undefgoto (error for an undefined 'goto')
    private function undefgoto(Labeldesc $gt): never
    {
        if ($gt->name === 'break') {
            $message = "break outside loop at line {$gt->line}";
        } else {
            $message = "no visible label '{$gt->name}' for <goto> at line {$gt->line}";
        }
        K::luaK_semerror($this->ls, $message);
    }

    // lparser.c: leaveblock
    private function leaveblock(FuncState $fs): void
    {
        $bl = $fs->bl;
        $ls = $this->ls;
        $hasclose = false;
        $stklevel = self::reglevel($fs, $bl->nactvar);  // level outside the block
        self::removevars($fs, $bl->nactvar);  // remove block locals
        if ($bl->isloop) {  // has to fix pending breaks?
            $hasclose = $this->createlabel('break', 0, false);
        }
        if (!$hasclose && $bl->previous !== null && $bl->upval) {  // still need a 'close'?
            K::luaK_codeABC($fs, O::OP_CLOSE, $stklevel, 0, 0);
        }
        $fs->freereg = $stklevel;  // free registers
        array_splice($ls->dyd->label, $bl->firstlabel);  // remove local labels
        $fs->bl = $bl->previous;  // current block now is previous one
        if ($bl->previous !== null) {  // was it a nested block?
            self::movegotosout($fs, $bl);  // update pending gotos to enclosing block
        } elseif ($bl->firstgoto < count($ls->dyd->gt)) {  // still pending gotos?
            $this->undefgoto($ls->dyd->gt[$bl->firstgoto]);  // error
        }
    }

    // lparser.c: addprototype (adds a new prototype into list of prototypes)
    private function addprototype(): Proto
    {
        $fs = $this->ls->fs;
        K::checkVectorLimit($fs->np, O::MAXARG_Bx, 'functions');
        $clp = new Proto();
        $fs->f->p[$fs->np++] = $clp;
        return $clp;
    }

    /**
     * lparser.c: codeclosure. The OP_CLOSURE instruction uses the last
     * available register.
     */
    private function codeclosure(E $v): void
    {
        $fs = $this->ls->fs->prev;
        self::init_exp($v, E::VRELOC, K::luaK_codeABx($fs, O::OP_CLOSURE, 0, $fs->np - 1));
        K::luaK_exp2nextreg($fs, $v);  // fix it at the last register
    }

    // lparser.c: open_func
    private function open_func(FuncState $fs, BlockCnt $bl): void
    {
        $f = $fs->f;
        $fs->prev = $this->ls->fs;  // linked list of funcstates
        $this->ls->fs = $fs;
        $fs->pc = 0;
        $fs->previousline = $f->linedefined;
        $fs->iwthabs = 0;
        $fs->lasttarget = 0;
        $fs->freereg = 0;
        $fs->nk = 0;
        $fs->nabslineinfo = 0;
        $fs->np = 0;
        $fs->nups = 0;
        $fs->ndebugvars = 0;
        $fs->nactvar = 0;
        $fs->needclose = false;
        $fs->firstlocal = $this->ls->dyd->actvarCount;
        $fs->firstlabel = count($this->ls->dyd->label);
        $fs->bl = null;
        $f->source = $this->ls->source;
        $f->maxstacksize = 2;  // registers 0/1 are always valid
        self::enterblock($fs, $bl, false);
    }

    // lparser.c: close_func
    private function close_func(): void
    {
        $fs = $this->ls->fs;
        $f = $fs->f;
        K::luaK_ret($fs, self::luaY_nvarstack($fs), 0);  // final return
        $this->leaveblock($fs);
        K::luaK_finish($fs);
        // luaM_shrinkvector: keep only the used part of each vector
        $f->code = array_slice($f->code, 0, $fs->pc);
        $f->lineinfo = array_slice($f->lineinfo, 0, $fs->pc);
        $f->abslineinfo = array_slice($f->abslineinfo, 0, $fs->nabslineinfo);
        $f->k = array_slice($f->k, 0, $fs->nk);
        $f->p = array_slice($f->p, 0, $fs->np);
        $f->locvars = array_slice($f->locvars, 0, $fs->ndebugvars);
        $f->upvalues = array_slice($f->upvalues, 0, $fs->nups);
        $this->ls->fs = $fs->prev;
    }

    /*============================================================*/
    /* GRAMMAR RULES */
    /*============================================================*/

    /**
     * lparser.c: block_follow. 'until' closes syntactical blocks, but do
     * not close scope, so it is handled in separate.
     */
    private function block_follow(bool $withuntil): bool
    {
        switch ($this->ls->t->token) {
            case X::TK_ELSE:
            case X::TK_ELSEIF:
            case X::TK_END:
            case X::TK_EOS:
                return true;
            case X::TK_UNTIL:
                return $withuntil;
            default:
                return false;
        }
    }

    // lparser.c: statlist
    private function statlist(): void
    {
        // statlist -> { stat [';'] }
        while (!$this->block_follow(true)) {
            if ($this->ls->t->token === X::TK_RETURN) {
                $this->statement();
                return;  // 'return' must be last statement
            }
            $this->statement();
        }
    }

    // lparser.c: fieldsel
    private function fieldsel(E $v): void
    {
        // fieldsel -> ['.' | ':'] NAME
        $fs = $this->ls->fs;
        $key = new E();
        K::luaK_exp2anyregup($fs, $v);
        $this->ls->luaX_next();  // skip the dot or colon
        $this->codename($key);
        K::luaK_indexed($fs, $v, $key);
    }

    // lparser.c: yindex
    private function yindex(E $v): void
    {
        // index -> '[' expr ']'
        $this->ls->luaX_next();  // skip the '['
        $this->expr($v);
        K::luaK_exp2val($this->ls->fs, $v);
        $this->checknext(93);  // ']'
    }

    /*
    ** {======================================================================
    ** Rules for Constructors
    ** =======================================================================
    */

    // lparser.c: recfield
    private function recfield(ConsControl $cc): void
    {
        // recfield -> (NAME | '['exp']') = exp
        $fs = $this->ls->fs;
        $reg = $fs->freereg;
        $tab = new E();
        $key = new E();
        $val = new E();
        if ($this->ls->t->token === X::TK_NAME) {
            $this->codename($key);
        } else {  // ls->t.token == '['
            $this->yindex($key);
        }
        $this->checklimit($fs, $cc->nh, self::MAX_INT, 'items in a constructor');
        $cc->nh++;
        $this->checknext(61);  // '='
        $tab->copyFrom($cc->t);
        K::luaK_indexed($fs, $tab, $key);
        $this->expr($val);
        K::luaK_storevar($fs, $tab, $val);
        $fs->freereg = $reg;  // free registers
    }

    // lparser.c: closelistfield
    private static function closelistfield(FuncState $fs, ConsControl $cc): void
    {
        if ($cc->v->k === E::VVOID) {
            return;  // there is no list item
        }
        K::luaK_exp2nextreg($fs, $cc->v);
        $cc->v->k = E::VVOID;
        if ($cc->tostore === O::LFIELDS_PER_FLUSH) {
            K::luaK_setlist($fs, $cc->t->info, $cc->na, $cc->tostore);  // flush
            $cc->na += $cc->tostore;
            $cc->tostore = 0;  // no more items pending
        }
    }

    // lparser.c: lastlistfield
    private static function lastlistfield(FuncState $fs, ConsControl $cc): void
    {
        if ($cc->tostore === 0) {
            return;
        }
        if (self::hasmultret($cc->v->k)) {
            K::luaK_setmultret($fs, $cc->v);
            K::luaK_setlist($fs, $cc->t->info, $cc->na, K::LUA_MULTRET);
            $cc->na--;  // do not count last expression (unknown number of elements)
        } else {
            if ($cc->v->k !== E::VVOID) {
                K::luaK_exp2nextreg($fs, $cc->v);
            }
            K::luaK_setlist($fs, $cc->t->info, $cc->na, $cc->tostore);
        }
        $cc->na += $cc->tostore;
    }

    // lparser.c: listfield
    private function listfield(ConsControl $cc): void
    {
        // listfield -> exp
        $this->expr($cc->v);
        $cc->tostore++;
    }

    // lparser.c: field
    private function field(ConsControl $cc): void
    {
        // field -> listfield | recfield
        switch ($this->ls->t->token) {
            case X::TK_NAME:  // may be 'listfield' or 'recfield'
                if ($this->ls->luaX_lookahead() !== 61) {  // expression?
                    $this->listfield($cc);
                } else {
                    $this->recfield($cc);
                }
                break;
            case 91:  // '['
                $this->recfield($cc);
                break;
            default:
                $this->listfield($cc);
                break;
        }
    }

    // lparser.c: constructor
    private function constructor(E $t): void
    {
        // constructor -> '{' [ field { sep field } [sep] ] '}'
        // sep -> ',' | ';'
        $fs = $this->ls->fs;
        $line = $this->ls->linenumber;
        $pc = K::luaK_codeABC($fs, O::OP_NEWTABLE, 0, 0, 0);
        K::luaK_code($fs, 0);  // space for extra arg.
        $cc = new ConsControl($t);
        self::init_exp($t, E::VNONRELOC, $fs->freereg);  // table will be at stack top
        K::luaK_reserveregs($fs, 1);
        self::init_exp($cc->v, E::VVOID, 0);  // no value (yet)
        $this->checknext(123);  // '{'
        do {
            if ($this->ls->t->token === 125) {  // '}'
                break;
            }
            self::closelistfield($fs, $cc);
            $this->field($cc);
            $this->checklimit($fs, $cc->tostore + $cc->na + $cc->nh, intdiv(self::INT_MAX, 2), 'items in a constructor');
        } while ($this->testnext(44) || $this->testnext(59));  // ',' or ';'
        $this->check_match(125, 123, $line);  // '}' to close '{'
        self::lastlistfield($fs, $cc);
        K::luaK_settablesize($fs, $pc, $t->info, $cc->na, $cc->nh);
    }

    /* }====================================================================== */

    // lparser.c: setvararg
    private static function setvararg(FuncState $fs, int $nparams): void
    {
        $fs->f->is_vararg = true;
        K::luaK_codeABC($fs, O::OP_VARARGPREP, $nparams, 0, 0);
    }

    // lparser.c: parlist
    private function parlist(): void
    {
        // parlist -> [ {NAME ','} (NAME | '...') ]
        $fs = $this->ls->fs;
        $f = $fs->f;
        $nparams = 0;
        $isvararg = false;
        if ($this->ls->t->token !== 41) {  // is 'parlist' not empty? (not ')')
            do {
                switch ($this->ls->t->token) {
                    case X::TK_NAME:
                        $this->new_localvar($this->str_checkname());
                        $nparams++;
                        break;
                    case X::TK_DOTS:
                        $this->ls->luaX_next();
                        $isvararg = true;
                        break;
                    default:
                        $this->ls->luaX_syntaxerror("<name> or '...' expected");
                }
            } while (!$isvararg && $this->testnext(44));  // ','
        }
        $this->adjustlocalvars($nparams);
        $f->numparams = $fs->nactvar;
        if ($isvararg) {
            self::setvararg($fs, $f->numparams);  // declared vararg
        }
        K::luaK_reserveregs($fs, $fs->nactvar);  // reserve registers for parameters
    }

    // lparser.c: body
    private function body(E $e, bool $ismethod, int $line): void
    {
        // body ->  '(' parlist ')' block END
        $newFs = new FuncState($this->addprototype(), $this->ls);
        $newFs->f->linedefined = $line;
        $this->open_func($newFs, new BlockCnt());
        $this->checknext(40);  // '('
        if ($ismethod) {
            $this->new_localvar('self');  // create 'self' parameter
            $this->adjustlocalvars(1);
        }
        $this->parlist();
        $this->checknext(41);  // ')'
        $this->statlist();
        $newFs->f->lastlinedefined = $this->ls->linenumber;
        $this->check_match(X::TK_END, X::TK_FUNCTION, $line);
        $this->codeclosure($e);
        $this->close_func();
    }

    // lparser.c: explist
    private function explist(E $v): int
    {
        // explist -> expr { ',' expr }
        $n = 1;  // at least one expression
        $this->expr($v);
        while ($this->testnext(44)) {  // ','
            K::luaK_exp2nextreg($this->ls->fs, $v);
            $this->expr($v);
            $n++;
        }
        return $n;
    }

    // lparser.c: funcargs
    private function funcargs(E $f): void
    {
        $fs = $this->ls->fs;
        $args = new E();
        $line = $this->ls->linenumber;
        switch ($this->ls->t->token) {
            case 40:  // funcargs -> '(' [ explist ] ')'
                $this->ls->luaX_next();
                if ($this->ls->t->token === 41) {  // arg list is empty?
                    $args->k = E::VVOID;
                } else {
                    $this->explist($args);
                    if (self::hasmultret($args->k)) {
                        K::luaK_setmultret($fs, $args);
                    }
                }
                $this->check_match(41, 40, $line);
                break;
            case 123:  // funcargs -> constructor
                $this->constructor($args);
                break;
            case X::TK_STRING:  // funcargs -> STRING
                self::codestring($args, $this->ls->t->seminfo);
                $this->ls->luaX_next();  // must use 'seminfo' before 'next'
                break;
            default:
                $this->ls->luaX_syntaxerror('function arguments expected');
        }
        $base = $f->info;  // base register for call
        if (self::hasmultret($args->k)) {
            $nparams = K::LUA_MULTRET;  // open call
        } else {
            if ($args->k !== E::VVOID) {
                K::luaK_exp2nextreg($fs, $args);  // close last argument
            }
            $nparams = $fs->freereg - ($base + 1);
        }
        self::init_exp($f, E::VCALL, K::luaK_codeABC($fs, O::OP_CALL, $base, $nparams + 1, 2));
        K::luaK_fixline($fs, $line);
        // call removes function and arguments and leaves one result (unless changed later)
        $fs->freereg = $base + 1;
    }

    /*
    ** {======================================================================
    ** Expression parsing
    ** =======================================================================
    */

    // lparser.c: primaryexp
    private function primaryexp(E $v): void
    {
        // primaryexp -> NAME | '(' expr ')'
        switch ($this->ls->t->token) {
            case 40:  // '('
                $line = $this->ls->linenumber;
                $this->ls->luaX_next();
                $this->expr($v);
                $this->check_match(41, 40, $line);
                K::luaK_dischargevars($this->ls->fs, $v);
                return;
            case X::TK_NAME:
                $this->singlevar($v);
                return;
            default:
                $this->ls->luaX_syntaxerror('unexpected symbol');
        }
    }

    // lparser.c: suffixedexp
    private function suffixedexp(E $v): void
    {
        // suffixedexp ->
        //   primaryexp { '.' NAME | '[' exp ']' | ':' NAME funcargs | funcargs }
        $fs = $this->ls->fs;
        $this->primaryexp($v);
        for (;;) {
            switch ($this->ls->t->token) {
                case 46:  // '.': fieldsel
                    $this->fieldsel($v);
                    break;
                case 91:  // '[' exp ']'
                    $key = new E();
                    K::luaK_exp2anyregup($fs, $v);
                    $this->yindex($key);
                    K::luaK_indexed($fs, $v, $key);
                    break;
                case 58:  // ':' NAME funcargs
                    $key = new E();
                    $this->ls->luaX_next();
                    $this->codename($key);
                    K::luaK_self($fs, $v, $key);
                    $this->funcargs($v);
                    break;
                case 40:  // '('
                case X::TK_STRING:
                case 123:  // '{': funcargs
                    K::luaK_exp2nextreg($fs, $v);
                    $this->funcargs($v);
                    break;
                default:
                    return;
            }
        }
    }

    // lparser.c: simpleexp
    private function simpleexp(E $v): void
    {
        // simpleexp -> FLT | INT | STRING | NIL | TRUE | FALSE | ... |
        //              constructor | FUNCTION body | suffixedexp
        switch ($this->ls->t->token) {
            case X::TK_FLT:
                self::init_exp($v, E::VKFLT, 0);
                $v->nval = $this->ls->t->seminfo;
                break;
            case X::TK_INT:
                self::init_exp($v, E::VKINT, 0);
                $v->ival = $this->ls->t->seminfo;
                break;
            case X::TK_STRING:
                self::codestring($v, $this->ls->t->seminfo);
                break;
            case X::TK_NIL:
                self::init_exp($v, E::VNIL, 0);
                break;
            case X::TK_TRUE:
                self::init_exp($v, E::VTRUE, 0);
                break;
            case X::TK_FALSE:
                self::init_exp($v, E::VFALSE, 0);
                break;
            case X::TK_DOTS:  // vararg
                $fs = $this->ls->fs;
                $this->check_condition($fs->f->is_vararg, "cannot use '...' outside a vararg function");
                self::init_exp($v, E::VVARARG, K::luaK_codeABC($fs, O::OP_VARARG, 0, 0, 1));
                break;
            case 123:  // '{': constructor
                $this->constructor($v);
                return;
            case X::TK_FUNCTION:
                $this->ls->luaX_next();
                $this->body($v, false, $this->ls->linenumber);
                return;
            default:
                $this->suffixedexp($v);
                return;
        }
        $this->ls->luaX_next();
    }

    // lparser.c: getunopr
    private static function getunopr(int $op): int
    {
        return match ($op) {
            X::TK_NOT => K::OPR_NOT,
            45 => K::OPR_MINUS,   // '-'
            126 => K::OPR_BNOT,   // '~'
            35 => K::OPR_LEN,     // '#'
            default => K::OPR_NOUNOPR,
        };
    }

    // lparser.c: getbinopr
    private static function getbinopr(int $op): int
    {
        return match ($op) {
            43 => K::OPR_ADD,     // '+'
            45 => K::OPR_SUB,     // '-'
            42 => K::OPR_MUL,     // '*'
            37 => K::OPR_MOD,     // '%'
            94 => K::OPR_POW,     // '^'
            47 => K::OPR_DIV,     // '/'
            X::TK_IDIV => K::OPR_IDIV,
            38 => K::OPR_BAND,    // '&'
            124 => K::OPR_BOR,    // '|'
            126 => K::OPR_BXOR,   // '~'
            X::TK_SHL => K::OPR_SHL,
            X::TK_SHR => K::OPR_SHR,
            X::TK_CONCAT => K::OPR_CONCAT,
            X::TK_NE => K::OPR_NE,
            X::TK_EQ => K::OPR_EQ,
            60 => K::OPR_LT,      // '<'
            X::TK_LE => K::OPR_LE,
            62 => K::OPR_GT,      // '>'
            X::TK_GE => K::OPR_GE,
            X::TK_AND => K::OPR_AND,
            X::TK_OR => K::OPR_OR,
            default => K::OPR_NOBINOPR,
        };
    }

    /**
     * lparser.c: subexpr
     * subexpr -> (simpleexp | unop subexpr) { binop subexpr }
     * where 'binop' is any binary operator with a priority higher than 'limit'
     */
    private function subexpr(E $v, int $limit): int
    {
        $this->enterlevel();
        $uop = self::getunopr($this->ls->t->token);
        if ($uop !== K::OPR_NOUNOPR) {  // prefix (unary) operator?
            $line = $this->ls->linenumber;
            $this->ls->luaX_next();  // skip operator
            $this->subexpr($v, self::UNARY_PRIORITY);
            K::luaK_prefix($this->ls->fs, $uop, $v, $line);
        } else {
            $this->simpleexp($v);
        }
        // expand while operators have priorities higher than 'limit'
        $op = self::getbinopr($this->ls->t->token);
        while ($op !== K::OPR_NOBINOPR && self::PRIORITY[$op][0] > $limit) {
            $v2 = new E();
            $line = $this->ls->linenumber;
            $this->ls->luaX_next();  // skip operator
            K::luaK_infix($this->ls->fs, $op, $v);
            // read sub-expression with higher priority
            $nextop = $this->subexpr($v2, self::PRIORITY[$op][1]);
            K::luaK_posfix($this->ls->fs, $op, $v, $v2, $line);
            $op = $nextop;
        }
        $this->leavelevel();
        return $op;  // return first untreated operator
    }

    // lparser.c: expr
    private function expr(E $v): void
    {
        $this->subexpr($v, 0);
    }

    /* }==================================================================== */

    /*
    ** {======================================================================
    ** Rules for Statements
    ** =======================================================================
    */

    // lparser.c: block
    private function block(): void
    {
        // block -> statlist
        $fs = $this->ls->fs;
        $bl = new BlockCnt();
        self::enterblock($fs, $bl, false);
        $this->statlist();
        $this->leaveblock($fs);
    }

    /**
     * lparser.c: check_conflict. In an assignment to an upvalue/local
     * variable, if it is used as table or index in a previous assignment
     * of the list, save its original value in a safe place and use the
     * copy there.
     */
    private function check_conflict(?LhsAssign $lh, E $v): void
    {
        $fs = $this->ls->fs;
        $extra = $fs->freereg;  // eventual position to save local variable
        $conflict = false;
        for (; $lh !== null; $lh = $lh->prev) {  // check all previous assignments
            if (!E::vkisindexed($lh->v->k)) {
                continue;
            }
            // assignment to table field
            if ($lh->v->k === E::VINDEXUP) {  // is table an upvalue?
                if ($v->k === E::VUPVAL && $lh->v->indT === $v->info) {
                    $conflict = true;  // table is the upvalue being assigned now
                    $lh->v->k = E::VINDEXSTR;
                    $lh->v->indT = $extra;  // assignment will use safe copy
                }
            } else {  // table is a register
                if ($v->k === E::VLOCAL && $lh->v->indT === $v->varRidx) {
                    $conflict = true;  // table is the local being assigned now
                    $lh->v->indT = $extra;  // assignment will use safe copy
                }
                // is index the local being assigned?
                if ($lh->v->k === E::VINDEXED && $v->k === E::VLOCAL && $lh->v->indIdx === $v->varRidx) {
                    $conflict = true;
                    $lh->v->indIdx = $extra;  // previous assignment will use safe copy
                }
            }
        }
        if ($conflict) {
            // copy upvalue/local value to a temporary (in position 'extra')
            if ($v->k === E::VLOCAL) {
                K::luaK_codeABC($fs, O::OP_MOVE, $extra, $v->varRidx, 0);
            } else {
                K::luaK_codeABC($fs, O::OP_GETUPVAL, $extra, $v->info, 0);
            }
            K::luaK_reserveregs($fs, 1);
        }
    }

    /**
     * lparser.c: restassign. Parse and compile a multiple assignment; the
     * first "variable" (a 'suffixedexp') was already read by the caller.
     *
     * assignment -> suffixedexp restassign
     * restassign -> ',' suffixedexp restassign | '=' explist
     */
    private function restassign(LhsAssign $lh, int $nvars): void
    {
        $e = new E();
        $this->check_condition(E::vkisvar($lh->v->k), 'syntax error');
        $this->check_readonly($lh->v);
        if ($this->testnext(44)) {  // restassign -> ',' suffixedexp restassign
            $nv = new LhsAssign($lh);
            $this->suffixedexp($nv->v);
            if (!E::vkisindexed($nv->v->k)) {
                $this->check_conflict($lh, $nv->v);
            }
            $this->enterlevel();  // control recursion depth
            $this->restassign($nv, $nvars + 1);
            $this->leavelevel();
        } else {  // restassign -> '=' explist
            $this->checknext(61);  // '='
            $nexps = $this->explist($e);
            if ($nexps !== $nvars) {
                $this->adjust_assign($nvars, $nexps, $e);
            } else {
                K::luaK_setoneret($this->ls->fs, $e);  // close last expression
                K::luaK_storevar($this->ls->fs, $lh->v, $e);
                return;  // avoid default
            }
        }
        self::init_exp($e, E::VNONRELOC, $this->ls->fs->freereg - 1);  // default assignment
        K::luaK_storevar($this->ls->fs, $lh->v, $e);
    }

    // lparser.c: cond
    private function cond(): int
    {
        // cond -> exp
        $v = new E();
        $this->expr($v);  // read condition
        if ($v->k === E::VNIL) {
            $v->k = E::VFALSE;  // 'falses' are all equal here
        }
        K::luaK_goiftrue($this->ls->fs, $v);
        return $v->f;
    }

    // lparser.c: gotostat
    private function gotostat(): void
    {
        $fs = $this->ls->fs;
        $line = $this->ls->linenumber;
        $name = $this->str_checkname();  // label's name
        $lb = $this->findlabel($name);
        if ($lb === null) {  // no label?
            // forward jump; will be resolved when the label is declared
            $this->newgotoentry($name, $line, K::luaK_jump($fs));
            return;
        }
        // found a label: backward jump; will be resolved here
        $lblevel = self::reglevel($fs, $lb->nactvar);  // label level
        if (self::luaY_nvarstack($fs) > $lblevel) {  // leaving the scope of a variable?
            K::luaK_codeABC($fs, O::OP_CLOSE, $lblevel, 0, 0);
        }
        // create jump and link it to the label
        K::luaK_patchlist($fs, K::luaK_jump($fs), $lb->pc);
    }

    // lparser.c: breakstat (semantically equivalent to "goto break")
    private function breakstat(): void
    {
        $line = $this->ls->linenumber;
        $this->ls->luaX_next();  // skip break
        $this->newgotoentry('break', $line, K::luaK_jump($this->ls->fs));
    }

    // lparser.c: checkrepeated (check whether there is already a label with the given 'name')
    private function checkrepeated(string $name): void
    {
        $lb = $this->findlabel($name);
        if ($lb !== null) {  // already defined?
            K::luaK_semerror($this->ls, "label '$name' already defined on line {$lb->line}");
        }
    }

    // lparser.c: labelstat
    private function labelstat(string $name, int $line): void
    {
        // label -> '::' NAME '::'
        $this->checknext(X::TK_DBCOLON);  // skip double colon
        while ($this->ls->t->token === 59 || $this->ls->t->token === X::TK_DBCOLON) {  // ';' or '::'
            $this->statement();  // skip other no-op statements
        }
        $this->checkrepeated($name);  // check for repeated labels
        $this->createlabel($name, $line, $this->block_follow(false));
    }

    // lparser.c: whilestat
    private function whilestat(int $line): void
    {
        // whilestat -> WHILE cond DO block END
        $fs = $this->ls->fs;
        $bl = new BlockCnt();
        $this->ls->luaX_next();  // skip WHILE
        $whileinit = K::luaK_getlabel($fs);
        $condexit = $this->cond();
        self::enterblock($fs, $bl, true);
        $this->checknext(X::TK_DO);
        $this->block();
        K::luaK_jumpto($fs, $whileinit);
        $this->check_match(X::TK_END, X::TK_WHILE, $line);
        $this->leaveblock($fs);
        K::luaK_patchtohere($fs, $condexit);  // false conditions finish the loop
    }

    // lparser.c: repeatstat
    private function repeatstat(int $line): void
    {
        // repeatstat -> REPEAT block UNTIL cond
        $fs = $this->ls->fs;
        $repeatInit = K::luaK_getlabel($fs);
        $bl1 = new BlockCnt();
        $bl2 = new BlockCnt();
        self::enterblock($fs, $bl1, true);  // loop block
        self::enterblock($fs, $bl2, false);  // scope block
        $this->ls->luaX_next();  // skip REPEAT
        $this->statlist();
        $this->check_match(X::TK_UNTIL, X::TK_REPEAT, $line);
        $condexit = $this->cond();  // read condition (inside scope block)
        $this->leaveblock($fs);  // finish scope
        if ($bl2->upval) {  // upvalues?
            $exit = K::luaK_jump($fs);  // normal exit must jump over fix
            K::luaK_patchtohere($fs, $condexit);  // repetition must close upvalues
            K::luaK_codeABC($fs, O::OP_CLOSE, self::reglevel($fs, $bl2->nactvar), 0, 0);
            $condexit = K::luaK_jump($fs);  // repeat after closing upvalues
            K::luaK_patchtohere($fs, $exit);  // normal exit comes to here
        }
        K::luaK_patchlist($fs, $condexit, $repeatInit);  // close the loop
        $this->leaveblock($fs);  // finish loop
    }

    // lparser.c: exp1 (read an expression and put its results in next stack slot)
    private function exp1(): void
    {
        $e = new E();
        $this->expr($e);
        K::luaK_exp2nextreg($this->ls->fs, $e);
    }

    /**
     * lparser.c: fixforjump. Fix for instruction at position 'pc' to jump
     * to 'dest'; 'back' true means a back jump.
     */
    private function fixforjump(FuncState $fs, int $pc, int $dest, bool $back): void
    {
        $offset = $dest - ($pc + 1);
        if ($back) {
            $offset = -$offset;
        }
        if ($offset > O::MAXARG_Bx) {
            $this->ls->luaX_syntaxerror('control structure too long');
        }
        $fs->f->code[$pc] = O::SETARG_Bx($fs->f->code[$pc], $offset);
    }

    // lparser.c: forbody (generate code for a 'for' loop)
    private function forbody(int $base, int $line, int $nvars, bool $isgen): void
    {
        // forbody -> DO block
        $bl = new BlockCnt();
        $fs = $this->ls->fs;
        $this->checknext(X::TK_DO);
        $prep = K::luaK_codeABx($fs, $isgen ? O::OP_TFORPREP : O::OP_FORPREP, $base, 0);
        self::enterblock($fs, $bl, false);  // scope for declared variables
        $this->adjustlocalvars($nvars);
        K::luaK_reserveregs($fs, $nvars);
        $this->block();
        $this->leaveblock($fs);  // end of scope for declared variables
        $this->fixforjump($fs, $prep, K::luaK_getlabel($fs), false);
        if ($isgen) {  // generic for?
            K::luaK_codeABC($fs, O::OP_TFORCALL, $base, 0, $nvars);
            K::luaK_fixline($fs, $line);
        }
        $endfor = K::luaK_codeABx($fs, $isgen ? O::OP_TFORLOOP : O::OP_FORLOOP, $base, 0);
        $this->fixforjump($fs, $endfor, $prep + 1, true);
        K::luaK_fixline($fs, $line);
    }

    // lparser.c: fornum
    private function fornum(string $varname, int $line): void
    {
        // fornum -> NAME = exp,exp[,exp] forbody
        $fs = $this->ls->fs;
        $base = $fs->freereg;
        $this->new_localvar('(for state)');
        $this->new_localvar('(for state)');
        $this->new_localvar('(for state)');
        $this->new_localvar($varname);
        $this->checknext(61);  // '='
        $this->exp1();  // initial value
        $this->checknext(44);  // ','
        $this->exp1();  // limit
        if ($this->testnext(44)) {
            $this->exp1();  // optional step
        } else {  // default step = 1
            K::luaK_int($fs, $fs->freereg, 1);
            K::luaK_reserveregs($fs, 1);
        }
        $this->adjustlocalvars(3);  // control variables
        $this->forbody($base, $line, 1, false);
    }

    // lparser.c: forlist
    private function forlist(string $indexname): void
    {
        // forlist -> NAME {,NAME} IN explist forbody
        $fs = $this->ls->fs;
        $e = new E();
        $nvars = 5;  // gen, state, control, toclose, 'indexname'
        $base = $fs->freereg;
        // create control variables
        $this->new_localvar('(for state)');
        $this->new_localvar('(for state)');
        $this->new_localvar('(for state)');
        $this->new_localvar('(for state)');
        // create declared variables
        $this->new_localvar($indexname);
        while ($this->testnext(44)) {  // ','
            $this->new_localvar($this->str_checkname());
            $nvars++;
        }
        $this->checknext(X::TK_IN);
        $line = $this->ls->linenumber;
        $this->adjust_assign(4, $this->explist($e), $e);
        $this->adjustlocalvars(4);  // control variables
        self::marktobeclosed($fs);  // last control var. must be closed
        K::luaK_checkstack($fs, 3);  // extra space to call generator
        $this->forbody($base, $line, $nvars - 4, true);
    }

    // lparser.c: forstat
    private function forstat(int $line): void
    {
        // forstat -> FOR (fornum | forlist) END
        $fs = $this->ls->fs;
        $bl = new BlockCnt();
        self::enterblock($fs, $bl, true);  // scope for loop and control variables
        $this->ls->luaX_next();  // skip 'for'
        $varname = $this->str_checkname();  // first variable name
        switch ($this->ls->t->token) {
            case 61:  // '='
                $this->fornum($varname, $line);
                break;
            case 44:  // ','
            case X::TK_IN:
                $this->forlist($varname);
                break;
            default:
                $this->ls->luaX_syntaxerror("'=' or 'in' expected");
        }
        $this->check_match(X::TK_END, X::TK_FOR, $line);
        $this->leaveblock($fs);  // loop scope ('break' jumps to this point)
    }

    // lparser.c: test_then_block
    private function test_then_block(int &$escapelist): void
    {
        // test_then_block -> [IF | ELSEIF] cond THEN block
        $bl = new BlockCnt();
        $fs = $this->ls->fs;
        $v = new E();
        $this->ls->luaX_next();  // skip IF or ELSEIF
        $this->expr($v);  // read condition
        $this->checknext(X::TK_THEN);
        if ($this->ls->t->token === X::TK_BREAK) {  // 'if x then break' ?
            $line = $this->ls->linenumber;
            K::luaK_goiffalse($this->ls->fs, $v);  // will jump if condition is true
            $this->ls->luaX_next();  // skip 'break'
            self::enterblock($fs, $bl, false);  // must enter block before 'goto'
            $this->newgotoentry('break', $line, $v->t);
            while ($this->testnext(59)) {  // skip semicolons
            }
            if ($this->block_follow(false)) {  // jump is the entire block?
                $this->leaveblock($fs);
                return;  // and that is it
            }
            // else must skip over 'then' part if condition is false
            $jf = K::luaK_jump($fs);
        } else {  // regular case (not a break)
            K::luaK_goiftrue($this->ls->fs, $v);  // skip over block if condition is false
            self::enterblock($fs, $bl, false);
            $jf = $v->f;
        }
        $this->statlist();  // 'then' part
        $this->leaveblock($fs);
        if ($this->ls->t->token === X::TK_ELSE || $this->ls->t->token === X::TK_ELSEIF) {  // followed by 'else'/'elseif'?
            K::luaK_concat($fs, $escapelist, K::luaK_jump($fs));  // must jump over it
        }
        K::luaK_patchtohere($fs, $jf);
    }

    // lparser.c: ifstat
    private function ifstat(int $line): void
    {
        // ifstat -> IF cond THEN block {ELSEIF cond THEN block} [ELSE block] END
        $fs = $this->ls->fs;
        $escapelist = K::NO_JUMP;  // exit list for finished parts
        $this->test_then_block($escapelist);  // IF cond THEN block
        while ($this->ls->t->token === X::TK_ELSEIF) {
            $this->test_then_block($escapelist);  // ELSEIF cond THEN block
        }
        if ($this->testnext(X::TK_ELSE)) {
            $this->block();  // 'else' part
        }
        $this->check_match(X::TK_END, X::TK_IF, $line);
        K::luaK_patchtohere($fs, $escapelist);  // patch escape list to 'if' end
    }

    // lparser.c: localfunc
    private function localfunc(): void
    {
        $b = new E();
        $fs = $this->ls->fs;
        $fvar = $fs->nactvar;  // function's variable index
        $this->new_localvar($this->str_checkname());  // new local variable
        $this->adjustlocalvars(1);  // enter its scope
        $this->body($b, false, $this->ls->linenumber);  // function created in next register
        // debug information will only see the variable after this point!
        self::localdebuginfo($fs, $fvar)->startpc = $fs->pc;
    }

    // lparser.c: getlocalattribute
    private function getlocalattribute(): int
    {
        // ATTRIB -> ['<' Name '>']
        if ($this->testnext(60)) {  // '<'
            $attribute = $this->str_checkname();
            $this->checknext(62);  // '>'
            if ($attribute === 'const') {
                return Vardesc::RDKCONST;  // read-only variable
            }
            if ($attribute === 'close') {
                return Vardesc::RDKTOCLOSE;  // to-be-closed variable
            }
            K::luaK_semerror($this->ls, "unknown attribute '$attribute'");
        }
        return Vardesc::VDKREG;  // regular variable
    }

    // lparser.c: checktoclose
    private static function checktoclose(FuncState $fs, int $level): void
    {
        if ($level !== -1) {  // is there a to-be-closed variable?
            self::marktobeclosed($fs);
            K::luaK_codeABC($fs, O::OP_TBC, self::reglevel($fs, $level), 0, 0);
        }
    }

    // lparser.c: localstat
    private function localstat(): void
    {
        // stat -> LOCAL NAME ATTRIB { ',' NAME ATTRIB } ['=' explist]
        $fs = $this->ls->fs;
        $toclose = -1;  // index of to-be-closed variable (if any)
        $nvars = 0;
        $e = new E();
        do {
            $vidx = $this->new_localvar($this->str_checkname());
            $kind = $this->getlocalattribute();
            self::getlocalvardesc($fs, $vidx)->kind = $kind;
            if ($kind === Vardesc::RDKTOCLOSE) {  // to-be-closed?
                if ($toclose !== -1) {  // one already present?
                    K::luaK_semerror($this->ls, 'multiple to-be-closed variables in local list');
                }
                $toclose = $fs->nactvar + $nvars;
            }
            $nvars++;
        } while ($this->testnext(44));  // ','
        if ($this->testnext(61)) {  // '='
            $nexps = $this->explist($e);
        } else {
            $e->k = E::VVOID;
            $nexps = 0;
        }
        $var = self::getlocalvardesc($fs, $vidx);  // get last variable
        if ($nvars === $nexps  // no adjustments?
            && $var->kind === Vardesc::RDKCONST  // last variable is const?
            && K::luaK_exp2const($fs, $e, $var->k)) {  // compile-time constant?
            $var->kind = Vardesc::RDKCTC;  // variable is a compile-time constant
            $this->adjustlocalvars($nvars - 1);  // exclude last variable
            $fs->nactvar++;  // but count it
        } else {
            $this->adjust_assign($nvars, $nexps, $e);
            $this->adjustlocalvars($nvars);
        }
        self::checktoclose($fs, $toclose);
    }

    // lparser.c: funcname
    private function funcname(E $v): bool
    {
        // funcname -> NAME {fieldsel} [':' NAME]
        $ismethod = false;
        $this->singlevar($v);
        while ($this->ls->t->token === 46) {  // '.'
            $this->fieldsel($v);
        }
        if ($this->ls->t->token === 58) {  // ':'
            $ismethod = true;
            $this->fieldsel($v);
        }
        return $ismethod;
    }

    // lparser.c: funcstat
    private function funcstat(int $line): void
    {
        // funcstat -> FUNCTION funcname body
        $v = new E();
        $b = new E();
        $this->ls->luaX_next();  // skip FUNCTION
        $ismethod = $this->funcname($v);
        $this->body($b, $ismethod, $line);
        $this->check_readonly($v);
        K::luaK_storevar($this->ls->fs, $v, $b);
        K::luaK_fixline($this->ls->fs, $line);  // definition "happens" in the first line
    }

    // lparser.c: exprstat
    private function exprstat(): void
    {
        // stat -> func | assignment
        $fs = $this->ls->fs;
        $v = new LhsAssign(null);
        $this->suffixedexp($v->v);
        if ($this->ls->t->token === 61 || $this->ls->t->token === 44) {  // stat -> assignment ?
            $this->restassign($v, 1);
            return;
        }
        // stat -> func
        $this->check_condition($v->v->k === E::VCALL, 'syntax error');
        $fs->f->code[$v->v->info] = O::SETARG_C($fs->f->code[$v->v->info], 1);  // call statement uses no results
    }

    // lparser.c: retstat
    private function retstat(): void
    {
        // stat -> RETURN [explist] [';']
        $fs = $this->ls->fs;
        $e = new E();
        $first = self::luaY_nvarstack($fs);  // first slot to be returned
        if ($this->block_follow(true) || $this->ls->t->token === 59) {  // ';'
            $nret = 0;  // return no values
        } else {
            $nret = $this->explist($e);  // optional return values
            if (self::hasmultret($e->k)) {
                K::luaK_setmultret($fs, $e);
                if ($e->k === E::VCALL && $nret === 1 && !$fs->bl->insidetbc) {  // tail call?
                    $fs->f->code[$e->info] = O::SET_OPCODE($fs->f->code[$e->info], O::OP_TAILCALL);
                }
                $nret = K::LUA_MULTRET;  // return all values
            } elseif ($nret === 1) {  // only one single value?
                $first = K::luaK_exp2anyreg($fs, $e);  // can use original slot
            } else {  // values must go to the top of the stack
                K::luaK_exp2nextreg($fs, $e);
            }
        }
        K::luaK_ret($fs, $first, $nret);
        $this->testnext(59);  // skip optional semicolon
    }

    // lparser.c: statement
    private function statement(): void
    {
        $line = $this->ls->linenumber;  // may be needed for error messages
        $this->enterlevel();
        switch ($this->ls->t->token) {
            case 59:  // stat -> ';' (empty statement)
                $this->ls->luaX_next();  // skip ';'
                break;
            case X::TK_IF:  // stat -> ifstat
                $this->ifstat($line);
                break;
            case X::TK_WHILE:  // stat -> whilestat
                $this->whilestat($line);
                break;
            case X::TK_DO:  // stat -> DO block END
                $this->ls->luaX_next();  // skip DO
                $this->block();
                $this->check_match(X::TK_END, X::TK_DO, $line);
                break;
            case X::TK_FOR:  // stat -> forstat
                $this->forstat($line);
                break;
            case X::TK_REPEAT:  // stat -> repeatstat
                $this->repeatstat($line);
                break;
            case X::TK_FUNCTION:  // stat -> funcstat
                $this->funcstat($line);
                break;
            case X::TK_LOCAL:  // stat -> localstat
                $this->ls->luaX_next();  // skip LOCAL
                if ($this->testnext(X::TK_FUNCTION)) {  // local function?
                    $this->localfunc();
                } else {
                    $this->localstat();
                }
                break;
            case X::TK_DBCOLON:  // stat -> label
                $this->ls->luaX_next();  // skip double colon
                $this->labelstat($this->str_checkname(), $line);
                break;
            case X::TK_RETURN:  // stat -> retstat
                $this->ls->luaX_next();  // skip RETURN
                $this->retstat();
                break;
            case X::TK_BREAK:  // stat -> breakstat
                $this->breakstat();
                break;
            case X::TK_GOTO:  // stat -> 'goto' NAME
                $this->ls->luaX_next();  // skip 'goto'
                $this->gotostat();
                break;
            default:  // stat -> func | assignment
                $this->exprstat();
                break;
        }
        $this->ls->fs->freereg = self::luaY_nvarstack($this->ls->fs);  // free registers
        $this->leavelevel();
    }

    /* }====================================================================== */

    // lparser.c: mainfunc
    private function mainfunc(FuncState $fs): void
    {
        $this->open_func($fs, new BlockCnt());
        self::setvararg($fs, 0);  // main function is always declared vararg
        $env = $this->allocupvalue($fs);  // ...set environment upvalue
        $env->instack = true;
        $env->idx = 0;
        $env->kind = Vardesc::VDKREG;
        $env->name = $this->ls->envn;
        $this->ls->luaX_next();  // read first token
        $this->statlist();  // parse main body
        $this->check(X::TK_EOS);
        $this->close_func();
    }
}
