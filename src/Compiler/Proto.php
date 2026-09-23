<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Function prototype; mirrors C 'Proto' in lobject.h, field for field.
 *
 * The C 'size*' fields are the counts of the corresponding arrays. Where C
 * holds a NULL TString (stripped debug info), the PHP field holds null.
 * Constants ('k') are PHP null|bool|int|float|string; strings are raw bytes.
 */
final class Proto
{
    /** @var ?string chunk name ("@file", "=stdin", source text...); null when stripped */
    public ?string $source = null;

    public int $linedefined = 0;

    public int $lastlinedefined = 0;

    /** number of fixed (named) parameters */
    public int $numparams = 0;

    public bool $is_vararg = false;

    /** number of registers needed by this function */
    public int $maxstacksize = 0;

    /** @var list<int> instructions, each an unsigned 32-bit value (see OpCodes) */
    public array $code = [];

    /** @var list<null|bool|int|float|string> constants used by the function */
    public array $k = [];

    /** @var list<Proto> functions defined inside the function */
    public array $p = [];

    /** @var list<UpvalDesc> upvalue information */
    public array $upvalues = [];

    /** @var list<int> per-instruction line deltas, signed bytes (-128 is ABSLINEINFO) */
    public array $lineinfo = [];

    /** @var list<AbsLineInfo> */
    public array $abslineinfo = [];

    /** @var list<LocVar> information about local variables */
    public array $locvars = [];
}
