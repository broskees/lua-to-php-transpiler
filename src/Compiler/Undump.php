<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Port of lundump.c: load precompiled Lua chunks (bytes -> Proto).
 *
 * The format is native to the reference build: little-endian, 4-byte
 * instructions, 8-byte lua_Integer and lua_Number.
 */
final class Undump
{
    // lundump.h / lua.h / llimits.h
    public const LUA_SIGNATURE = "\x1bLua";
    public const LUAC_DATA = "\x19\x93\r\n\x1a\n";
    public const LUAC_INT = 0x5678;
    public const LUAC_NUM = 370.5;
    public const LUAC_VERSION = 0x54;  // (LUA_VERSION_NUM / 100) * 16 + LUA_VERSION_NUM % 100
    public const LUAC_FORMAT = 0;
    public const LUAI_MAXSHORTLEN = 40;

    // lobject.h: variant tags written before each constant
    public const LUA_VNIL = 0;
    public const LUA_VFALSE = 1;
    public const LUA_VTRUE = 17;
    public const LUA_VNUMINT = 3;
    public const LUA_VNUMFLT = 19;
    public const LUA_VSHRSTR = 4;
    public const LUA_VLNGSTR = 20;

    // lundump.c: loadInt limit (INT_MAX) and loadSize limit (MAX_SIZET), both already '>> 7'
    private const INT_LIMIT_SHIFTED = 0x7FFFFFFF >> 7;
    private const SIZE_LIMIT_SHIFTED = (1 << 57) - 1;

    private int $position = 1;

    private function __construct(
        private readonly string $bytes,
        private readonly string $nameForErrors,
    ) {
    }

    /**
     * lundump.c: luaU_undump.
     *
     * $bytes is the whole chunk. Its first byte is the ESC that the caller
     * (ldo.c: f_parser) already used to decide the chunk is binary, so it is
     * skipped unchecked, as in C. $chunkname only feeds error messages.
     *
     * @throws CompileError "<name>: bad binary format (<why>)"
     */
    public static function undump(string $bytes, string $chunkname): Proto
    {
        if ($chunkname !== '' && ($chunkname[0] === '@' || $chunkname[0] === '=')) {
            $nameForErrors = substr($chunkname, 1);
        } elseif ($chunkname !== '' && $chunkname[0] === self::LUA_SIGNATURE[0]) {
            $nameForErrors = 'binary string';
        } else {
            $nameForErrors = $chunkname;
        }
        $loadState = new self($bytes, $nameForErrors);
        $loadState->checkHeader();
        // Number of upvalues of the main closure; the runtime takes it from
        // the Proto instead (C asserts both agree).
        $loadState->loadByte();
        $mainProto = new Proto();
        $loadState->loadFunction($mainProto, null);
        return $mainProto;
    }

    // lundump.c: error
    private function error(string $why): never
    {
        throw new CompileError(sprintf('%s: bad binary format (%s)', $this->nameForErrors, $why));
    }

    // lundump.c: loadBlock
    private function loadBlock(int $size): string
    {
        if ($size > strlen($this->bytes) - $this->position) {
            $this->error('truncated chunk');
        }
        $block = substr($this->bytes, $this->position, $size);
        $this->position += $size;
        return $block;
    }

    // lundump.c: loadByte
    private function loadByte(): int
    {
        if ($this->position >= strlen($this->bytes)) {
            $this->error('truncated chunk');
        }
        return ord($this->bytes[$this->position++]);
    }

    // lundump.c: loadUnsigned ($limitShifted is C's 'limit >>= 7')
    private function loadUnsigned(int $limitShifted): int
    {
        $value = 0;
        do {
            $byte = $this->loadByte();
            if ($value >= $limitShifted) {
                $this->error('integer overflow');
            }
            $value = ($value << 7) | ($byte & 0x7f);
        } while (($byte & 0x80) === 0);
        return $value;
    }

    /**
     * lundump.c: loadSize. Values of 2^63 and above wrap negative in PHP;
     * they can never be satisfied by the remaining bytes, and every caller
     * treats them as a truncated chunk.
     */
    private function loadSize(): int
    {
        return $this->loadUnsigned(self::SIZE_LIMIT_SHIFTED);
    }

    // lundump.c: loadInt
    private function loadInt(): int
    {
        return $this->loadUnsigned(self::INT_LIMIT_SHIFTED);
    }

    // lundump.c: loadNumber
    private function loadNumber(): float
    {
        return unpack('e', $this->loadBlock(8))[1];
    }

    // lundump.c: loadInteger
    private function loadInteger(): int
    {
        return unpack('P', $this->loadBlock(8))[1];
    }

    // lundump.c: loadStringN (nullable string)
    private function loadStringN(): ?string
    {
        $size = $this->loadSize();
        if ($size === 0) {
            return null;
        }
        if ($size < 0) {
            $this->error('truncated chunk');
        }
        return $this->loadBlock($size - 1);
    }

    // lundump.c: loadString (non-nullable string)
    private function loadString(): string
    {
        $string = $this->loadStringN();
        if ($string === null) {
            $this->error('bad format for constant string');
        }
        return $string;
    }

