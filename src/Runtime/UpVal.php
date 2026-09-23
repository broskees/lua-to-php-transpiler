<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * An upvalue (C: UpVal in lobject.h, managed by lfunc.c).
 *
 * While open, $v is a PHP reference to the register it lives in (an element
 * of the defining frame's $R array), so reads and writes through the
 * upvalue and through the register see the same variable. Closing it
 * (Upvalues::close) copies the value into $v itself and breaks the
 * reference. All closures that capture the same variable share one UpVal
 * object (lfunc.c: luaF_findupval), so object identity is the upvalue's
 * identity (debug.upvalueid).
 */
final class UpVal
{
    /** the value (a reference to the register while open) */
    public mixed $v = null;

    /** true while $v refers to a live register */
    public bool $isOpen = false;

    /** a closed upvalue holding $value (lfunc.c: luaF_initupvals style) */
    public static function closed(mixed $value): self
    {
        $upvalue = new self();
        $upvalue->v = $value;
        return $upvalue;
    }
}
