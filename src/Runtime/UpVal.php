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
    /**
     * the value; while the upvalue is open, a PHP reference to the register
     * it lives in (Upvalues::find makes it one, Upvalues::closeUpvalues
     * breaks it). The only property: an UpVal takes 56 bytes (an open one
     * 32 more for the reference).
     */
    public mixed $v = null;

    /**
     * Freeing a long chain of closures, each capturing the next, must not
     * recurse: see Teardown. (A LuaClosure refers to more Lua values only
     * through its UpVals.) An open upvalue is freed only with its frame
     * (CallInfo::$openupval holds it until it is closed), whose registers
     * hold the same value: queueing it only delays that value's release.
     */
    public function __destruct()
    {
        if (!\is_object($this->v)) {
            return;
        }
        if (Teardown::$releasing) {
            Teardown::$pending[] = $this->v;
            return;
        }
        Teardown::$releasing = true;
        unset($this->v);  // (not "= null", which would write through an open upvalue's reference)
        Teardown::release();
    }

    /** a closed upvalue holding $value (lfunc.c: luaF_initupvals style) */
    public static function closed(mixed $value): self
    {
        $upvalue = new self();
        $upvalue->v = $value;
        return $upvalue;
    }
}