    // lundump.c: loadCode
    private function loadCode(Proto $f): void
    {
        $instructionCount = $this->loadInt();
        $codeBytes = $this->loadBlock($instructionCount * 4);
        $f->code = $instructionCount === 0 ? [] : array_values(unpack('V*', $codeBytes));
    }

    // lundump.c: loadConstants
    private function loadConstants(Proto $f): void
    {
        $constantCount = $this->loadInt();
        $f->k = [];
        for ($i = 0; $i < $constantCount; $i++) {
            $tag = $this->loadByte();
            $f->k[$i] = match ($tag) {
                self::LUA_VNIL => null,
                self::LUA_VFALSE => false,
                self::LUA_VTRUE => true,
                self::LUA_VNUMFLT => $this->loadNumber(),
                self::LUA_VNUMINT => $this->loadInteger(),
                self::LUA_VSHRSTR, self::LUA_VLNGSTR => $this->loadString(),
                // C: lua_assert(0); a release build leaves the constant nil
                default => null,
            };
        }
    }

    // lundump.c: loadProtos
    private function loadProtos(Proto $f): void
    {
        $protoCount = $this->loadInt();
        $f->p = [];
        for ($i = 0; $i < $protoCount; $i++) {
            $childProto = new Proto();
            $f->p[$i] = $childProto;
            $this->loadFunction($childProto, $f->source);
        }
    }

    /**
     * lundump.c: loadUpvalues. Names are filled in later by loadDebug.
     */
    private function loadUpvalues(Proto $f): void
    {
        $upvalueCount = $this->loadInt();
        $f->upvalues = [];
        for ($i = 0; $i < $upvalueCount; $i++) {
            $instack = $this->loadByte();
            $idx = $this->loadByte();
            $kind = $this->loadByte();
            $f->upvalues[$i] = new UpvalDesc(null, $instack !== 0, $idx, $kind);
        }
    }

    // lundump.c: loadDebug
    private function loadDebug(Proto $f): void
    {
        $lineinfoCount = $this->loadInt();
        $lineinfoBytes = $this->loadBlock($lineinfoCount);
        $f->lineinfo = $lineinfoCount === 0 ? [] : array_values(unpack('c*', $lineinfoBytes));

        $abslineinfoCount = $this->loadInt();
        $f->abslineinfo = [];
        for ($i = 0; $i < $abslineinfoCount; $i++) {
            $pc = $this->loadInt();
            $line = $this->loadInt();
            $f->abslineinfo[$i] = new AbsLineInfo($pc, $line);
        }

        $locvarCount = $this->loadInt();
        $f->locvars = [];
        for ($i = 0; $i < $locvarCount; $i++) {
            $varname = $this->loadStringN();
            $startpc = $this->loadInt();
            $endpc = $this->loadInt();
            $f->locvars[$i] = new LocVar($varname, $startpc, $endpc);
        }

        $upvalueNameCount = $this->loadInt();
        if ($upvalueNameCount !== 0) {  // does it have debug information?
            $upvalueNameCount = count($f->upvalues);  // must be this many
        }
        for ($i = 0; $i < $upvalueNameCount; $i++) {
            $f->upvalues[$i]->name = $this->loadStringN();
        }
    }

    // lundump.c: loadFunction
    private function loadFunction(Proto $f, ?string $parentSource): void
    {
        $f->source = $this->loadStringN();
        if ($f->source === null) {  // no source in dump?
            $f->source = $parentSource;  // reuse parent's source
        }
        $f->linedefined = $this->loadInt();
        $f->lastlinedefined = $this->loadInt();
        $f->numparams = $this->loadByte();
        $f->is_vararg = $this->loadByte() !== 0;
        $f->maxstacksize = $this->loadByte();
        $this->loadCode($f);
        $this->loadConstants($f);
        $this->loadUpvalues($f);
        $this->loadProtos($f);
        $this->loadDebug($f);
    }

    // lundump.c: checkliteral
    private function checkLiteral(string $expected, string $why): void
    {
        $actual = $this->loadBlock(strlen($expected));
        if ($actual !== $expected) {
            $this->error($why);
        }
    }

    // lundump.c: fchecksize / checksize
    private function checkSize(int $expectedSize, string $typeName): void
    {
        if ($this->loadByte() !== $expectedSize) {
            $this->error(sprintf('%s size mismatch', $typeName));
        }
    }

    // lundump.c: checkHeader
    private function checkHeader(): void
    {
        // skip 1st char (already read and checked)
        $this->checkLiteral(substr(self::LUA_SIGNATURE, 1), 'not a binary chunk');
        if ($this->loadByte() !== self::LUAC_VERSION) {
            $this->error('version mismatch');
        }
        if ($this->loadByte() !== self::LUAC_FORMAT) {
            $this->error('format mismatch');
        }
        $this->checkLiteral(self::LUAC_DATA, 'corrupted chunk');
        $this->checkSize(4, 'Instruction');
        $this->checkSize(8, 'lua_Integer');
        $this->checkSize(8, 'lua_Number');
        if ($this->loadInteger() !== self::LUAC_INT) {
            $this->error('integer format mismatch');
        }
        if ($this->loadNumber() !== self::LUAC_NUM) {
            $this->error('float format mismatch');
        }
    }
}
