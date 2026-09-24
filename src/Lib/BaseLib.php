<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Gc\Collector;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\MetaMethods;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\StringToNumber;
use LuaPhp\Runtime\Vm;

/**
 * Port of lbaselib.c: the basic library.
 *
 * Every function has the native signature (Coroutine $L, array $args):
 * array (see NativeFunction).
 */
final class BaseLib
{
    // lbaselib.c: SPACECHARS
    private const SPACECHARS = " \f\n\r\t\v";

    // lbaselib.c: luaopen_base
    public static function open(Coroutine $L): LuaTable
    {
        $globals = $L->globalState->globals;
        $next = new NativeFunction('next', self::next(...));
        $ipairsIterator = new NativeFunction('ipairs_aux', self::ipairsAux(...));
        Auxiliary::setFunctions($globals, [
            'assert' => self::assert(...),
            'collectgarbage' => self::collectgarbage(...),
            'dofile' => self::dofile(...),
            'error' => self::error(...),
            'getmetatable' => self::getmetatable(...),
            'ipairs' => static fn (Coroutine $L, array $args): array => self::ipairs($L, $args, $ipairsIterator),
            'loadfile' => self::loadfile(...),
            'load' => self::load(...),
            'pairs' => static fn (Coroutine $L, array $args): array => self::pairs($L, $args, $next),
            'pcall' => self::pcall(...),
            'print' => self::print(...),
            'warn' => self::warn(...),
            'rawequal' => self::rawequal(...),
            'rawlen' => self::rawlen(...),
            'rawget' => self::rawget(...),
            'rawset' => self::rawset(...),
            'select' => self::select(...),
            'setmetatable' => self::setmetatable(...),
            'tonumber' => self::tonumber(...),
            'tostring' => self::tostring(...),
            'type' => self::type(...),
            'xpcall' => self::xpcall(...),
        ]);
        $globals->hash['next'] = $next;
        $globals->hash[Lua::LUA_GNAME] = $globals;  // set global _G
        $globals->hash['_VERSION'] = Lua::LUA_VERSION;  // set global _VERSION
        return $globals;
    }

    // lbaselib.c: luaB_print
    private static function print(Coroutine $L, array $args): array
    {
        $count = \count($args);
        for ($i = 0; $i < $count; $i++) {
            $text = Auxiliary::toLString($L, $args[$i]);  // convert it to string
            echo $i > 0 ? "\t" . $text : $text;
        }
        echo "\n";
        return [];
    }

    // lbaselib.c: luaB_warn
    private static function warn(Coroutine $L, array $args): array
    {
        $count = \count($args);
        Auxiliary::checkString($L, $args, 1);  // at least one argument
        for ($i = 2; $i <= $count; $i++) {
            Auxiliary::checkString($L, $args, $i);  // make sure all arguments are strings
        }
        for ($i = 1; $i < $count; $i++) {  // compose warning
            self::warning($L, LuaObject::toStringCoerced($args[$i - 1]), true);
        }
        self::warning($L, LuaObject::toStringCoerced($args[$count - 1]), false);  // close warning
        return [];
    }

    /**
     * lapi.c: lua_warning with lauxlib.c's warning functions (warnfoff,
     * warnfon, warnfcont): control messages "@on"/"@off"; warnings go to
     * stderr as "Lua warning: <message>\n".
     */
    public static function warning(Coroutine $L, string $message, bool $toContinue): void
    {
        $G = $L->globalState;
        if ($G->warningContinues) {  // warnfcont
            self::continueWarning($L, $message, $toContinue);
            return;
        }
        // lauxlib.c: checkcontrol
        if (!$toContinue && str_starts_with($message, '@')) {
            $control = DebugInfo::cString(substr($message, 1));
            if ($control === 'off') {
                $G->warningsOn = false;
            } elseif ($control === 'on') {
                $G->warningsOn = true;
            }
            return;  // it was a control message
        }
        if (!$G->warningsOn) {
            return;  // warnfoff
        }
        fwrite(STDERR, 'Lua warning: ');  // start a new warning
        self::continueWarning($L, $message, $toContinue);
    }

