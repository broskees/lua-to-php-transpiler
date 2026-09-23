<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Port of lauxlib.c, the helpers libraries use: argument checks with Lua's
 * exact messages, luaL_error/luaL_where, luaL_tolstring, luaL_traceback.
 *
 * Native functions receive their arguments as a 0-based PHP list; the
 * helpers take Lua's 1-based argument number ('arg'), so
 * checkInteger($L, $args, 2) checks $args[1]. An argument beyond the end
 * of the list is "none" (C: LUA_TNONE); an existing null is nil.
 */
final class Auxiliary
{
    // lauxlib.c: LEVELS1 / LEVELS2
    private const LEVELS1 = 10;
    private const LEVELS2 = 11;

    /** lapi.c: lua_type for argument 'arg' (Lua::LUA_TNONE if absent) */
    public static function argumentType(array $args, int $arg): int
    {
        if ($arg > \count($args)) {
            return Lua::LUA_TNONE;
        }
        return LuaObject::type($args[$arg - 1]);
    }

    /** lauxlib.h: luaL_typename for argument 'arg' */
    public static function argumentTypeName(array $args, int $arg): string
    {
        return Lua::TYPE_NAMES[self::argumentType($args, $arg) + 1];
    }

    /** lauxlib.c: luaL_argerror */
    public static function argError(Coroutine $L, int $arg, string $extramsg): never
    {
        $ci = DebugInfo::getStack($L, 0);
        if ($ci === null) {  // no stack frame?
            self::error($L, "bad argument #$arg ($extramsg)");
        }
        $info = DebugInfo::getInfo($L, 'n', $ci);
        $name = $info['name'];
        if ($info['namewhat'] === 'method') {
            $arg--;  // do not count 'self'
            if ($arg === 0) {  // error is in the self argument itself?
                self::error($L, "calling '" . DebugInfo::cString($name) . "' on bad self ($extramsg)");
            }
        }
        if ($name === null) {
            $name = self::globalFunctionName($L, $ci) ?? '?';
        }
        self::error($L, "bad argument #$arg to '" . DebugInfo::cString($name) . "' ($extramsg)");
    }

    /** lauxlib.c: luaL_typeerror */
    public static function typeError(Coroutine $L, array $args, int $arg, string $expectedTypeName): never
    {
        $value = $args[$arg - 1] ?? null;
        $metafield = self::getMetafield($L, $value, '__name');
        if (\is_string($metafield)) {
            $actualTypeName = $metafield;  // use the given type name
        } elseif ($value instanceof LightUserdata) {
            $actualTypeName = 'light userdata';  // special name for messages
        } else {
            $actualTypeName = self::argumentTypeName($args, $arg);  // standard name
        }
        self::argError($L, $arg, $expectedTypeName . ' expected, got ' . DebugInfo::cString($actualTypeName));
    }

    /** lauxlib.h: luaL_argcheck */
    public static function argCheck(Coroutine $L, bool $condition, int $arg, string $extramsg): void
    {
        if (!$condition) {
            self::argError($L, $arg, $extramsg);
        }
    }

    /** lauxlib.h: luaL_argexpected */
    public static function argExpected(Coroutine $L, bool $condition, array $args, int $arg, string $typeName): void
    {
        if (!$condition) {
            self::typeError($L, $args, $arg, $typeName);
        }
    }

    // lauxlib.c: luaL_checkany
    public static function checkAny(Coroutine $L, array $args, int $arg): void
    {
        if ($arg > \count($args)) {
            self::argError($L, $arg, 'value expected');
        }
    }

    // lauxlib.c: luaL_checktype
    public static function checkType(Coroutine $L, array $args, int $arg, int $type): void
    {
        if (self::argumentType($args, $arg) !== $type) {
            self::typeError($L, $args, $arg, Lua::TYPE_NAMES[$type + 1]);  // tag_error
        }
    }

    /** lauxlib.c: luaL_checktype(L, arg, LUA_TTABLE) returning the table */
    public static function checkTable(Coroutine $L, array $args, int $arg): LuaTable
    {
        $value = $args[$arg - 1] ?? null;
        if (!($value instanceof LuaTable)) {
            self::typeError($L, $args, $arg, 'table');
        }
        return $value;
    }

    /** lauxlib.c: luaL_checkinteger (lua_tointegerx: strings are coerced, floats must be integral) */
    public static function checkInteger(Coroutine $L, array $args, int $arg): int
    {
        $value = $args[$arg - 1] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        $integer = Vm::toInteger($value);
        if ($integer === null) {
            // lauxlib.c: interror
            if (Vm::toNumber($value) !== null) {
                self::argError($L, $arg, 'number has no integer representation');
            }
            self::typeError($L, $args, $arg, 'number');
        }
        return $integer;
    }

