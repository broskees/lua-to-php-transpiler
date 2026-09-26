<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Dynamic structures used by the parser; mirrors C 'Dyndata' in lparser.h.
 * For the label lists the C 'n' counts are the array lengths. The active
 * variable list keeps its C count separately: lparser.c reads entries just
 * past it (movegotosout after removevars) and new_localvar reuses them.
 *
 * @internal
 */
final class Dyndata
{
    /** @var list<Vardesc> list of all active local variables (and stale ones past the count) */
    public array $actvar = [];

    /** C 'actvar.n' */
    public int $actvarCount = 0;

    /** @var list<Labeldesc> list of pending gotos */
    public array $gt = [];

    /** @var list<Labeldesc> list of active labels */
    public array $label = [];
}
