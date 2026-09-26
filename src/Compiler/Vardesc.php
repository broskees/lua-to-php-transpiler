<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

/**
 * Description of an active local variable; mirrors C 'Vardesc' in
 * lparser.h. 'k' is the value of a compile-time constant (kind RDKCTC).
 *
 * @internal
 */
final class Vardesc
{
    // lparser.h: kinds of variables
    public const VDKREG = 0;      // regular
    public const RDKCONST = 1;    // constant
    public const RDKTOCLOSE = 2;  // to-be-closed
    public const RDKCTC = 3;      // compile-time constant

    public int $kind = self::VDKREG;

    /** register holding the variable */
    public int $ridx = 0;

    /** index of the variable in the Proto's 'locvars' array */
    public int $pidx = 0;

    /** constant value (if it is a compile-time constant) */
    public null|bool|int|float|string $k = null;

    public function __construct(
        /** variable name */
        public string $name,
    ) {
    }
}
