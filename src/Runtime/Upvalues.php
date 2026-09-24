<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Port of lfunc.c: open/closed upvalues and to-be-closed variables.
 *
 * C keeps one list of open upvalues per thread, ordered by stack level;
 * here each CallInfo keeps the open upvalues of its own frame by register
 * ($ci->openupval) and its pending to-be-closed registers ($ci->tbclist).
 * Closing "everything at or above a level" of a frame is the same thing.
 */
final class Upvalues
{
    /**
     * lfunc.c: luaF_findupval: the open upvalue for register $register of
     * the frame in $ci, created if needed. All closures capturing that
     * register share it.
     */
    public static function find(CallInfo $ci, int $register): UpVal
    {
        $upvalue = $ci->openupval[$register] ?? null;
        if ($upvalue !== null) {
            return $upvalue;
        }
        $upvalue = new UpVal();
        $upvalue->v = &$ci->R[$register];  // current value lives in the register
        $ci->openupval[$register] = $upvalue;
        return $upvalue;
    }

    /** lfunc.c: luaF_closeupval: close the upvalues of registers >= $level */
    public static function closeUpvalues(CallInfo $ci, int $level): void
    {
        foreach ($ci->openupval as $register => $upvalue) {
            if ($register < $level) {
                continue;
            }
            $value = $upvalue->v;
            unset($upvalue->v);  // break the reference to the register
            $upvalue->v = $value;  // now current value lives here
            unset($ci->openupval[$register]);
        }
    }

    /**
     * lfunc.c: luaF_newtbcupval: register $register of the running frame
     * holds a new to-be-closed variable.
     */
    public static function newTbc(Coroutine $L, CallInfo $ci, int $register): void
    {
        $value = $ci->R[$register];
        if ($value === null || $value === false) {
            return;  // false doesn't need to be closed
        }
        // lfunc.c: checkclosemth
        if (MetaMethods::getByObject($L, $value, MetaMethods::TM_CLOSE) === null) {  // no metamethod?
            $variableName = DebugInfo::findLocal($L, $ci, $register + 1) ?? '?';
            DebugInfo::runError($L, "variable '" . DebugInfo::cString($variableName) . "' got a non-closable value");
        }
        $ci->tbclist[] = $register;
    }

    /**
     * lfunc.c: luaF_close with status CLOSEKTOP (normal block exit or
     * return): close upvalues of registers >= $level, then call the
     * '__close' methods of the variables there, last created first, with
     * a nil error.
     */
    public static function close(Coroutine $L, CallInfo $ci, int $level): void
    {
        self::closeUpvalues($ci, $level);
        while ($ci->tbclist !== [] && $ci->tbclist[\count($ci->tbclist) - 1] >= $level) {
            $register = array_pop($ci->tbclist);  // remove it from list
            self::callCloseMethod($L, $ci->R[$register], null, true);  // close variable
        }
    }

    /**
     * lfunc.c: callclosemethod: call the '__close' metamethod of $object
     * with $error ($yieldable: luaD_call, else luaD_callnoyield).
     */
    public static function callCloseMethod(Coroutine $L, mixed $object, mixed $error, bool $yieldable): void
    {
        $tm = MetaMethods::getByObject($L, $object, MetaMethods::TM_CLOSE);
        if ($yieldable) {
            Calls::callk($L, $tm, [$object, $error]);
        } else {
            Calls::callNoYield($L, $tm, [$object, $error]);
        }
    }
}