    // lauxlib.c: luaL_optinteger
    public static function optInteger(Coroutine $L, array $args, int $arg, int $default): int
    {
        if (($args[$arg - 1] ?? null) === null) {
            return $default;
        }
        return self::checkInteger($L, $args, $arg);
    }

    /**
     * lauxlib.c: luaL_checknumber, keeping the integer/float subtype of the
     * converted value (C's version returns it as a lua_Number).
     */
    public static function checkNumber(Coroutine $L, array $args, int $arg): int|float
    {
        $number = Vm::toNumber($args[$arg - 1] ?? null);
        if ($number === null) {
            self::typeError($L, $args, $arg, 'number');
        }
        return $number;
    }

    // lauxlib.c: luaL_optnumber
    public static function optNumber(Coroutine $L, array $args, int $arg, int|float $default): int|float
    {
        if (($args[$arg - 1] ?? null) === null) {
            return $default;
        }
        return self::checkNumber($L, $args, $arg);
    }

    /** lauxlib.c: luaL_checklstring (numbers are converted to strings) */
    public static function checkString(Coroutine $L, array $args, int $arg): string
    {
        $string = LuaObject::toStringCoerced($args[$arg - 1] ?? null);
        if ($string === null) {
            self::typeError($L, $args, $arg, 'string');
        }
        return $string;
    }

    // lauxlib.c: luaL_optlstring
    public static function optString(Coroutine $L, array $args, int $arg, ?string $default): ?string
    {
        if (($args[$arg - 1] ?? null) === null) {
            return $default;
        }
        return self::checkString($L, $args, $arg);
    }

    /**
     * lauxlib.c: luaL_checkoption: index of the argument (or $default) in
     * $options.
     *
     * @param list<string> $options
     */
    public static function checkOption(Coroutine $L, array $args, int $arg, ?string $default, array $options): int
    {
        $name = $default !== null ? self::optString($L, $args, $arg, $default) : self::checkString($L, $args, $arg);
        $index = array_search($name, $options, true);
        if ($index !== false) {
            return $index;
        }
        self::argError($L, $arg, "invalid option '" . DebugInfo::cString($name) . "'");
    }

    /** lauxlib.c: luaL_checkstack */
    public static function checkStack(Coroutine $L, int $space, ?string $message): void
    {
        if (!Calls::checkStack($L, $space)) {
            if ($message !== null) {
                self::error($L, "stack overflow ($message)");
            }
            self::error($L, 'stack overflow');
        }
    }

    /** lauxlib.c: luaL_where: "short_src:line: " of the function at $level, or "" */
    public static function where(Coroutine $L, int $level): string
    {
        $ci = DebugInfo::getStack($L, $level);
        if ($ci !== null) {  // check function at level
            $info = DebugInfo::getInfo($L, 'Sl', $ci);
            if ($info['currentline'] > 0) {  // is there info?
                return $info['short_src'] . ':' . $info['currentline'] . ': ';
            }
        }
        return '';  // else, no information available...
    }

    /** lauxlib.c: luaL_error: raise $message with the position of the caller of the running function */
    public static function error(Coroutine $L, string $message): never
    {
        throw new LuaError(self::where($L, 1) . $message);
    }

    /** lauxlib.c: luaL_getmetafield: raw metatable field, or null */
    public static function getMetafield(Coroutine $L, mixed $value, string $event): mixed
    {
        $metatable = MetaMethods::metatableOf($L, $value);
        if ($metatable === null) {
            return null;
        }
        return $metatable->hash[$event] ?? null;
    }

    /**
     * lauxlib.c: luaL_callmeta: call metafield $event of $value with $value
     * as argument. Returns [true, first result] or [false, null] if there
     * is no such metafield.
     *
     * @return array{bool, mixed}
     */
    public static function callMeta(Coroutine $L, mixed $value, string $event): array
    {
        $metafield = self::getMetafield($L, $value, $event);
        if ($metafield === null) {
            return [false, null];
        }
        $results = Calls::call($L, $metafield, [$value]);
        return [true, $results[0] ?? null];
    }

    /** lauxlib.c: luaL_len (lua_len honors '__len') */
    public static function len(Coroutine $L, mixed $value): int
    {
        if ($value instanceof LuaTable && $value->metatable === null) {
            return $value->length();
        }
        if (\is_string($value)) {
            return \strlen($value);
        }
        $integer = Vm::toInteger(Vm::objectLength($L, $value, DebugInfo::NO_SLOT));  // lua_tointegerx
        if ($integer === null) {
            self::error($L, 'object length is not an integer');
        }
        return $integer;
    }