    // lauxlib.c: warnfcont
    private static function continueWarning(Coroutine $L, string $message, bool $toContinue): void
    {
        fwrite(STDERR, DebugInfo::cString($message));  // write message
        if ($toContinue) {  // not the last part?
            $L->globalState->warningContinues = true;  // to be continued
        } else {  // last part
            fwrite(STDERR, "\n");  // finish message with end-of-line
            $L->globalState->warningContinues = false;
        }
    }

    // lbaselib.c: b_str2int
    private static function stringToIntegerInBase(string $s, int $base): ?int
    {
        $position = strspn($s, self::SPACECHARS);  // skip initial spaces
        $negative = false;
        if (($s[$position] ?? '') === '-') {  // handle sign
            $position++;
            $negative = true;
        } elseif (($s[$position] ?? '') === '+') {
            $position++;
        }
        if (!ctype_alnum($s[$position] ?? '')) {  // no digit?
            return null;
        }
        $number = 0;
        do {
            $character = $s[$position];
            $digit = ctype_digit($character) ? \ord($character) - \ord('0') : \ord(strtoupper($character)) - \ord('A') + 10;
            if ($digit >= $base) {
                return null;  // invalid numeral
            }
            $number = Vm::addWrap(Vm::mulWrap($number, $base), $digit);
            $position++;
        } while (ctype_alnum($s[$position] ?? ''));
        $position += strspn($s, self::SPACECHARS, $position);  // skip trailing spaces
        if ($position !== \strlen($s)) {
            return null;
        }
        return $negative ? Vm::negWrap($number) : $number;
    }

