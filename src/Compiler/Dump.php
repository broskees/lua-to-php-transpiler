<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Port of ldump.c: save precompiled Lua chunks (Proto -> bytes).
 * Output is byte-identical to `luac5.4 -o` (and `luac5.4 -s -o` when stripping).
 */
final class Dump
{
    private string $output = '';

    private function __construct(
        private readonly bool $strip,
    ) {
    }

    // ldump.c: luaU_dump
    public static function dump(Proto $f, bool $strip): string
    {
        $dumpState = new self($strip);
        $dumpState->dumpHeader();
        $dumpState->dumpByte(count($f->upvalues));
        $dumpState->dumpFunction($f, null);
        return $dumpState->output;
    }

    // ldump.c: dumpByte
    private function dumpByte(int $value): void
    {
        $this->output .= chr($value & 0xff);
    }

    /**
     * ldump.c: dumpSize. Big-endian groups of 7 bits, the last byte marked
     * with 0x80. The value is treated as an unsigned 64-bit size_t.
     */
    private function dumpSize(int $value): void
    {
        $sevenBitGroups = chr(($value & 0x7f) | 0x80);  // mark last byte
        $value = ($value >> 7) & (PHP_INT_MAX >> 6);  // unsigned shift
        while ($value !== 0) {
            $sevenBitGroups = chr($value & 0x7f) . $sevenBitGroups;
            $value >>= 7;
        }
        $this->output .= $sevenBitGroups;
    }

    // ldump.c: dumpInt
    private function dumpInt(int $value): void
    {
        $this->dumpSize($value);
    }

    // ldump.c: dumpNumber
    private function dumpNumber(float $value): void
    {
        $this->output .= pack('e', $value);
    }

    // ldump.c: dumpInteger
    private function dumpInteger(int $value): void
    {
        $this->output .= pack('P', $value);
    }

    // ldump.c: dumpString
    private function dumpString(?string $string): void
    {
        if ($string === null) {
            $this->dumpSize(0);
            return;
        }
        $this->dumpSize(strlen($string) + 1);
        $this->output .= $string;
    }

    // ldump.c: dumpCode
    private function dumpCode(Proto $f): void
    {
        $this->dumpInt(count($f->code));
        if ($f->code !== []) {
            $this->output .= pack('V*', ...$f->code);
        }
    }

    // ldump.c: dumpConstants
    private function dumpConstants(Proto $f): void
    {
        $this->dumpInt(count($f->k));
        foreach ($f->k as $constant) {
            if ($constant === null) {
                $this->dumpByte(Undump::LUA_VNIL);
            } elseif ($constant === false) {
                $this->dumpByte(Undump::LUA_VFALSE);
            } elseif ($constant === true) {
                $this->dumpByte(Undump::LUA_VTRUE);
            } elseif (is_float($constant)) {
                $this->dumpByte(Undump::LUA_VNUMFLT);
                $this->dumpNumber($constant);
            } elseif (is_int($constant)) {
                $this->dumpByte(Undump::LUA_VNUMINT);
                $this->dumpInteger($constant);
            } else {
                // lstring.c: luaS_newlstr makes strings up to LUAI_MAXSHORTLEN short
                $isShortString = strlen($constant) <= Undump::LUAI_MAXSHORTLEN;
                $this->dumpByte($isShortString ? Undump::LUA_VSHRSTR : Undump::LUA_VLNGSTR);
                $this->dumpString($constant);
            }
        }
    }

    // ldump.c: dumpProtos
    private function dumpProtos(Proto $f): void
    {
        $this->dumpInt(count($f->p));
        foreach ($f->p as $childProto) {
            $this->dumpFunction($childProto, $f->source);
        }
    }

    // ldump.c: dumpUpvalues
    private function dumpUpvalues(Proto $f): void
    {
        $this->dumpInt(count($f->upvalues));
        foreach ($f->upvalues as $upvalue) {
            $this->dumpByte($upvalue->instack ? 1 : 0);
            $this->dumpByte($upvalue->idx);
            $this->dumpByte($upvalue->kind);
        }
    }

    // ldump.c: dumpDebug
    private function dumpDebug(Proto $f): void
    {
        $lineinfo = $this->strip ? [] : $f->lineinfo;
        $this->dumpInt(count($lineinfo));
        if ($lineinfo !== []) {
            $this->output .= pack('c*', ...$lineinfo);
        }

        $abslineinfo = $this->strip ? [] : $f->abslineinfo;
        $this->dumpInt(count($abslineinfo));
        foreach ($abslineinfo as $absLine) {
            $this->dumpInt($absLine->pc);
            $this->dumpInt($absLine->line);
        }

        $locvars = $this->strip ? [] : $f->locvars;
        $this->dumpInt(count($locvars));
        foreach ($locvars as $locvar) {
            $this->dumpString($locvar->varname);
            $this->dumpInt($locvar->startpc);
            $this->dumpInt($locvar->endpc);
        }

        $upvalues = $this->strip ? [] : $f->upvalues;
        $this->dumpInt(count($upvalues));
        foreach ($upvalues as $upvalue) {
            $this->dumpString($upvalue->name);
        }
    }

    /**
     * ldump.c: dumpFunction. C compares TString pointers (f->source ==
     * psource); a child's source is shared with its parent, so string
     * equality gives the same answer for everything the compiler and
     * lundump.c produce.
     */
    private function dumpFunction(Proto $f, ?string $parentSource): void
    {
        if ($this->strip || $f->source === $parentSource) {
            $this->dumpString(null);  // no debug info or same source as its parent
        } else {
            $this->dumpString($f->source);
        }
        $this->dumpInt($f->linedefined);
        $this->dumpInt($f->lastlinedefined);
        $this->dumpByte($f->numparams);
        $this->dumpByte($f->is_vararg ? 1 : 0);
        $this->dumpByte($f->maxstacksize);
        $this->dumpCode($f);
        $this->dumpConstants($f);
        $this->dumpUpvalues($f);
        $this->dumpProtos($f);
        $this->dumpDebug($f);
    }

    // ldump.c: dumpHeader
    private function dumpHeader(): void
    {
        $this->output .= Undump::LUA_SIGNATURE;
        $this->dumpByte(Undump::LUAC_VERSION);
        $this->dumpByte(Undump::LUAC_FORMAT);
        $this->output .= Undump::LUAC_DATA;
        $this->dumpByte(4);  // sizeof(Instruction)
        $this->dumpByte(8);  // sizeof(lua_Integer)
        $this->dumpByte(8);  // sizeof(lua_Number)
        $this->dumpInteger(Undump::LUAC_INT);
        $this->dumpNumber(Undump::LUAC_NUM);
    }
}