    /** lauxlib.c: luaL_tolstring */
    public static function toLString(Coroutine $L, mixed $value): string
    {
        [$called, $result] = self::callMeta($L, $value, '__tostring');
        if ($called) {  // metafield?
            if (!\is_string($result)) {
                if (\is_int($result) || \is_float($result)) {
                    return LuaObject::numberToString($result);  // lua_isstring accepts numbers
                }
                self::error($L, "'__tostring' must return a string");
            }
            return $result;
        }
        if (\is_int($value) || \is_float($value)) {
            return LuaObject::numberToString($value);
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'nil';
        }
        $name = self::getMetafield($L, $value, '__name');  // try name
        $kind = \is_string($name) ? $name : LuaObject::typeName($value);
        return DebugInfo::cString($kind) . ': ' . LuaObject::address($value);
    }

    /**
     * lauxlib.c: pushglobalfuncname: "modname.field" for a function found
     * in package.loaded (two levels deep), without a leading "_G.".
     */
    public static function globalFunctionName(Coroutine $L, CallInfo $ci): ?string
    {
        $function = $ci->func;
        $loaded = $L->globalState->registry->hash[Lua::LUA_LOADED_TABLE] ?? null;
        $name = self::findField($function, $loaded, 2);
        if ($name === null) {
            return null;
        }
        if (str_starts_with($name, Lua::LUA_GNAME . '.')) {  // name start with '_G.'?
            return substr($name, 3);  // push name without prefix
        }
        return $name;
    }

    // lauxlib.c: findfield
    private static function findField(mixed $object, mixed $table, int $level): ?string
    {
        if ($level === 0 || !($table instanceof LuaTable)) {
            return null;  // not found
        }
        $key = null;
        while (($entry = $table->next($key)) !== null && $entry !== false) {
            [$key, $value] = $entry;
            if (\is_string($key)) {  // ignore non-string keys
                if (Vm::rawEquals($object, $value)) {  // found object?
                    return $key;
                }
                $subName = self::findField($object, $value, $level - 1);
                if ($subName !== null) {  // try recursively
                    return $key . '.' . $subName;
                }
            }
        }
        return null;
    }

    // lauxlib.c: pushfuncname
    private static function functionDescription(Coroutine $L, CallInfo $ci, array $info): string
    {
        $globalName = self::globalFunctionName($L, $ci);
        if ($globalName !== null) {  // try first a global name
            return "function '" . DebugInfo::cString($globalName) . "'";
        }
        if ($info['namewhat'] !== '') {  // is there a name from code?
            return $info['namewhat'] . " '" . DebugInfo::cString($info['name']) . "'";  // use it
        }
        if ($info['what'] === 'main') {  // main?
            return 'main chunk';
        }
        if ($info['what'] !== 'C') {  // for Lua functions, use <file:line>
            return 'function <' . $info['short_src'] . ':' . $info['linedefined'] . '>';
        }
        return '?';  // nothing left...
    }

    // lauxlib.c: lastlevel
    private static function lastLevel(Coroutine $L1): int
    {
        $level = 0;
        for ($ci = $L1->ci; $ci !== null && $ci !== $L1->baseCi; $ci = $ci->previous) {
            $level++;
        }
        return $level - 1;
    }

    /** lauxlib.c: luaL_traceback of thread $L1 starting at $level */
    public static function traceback(Coroutine $L, Coroutine $L1, ?string $message, int $level): string
    {
        $last = self::lastLevel($L1);
        $limitToShow = ($last - $level > self::LEVELS1 + self::LEVELS2) ? self::LEVELS1 : -1;
        $buffer = $message !== null ? $message . "\n" : '';
        $buffer .= 'stack traceback:';
        while (($ci = DebugInfo::getStack($L1, $level++)) !== null) {
            if ($limitToShow-- === 0) {  // too many levels?
                $skipped = $last - $level - self::LEVELS2 + 1;  // number of levels to skip
                $buffer .= "\n\t...\t(skipping $skipped levels)";
                $level += $skipped;  // and skip to last levels
            } else {
                $info = DebugInfo::getInfo($L1, 'Slnt', $ci);
                if ($info['currentline'] <= 0) {
                    $buffer .= "\n\t" . $info['short_src'] . ': in ';
                } else {
                    $buffer .= "\n\t" . $info['short_src'] . ':' . $info['currentline'] . ': in ';
                }
                $buffer .= self::functionDescription($L1, $ci, $info);
                if ($info['istailcall']) {
                    $buffer .= "\n\t(...tail calls...)";
                }
            }
        }
        return $buffer;
    }

    /**
     * lauxlib.c: luaL_setfuncs for a module table: $functions maps names to
     * PHP closures (Coroutine $L, array $args): array.
     *
     * @param array<string, \Closure> $functions
     */
    public static function setFunctions(LuaTable $table, array $functions): void
    {
        foreach ($functions as $name => $function) {
            $table->hash[$name] = new NativeFunction($name, $function);
        }
    }

    /** lauxlib.c: luaL_requiref's bookkeeping: package.loaded[name] = module */
    public static function registerLoaded(Coroutine $L, string $name, mixed $module): void
    {
        $L->globalState->registry->hash[Lua::LUA_LOADED_TABLE]->hash[$name] = $module;
    }
}