    // lbaselib.c: luaB_tonumber
    private static function tonumber(Coroutine $L, array $args): array
    {
        if (($args[1] ?? null) === null) {  // standard conversion?
            $value = $args[0] ?? null;
            if (\is_int($value) || \is_float($value)) {  // already a number?
                return [$value];
            }
            if (\is_string($value)) {
                $number = StringToNumber::convert($value);
                if ($number !== null) {
                    return [$number];  // successful conversion to number
                }
            }
            Auxiliary::checkAny($L, $args, 1);  // (but there must be some parameter)
            return [null];  // not a number
        }
        $base = Auxiliary::checkInteger($L, $args, 2);
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TSTRING);  // no numbers as strings
        Auxiliary::argCheck($L, 2 <= $base && $base <= 36, 2, 'base out of range');
        return [self::stringToIntegerInBase($args[0], $base)];
    }

    // lbaselib.c: luaB_error
    private static function error(Coroutine $L, array $args): array
    {
        $level = Auxiliary::optInteger($L, $args, 2, 1);
        $value = $args[0] ?? null;
        if (\is_string($value) && $level > 0) {
            $value = Auxiliary::where($L, $level) . $value;  // add extra information
        }
        throw new LuaError($value);
    }

    // lbaselib.c: luaB_getmetatable
    private static function getmetatable(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 1);
        $metatable = MetaMethods::metatableOf($L, $args[0]);
        if ($metatable === null) {
            return [null];  // no metatable
        }
        // returns either __metatable field (if present) or metatable
        return [$metatable->hash['__metatable'] ?? $metatable];
    }

    // lbaselib.c: luaB_setmetatable
    private static function setmetatable(Coroutine $L, array $args): array
    {
        $metatableType = Auxiliary::argumentType($args, 2);
        $table = Auxiliary::checkTable($L, $args, 1);
        Auxiliary::argExpected($L, $metatableType === Lua::LUA_TNIL || $metatableType === Lua::LUA_TTABLE, $args, 2, 'nil or table');
        if (Auxiliary::getMetafield($L, $table, '__metatable') !== null) {
            Auxiliary::error($L, 'cannot change a protected metatable');
        }
        Collector::setMetatable($L, $table, $args[1] ?? null);
        return [$table];
    }

    // lbaselib.c: luaB_rawequal
    private static function rawequal(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 1);
        Auxiliary::checkAny($L, $args, 2);
        return [Vm::rawEquals($args[0], $args[1])];
    }

    // lbaselib.c: luaB_rawlen
    private static function rawlen(Coroutine $L, array $args): array
    {
        $value = $args[0] ?? null;
        Auxiliary::argExpected($L, $value instanceof LuaTable || \is_string($value), $args, 1, 'table or string');
        return [\is_string($value) ? \strlen($value) : $value->length()];
    }

    // lbaselib.c: luaB_rawget
    private static function rawget(Coroutine $L, array $args): array
    {
        $table = Auxiliary::checkTable($L, $args, 1);
        Auxiliary::checkAny($L, $args, 2);
        return [$table->get($args[1])];
    }

    // lbaselib.c: luaB_rawset
    private static function rawset(Coroutine $L, array $args): array
    {
        $table = Auxiliary::checkTable($L, $args, 1);
        Auxiliary::checkAny($L, $args, 2);
        Auxiliary::checkAny($L, $args, 3);
        Vm::rawSet($L, $table, $args[1], $args[2]);
        return [$table];
    }

    /**
     * lbaselib.c: luaB_collectgarbage with lapi.c: lua_gc. Inside a
     * finalizer the collector is stopped and every option returns fail.
     * Parameters are stored as C stores them (lgc.h: setgcparam keeps
     * value / 4 in a byte; C int arguments).
     */
    private static function collectgarbage(Coroutine $L, array $args): array
    {
        $options = ['stop', 'restart', 'collect', 'count', 'step', 'setpause', 'setstepmul', 'isrunning', 'generational', 'incremental'];
        $option = $options[Auxiliary::checkOption($L, $args, 1, 'collect', $options)];
        $G = $L->globalState;
        $cInt = static fn (int $index): int => ((Auxiliary::optInteger($L, $args, $index, 0) & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
        $toByte = static fn (int $value): int => $value & 0xFF;  // C: assignment to lu_byte
        $pushMode = static fn (int $previousMode): array => [$previousMode === Collector::KGC_INC ? 'incremental' : 'generational'];
        switch ($option) {
            case 'count':
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                return [Collector::totalBytes($G) / 1024.0];  // always a float, like C's lua_Number
            case 'step':
                $step = $cInt(2);
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                return [Collector::stepCommand($L, $step)];
            case 'setpause':
            case 'setstepmul':
                $value = $cInt(2);
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                if ($option === 'setpause') {
                    $previous = $G->gcpause * 4;
                    $G->gcpause = $toByte(intdiv($value, 4));
                } else {
                    $previous = $G->gcstepmul * 4;
                    $G->gcstepmul = $toByte(intdiv($value, 4));
                }
                return [$previous];
            case 'isrunning':
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                return [$G->gcstp === 0];
            case 'generational':
                $minormul = $cInt(2);
                $majormul = $cInt(3);
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                $previousMode = $G->gckind;
                if ($minormul !== 0) {
                    $G->genminormul = $toByte($minormul);
                }
                if ($majormul !== 0) {
                    $G->genmajormul = $toByte(intdiv($majormul, 4));
                }
                Collector::changeMode($L, Collector::KGC_GEN);
                return $pushMode($previousMode);
            case 'incremental':
                $pause = $cInt(2);
                $stepmul = $cInt(3);
                $stepsize = $cInt(4);
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                $previousMode = $G->gckind;
                if ($pause !== 0) {
                    $G->gcpause = $toByte(intdiv($pause, 4));
                }
                if ($stepmul !== 0) {
                    $G->gcstepmul = $toByte(intdiv($stepmul, 4));
                }
                if ($stepsize !== 0) {
                    $G->gcstepsize = $toByte($stepsize);
                }
                Collector::changeMode($L, Collector::KGC_INC);
                return $pushMode($previousMode);
            case 'stop':
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                $G->gcstp = Collector::GCSTPUSR;  // stopped by the user
                return [0];
            case 'restart':
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                Collector::restart($G);
                return [0];
            default:  // collect
                if ($G->gcstp & Collector::GCSTPGC) {
                    return [null];
                }
                Collector::fullGc($L);
                return [0];
        }
    }

    // lbaselib.c: luaB_type
    private static function type(Coroutine $L, array $args): array
    {
        Auxiliary::argCheck($L, $args !== [], 1, 'value expected');
        return [LuaObject::typeName($args[0])];
    }

    // lbaselib.c: luaB_next
    private static function next(Coroutine $L, array $args): array
    {
        $table = Auxiliary::checkTable($L, $args, 1);
        $entry = $table->next($args[1] ?? null);
        if ($entry === false) {
            DebugInfo::runError($L, "invalid key to 'next'");  // key not found
        }
        return $entry ?? [null];
    }

    // lbaselib.c: luaB_pairs
    private static function pairs(Coroutine $L, array $args, NativeFunction $next): array
    {
        Auxiliary::checkAny($L, $args, 1);
        $metamethod = Auxiliary::getMetafield($L, $args[0], '__pairs');
        if ($metamethod === null) {  // no metamethod?
            return [$next, $args[0], null];  // generator, state, and initial value
        }
        $results = Calls::callk($L, $metamethod, [$args[0]]);  // get 3 values from metamethod (lua_callk: may yield)
        return [$results[0] ?? null, $results[1] ?? null, $results[2] ?? null];
    }

    // lbaselib.c: ipairsaux
    private static function ipairsAux(Coroutine $L, array $args): array
    {
        $index = Vm::addWrap(Auxiliary::checkInteger($L, $args, 2), 1);
        $table = $args[0] ?? null;
        if ($table instanceof LuaTable) {
            $value = $table->arr[$index] ?? null;
            if ($value === null && $table->metatable !== null) {
                $value = Vm::finishGet($L, $table, $index, DebugInfo::NO_SLOT);
            }
        } else {
            $value = Vm::getTable($L, $table, $index);
        }
        return $value === null ? [null] : [$index, $value];
    }

    // lbaselib.c: luaB_ipairs
    private static function ipairs(Coroutine $L, array $args, NativeFunction $iterator): array
    {
        Auxiliary::checkAny($L, $args, 1);
        return [$iterator, $args[0], 0];  // iteration function, state, initial value
    }

    /**
     * lbaselib.c: load_aux: the loaded function (with 'env' as its first
     * upvalue, if given) or fail plus the error message.
     */
    private static function loadResult(callable $loader, array $args, int $environmentArgument): array
    {
        try {
            $function = $loader();
        } catch (LuaError $error) {
            return [null, $error->value];  // return fail plus error message
        }
        if ($environmentArgument <= \count($args) && $function->upvals !== []) {  // 'env' parameter?
            $function->upvals[0]->v = $args[$environmentArgument - 1];  // set it as 1st upvalue
        }
        return [$function];
    }

    // lbaselib.c: luaB_loadfile
    private static function loadfile(Coroutine $L, array $args): array
    {
        $filename = Auxiliary::optString($L, $args, 1, null);
        $mode = Auxiliary::optString($L, $args, 2, null);
        return self::loadResult(static fn (): LuaClosure => ChunkLoader::loadFile($L, $filename, $mode), $args, 3);
    }

    // lbaselib.c: luaB_load
    private static function load(Coroutine $L, array $args): array
    {
        $chunk = LuaObject::toStringCoerced($args[0] ?? null);
        $mode = Auxiliary::optString($L, $args, 3, 'bt');
        if ($chunk !== null) {  // loading a string?
            $chunkname = Auxiliary::optString($L, $args, 2, $chunk);
            return self::loadResult(static fn (): LuaClosure => ChunkLoader::load($L, $chunk, $chunkname, $mode), $args, 4);
        }
        // loading from a reader function
        $chunkname = Auxiliary::optString($L, $args, 2, '=(load)');
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TFUNCTION);
        $reader = $args[0];
        // ldo.c: luaD_protectedparser runs the reader in protected mode,
        // inside load's own frame, with the current message handler
        $readAll = static function () use ($L, $reader): string {
            $pieces = '';
            while (true) {  // lbaselib.c: generic_reader
                $results = Calls::call($L, $reader, []);  // call it
                $piece = $results[0] ?? null;
                if ($piece === null) {
                    break;
                }
                $piece = LuaObject::toStringCoerced($piece);
                if ($piece === null) {
                    Auxiliary::error($L, 'reader function must return a string');
                }
                if ($piece === '') {
                    break;
                }
                $pieces .= $piece;
            }
            return $pieces;
        };
        return self::loadResult(static function () use ($L, $readAll, $chunkname, $mode): LuaClosure {
            [$status, $result] = Calls::protectedRun($L, $readAll, $L->errfunc);
            if ($status !== Lua::LUA_OK) {
                throw new LuaError($result, $status);
            }
            return ChunkLoader::load($L, $result, $chunkname, $mode);
        }, $args, 4);
    }

    // lbaselib.c: luaB_dofile
    private static function dofile(Coroutine $L, array $args): array
    {
        $filename = Auxiliary::optString($L, $args, 1, null);
        $function = ChunkLoader::loadFile($L, $filename, null);
        return Calls::callk($L, $function, []);  // lua_callk with dofilecont: may yield
    }

    // lbaselib.c: luaB_assert
    private static function assert(Coroutine $L, array $args): array
    {
        $condition = $args[0] ?? null;
        if ($condition !== null && $condition !== false) {  // condition is true?
            return $args;  // return all arguments
        }
        Auxiliary::checkAny($L, $args, 1);  // there must be a condition
        // leave only message (default if no other one)
        $message = \count($args) >= 2 ? $args[1] : 'assertion failed!';
        return self::error($L, [$message]);  // call 'error'
    }

    // lbaselib.c: luaB_select
    private static function select(Coroutine $L, array $args): array
    {
        $count = \count($args);
        $first = $args[0] ?? null;
        if (\is_string($first) && str_starts_with($first, '#')) {
            return [$count - 1];
        }
        $index = Auxiliary::checkInteger($L, $args, 1);
        if ($index < 0) {
            $index = $count + $index;
        } elseif ($index > $count) {
            $index = $count;
        }
        Auxiliary::argCheck($L, 1 <= $index, 1, 'index out of range');
        return \array_slice($args, $index);
    }

    // lbaselib.c: luaB_pcall
    private static function pcall(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 1);
        $function = array_shift($args);
        [$status, $result] = Calls::protectedCallk($L, $function, $args);
        if ($status === Lua::LUA_OK) {
            array_unshift($result, true);
            return $result;
        }
        return [false, $result];  // lbaselib.c: finishpcall
    }

    // lbaselib.c: luaB_xpcall
    private static function xpcall(Coroutine $L, array $args): array
    {
        Auxiliary::checkType($L, $args, 2, Lua::LUA_TFUNCTION);  // check error function
        $function = $args[0];
        $handler = $args[1];
        [$status, $result] = Calls::protectedCallk($L, $function, \array_slice($args, 2), $handler);
        if ($status === Lua::LUA_OK) {
            array_unshift($result, true);
            return $result;
        }
        return [false, $result];
    }

    // lbaselib.c: luaB_tostring
    private static function tostring(Coroutine $L, array $args): array
    {
        Auxiliary::checkAny($L, $args, 1);
        return [Auxiliary::toLString($L, $args[0])];
    }
}
