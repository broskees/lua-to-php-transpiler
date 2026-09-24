<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Compiler\Dump;
use LuaPhp\Lib\String\MatchState;
use LuaPhp\Lib\String\Pack;
use LuaPhp\Lib\String\StringFormat;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;
use LuaPhp\Runtime\StringToNumber;
use LuaPhp\Runtime\Userdata;
use LuaPhp\Runtime\Vm;

/**
 * Port of lstrlib.c: the string library, and the string metatable
 * ('__index' = the string table) with the arithmetic metamethods that
 * coerce numeric strings ("10" + 1 == 11).
 *
 * The pattern matcher is String\MatchState, string.format is
 * String\StringFormat, pack/unpack are String\Pack.
 */
final class StringLib
{
    // lstrlib.c: MAXSIZE (INT_MAX, as sizeof(size_t) >= sizeof(int))
    private const MAXSIZE = 0x7fffffff;

    // limits.h: INT_MAX
    private const INT_MAX = 0x7fffffff;

    // lstrlib.c: luaopen_string
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'byte' => self::byte(...),
            'char' => self::char(...),
            'dump' => self::dump(...),
            'find' => static fn (Coroutine $L, array $args): array => self::findAux($L, $args, true),
            'format' => static fn (Coroutine $L, array $args): array => [StringFormat::format($L, $args)],
            'gmatch' => self::gmatch(...),
            'gsub' => self::gsub(...),
            'len' => self::len(...),
            'lower' => self::lower(...),
            'match' => static fn (Coroutine $L, array $args): array => self::findAux($L, $args, false),
            'rep' => self::rep(...),
            'reverse' => self::reverse(...),
            'sub' => self::sub(...),
            'upper' => self::upper(...),
            'pack' => static fn (Coroutine $L, array $args): array => [Pack::pack($L, $args)],
            'packsize' => static fn (Coroutine $L, array $args): array => [Pack::packsize($L, $args)],
            'unpack' => Pack::unpack(...),
        ]);
        self::createMetatable($L, $library);
        return $library;
    }

    // lstrlib.c: str_len
    private static function len(Coroutine $L, array $args): array
    {
        return [\strlen(Auxiliary::checkString($L, $args, 1))];
    }

    /**
     * lstrlib.c: posrelatI: translate a relative initial string position
     * (negative means back from end): clip result to [1, inf).
     */
    public static function relativePosition(int $position, int $length): int
    {
        if ($position > 0) {
            return $position;
        }
        if ($position === 0) {
            return 1;
        }
        if ($position < -$length) {  // inverted comparison
            return 1;  // clip to 1
        }
        return $length + $position + 1;
    }

    /**
     * lstrlib.c: getendpos: optional ending string position from argument
     * $arg, with default value $default. Negative means back from end:
     * clip result to [0, len].
     */
    private static function endPosition(Coroutine $L, array $args, int $arg, int $default, int $length): int
    {
        $position = Auxiliary::optInteger($L, $args, $arg, $default);
        if ($position > $length) {
            return $length;
        }
        if ($position >= 0) {
            return $position;
        }
        if ($position < -$length) {
            return 0;
        }
        return $length + $position + 1;
    }

    // lstrlib.c: str_sub
    private static function sub(Coroutine $L, array $args): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $length = \strlen($s);
        $start = self::relativePosition(Auxiliary::checkInteger($L, $args, 2), $length);
        $end = self::endPosition($L, $args, 3, -1, $length);
        if ($start > $end) {
            return [''];
        }
        return [substr($s, $start - 1, $end - $start + 1)];
    }

    // lstrlib.c: str_reverse
    private static function reverse(Coroutine $L, array $args): array
    {
        return [strrev(Auxiliary::checkString($L, $args, 1))];
    }

    // lstrlib.c: str_lower (C locale; PHP's strtolower is ASCII-only)
    private static function lower(Coroutine $L, array $args): array
    {
        return [strtolower(Auxiliary::checkString($L, $args, 1))];
    }

    // lstrlib.c: str_upper (C locale)
    private static function upper(Coroutine $L, array $args): array
    {
        return [strtoupper(Auxiliary::checkString($L, $args, 1))];
    }

    // lstrlib.c: str_rep
    private static function rep(Coroutine $L, array $args): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $n = Auxiliary::checkInteger($L, $args, 2);
        $separator = Auxiliary::optString($L, $args, 3, '');
        if ($n <= 0) {
            return [''];
        }
        if (\strlen($s) + \strlen($separator) > intdiv(self::MAXSIZE, $n)) {
            Auxiliary::error($L, 'resulting string too large');
        }
        if ($separator === '') {
            return [str_repeat($s, $n)];
        }
        // first n-1 copies followed by the separator, then the last copy
        return [str_repeat($s . $separator, $n - 1) . $s];
    }

    // lstrlib.c: str_byte
    private static function byte(Coroutine $L, array $args): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $length = \strlen($s);
        $initial = Auxiliary::optInteger($L, $args, 2, 1);
        $start = self::relativePosition($initial, $length);
        $end = self::endPosition($L, $args, 3, $initial, $length);
        if ($start > $end) {
            return [];  // empty interval; return no values
        }
        if ($end - $start >= self::INT_MAX) {  // arithmetic overflow?
            Auxiliary::error($L, 'string slice too long');
        }
        $count = $end - $start + 1;
        Auxiliary::checkStack($L, $count, 'string slice too long');
        return array_values(unpack('C*', substr($s, $start - 1, $count)));
    }

    // lstrlib.c: str_char
    private static function char(Coroutine $L, array $args): array
    {
        $count = \count($args);
        $result = '';
        for ($i = 1; $i <= $count; $i++) {
            $code = Auxiliary::checkInteger($L, $args, $i);
            Auxiliary::argCheck($L, $code >= 0 && $code <= 255, $i, 'value out of range');  // (lua_Unsigned)c <= UCHAR_MAX
            $result .= \chr($code);
        }
        return [$result];
    }

    // lstrlib.c: str_dump (lapi.c: lua_dump fails for C functions)
    private static function dump(Coroutine $L, array $args): array
    {
        $strip = ($args[1] ?? null) !== null && ($args[1] ?? null) !== false;
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TFUNCTION);
        $function = $args[0];
        if (!($function instanceof LuaClosure)) {
            Auxiliary::error($L, 'unable to dump given function');
        }
        return [Dump::dump($function->proto, $strip)];
    }

    /*
    ** {======================================================
    ** PATTERN MATCHING
    ** =======================================================
    */

    /** lstrlib.c: nospecials: does the pattern have no special characters? */
    private static function noSpecials(string $pattern): bool
    {
        return strcspn($pattern, MatchState::SPECIALS) === \strlen($pattern);
    }

    // lstrlib.c: str_find_aux (str_find and str_match)
    private static function findAux(Coroutine $L, array $args, bool $find): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $pattern = Auxiliary::checkString($L, $args, 2);
        $length = \strlen($s);
        $init = self::relativePosition(Auxiliary::optInteger($L, $args, 3, 1), $length) - 1;
        if ($init > $length) {  // start after string's end?
            return [null];  // cannot find anything
        }
        // explicit request or no special characters?
        $plain = $args[3] ?? null;
        if ($find && (($plain !== null && $plain !== false) || self::noSpecials($pattern))) {
            // do a plain search (lstrlib.c: lmemfind)
            $found = strpos($s, $pattern, $init);
            if ($found !== false) {
                return [$found + 1, $found + \strlen($pattern)];
            }
            return [null];  // not found
        }
        $anchor = ($pattern[0] ?? '') === '^';
        if ($anchor) {
            $pattern = substr($pattern, 1);  // skip anchor character
        }
        $ms = new MatchState($L, $s, $pattern);
        $start = $init;
        do {
            if (!$anchor) {
                $start = $ms->nextCandidate($start);  // (skip positions where match() fails at once)
                if ($start === -1) {
                    break;
                }
            }
            $ms->reprepstate();
            $end = $ms->match($start, 0);
            if ($end !== -1) {
                if ($find) {
                    return [$start + 1, $end, ...$ms->captures(-1, 0)];
                }
                return $ms->captures($start, $end);
            }
        } while ($start++ < $length && !$anchor);
        return [null];  // not found
    }

    // lstrlib.c: gmatch (with gmatch_aux as the iterator)
    private static function gmatch(Coroutine $L, array $args): array
    {
        $s = Auxiliary::checkString($L, $args, 1);
        $pattern = Auxiliary::checkString($L, $args, 2);
        $length = \strlen($s);
        $init = self::relativePosition(Auxiliary::optInteger($L, $args, 3, 1), $length) - 1;
        if ($init > $length) {  // start after string's end?
            $init = $length + 1;  // avoid overflows in 's + init'
        }
        // lstrlib.c: GMatchState
        $ms = new MatchState($L, $s, $pattern);
        $source = $init;  // current position
        $lastMatch = -1;  // end of last match (C: NULL)
        $iterator = static function (Coroutine $L, array $unused) use ($ms, &$source, &$lastMatch): array {
            $ms->L = $L;
            for ($start = $source; $start <= $ms->srcEnd; $start++) {
                $start = $ms->nextCandidate($start);  // (skip positions where match() fails at once)
                if ($start === -1) {
                    break;
                }
                $ms->reprepstate();
                $end = $ms->match($start, 0);
                if ($end !== -1 && $end !== $lastMatch) {
                    $source = $lastMatch = $end;
                    return $ms->captures($start, $end);
                }
            }
            return [];  // not found
        };
        // C closure upvalues: the subject, the pattern and the GMatchState userdata
        return [new NativeFunction('gmatch_aux', $iterator, [$s, $pattern, new Userdata($ms)])];
    }

    /**
     * lstrlib.c: add_s: the replacement string $replacement for the match
     * $s..$e, with '%0'-'%9' and '%%' expanded.
     */
    private static function expandReplacement(MatchState $ms, string $replacement, int $s, int $e): string
    {
        $expanded = '';
        $position = 0;
        while (($escapePosition = strpos($replacement, '%', $position)) !== false) {
            $expanded .= substr($replacement, $position, $escapePosition - $position);
            $escaped = $replacement[$escapePosition + 1] ?? "\0";  // skip ESC (C reads the final '\0')
            if ($escaped === '%') {  // '%%'
                $expanded .= '%';
            } elseif ($escaped === '0') {  // '%0'
                $expanded .= substr($ms->src, $s, $e - $s);
            } elseif (ctype_digit($escaped)) {  // '%n'
                $expanded .= (string) $ms->getCapture(\ord($escaped) - \ord('1'), $s, $e);
            } else {
                Auxiliary::error($ms->L, "invalid use of '%' in replacement string");
            }
            $position = $escapePosition + 2;
        }
        return $expanded . substr($replacement, $position);
    }

    // lstrlib.c: str_gsub (with add_value)
    private static function gsub(Coroutine $L, array $args): array
    {
        $source = Auxiliary::checkString($L, $args, 1);  // subject
        $pattern = Auxiliary::checkString($L, $args, 2);
        $sourceLength = \strlen($source);
        $replacementType = Auxiliary::argumentType($args, 3);
        $maxReplacements = Auxiliary::optInteger($L, $args, 4, $sourceLength + 1);
        $anchor = ($pattern[0] ?? '') === '^';
        Auxiliary::argExpected(
            $L,
            $replacementType === Lua::LUA_TNUMBER || $replacementType === Lua::LUA_TSTRING
                || $replacementType === Lua::LUA_TFUNCTION || $replacementType === Lua::LUA_TTABLE,
            $args,
            3,
            'string/function/table',
        );
        $replacement = $args[2];
        $replacementString = LuaObject::toStringCoerced($replacement);  // for LUA_TNUMBER or LUA_TSTRING
        if ($anchor) {
            $pattern = substr($pattern, 1);  // skip anchor character
        }
        $ms = new MatchState($L, $source, $pattern);
        $result = '';
        $position = 0;
        $lastMatch = -1;  // end of last match (C: NULL)
        $count = 0;  // replacement count
        $changed = false;  // change flag
        while ($count < $maxReplacements) {
            if (!$anchor) {  // (skip positions where match() fails at once, keeping their text)
                $candidate = $ms->nextCandidate($position);
                if ($candidate === -1) {
                    break;  // no more matches: the rest of the subject is kept below
                }
                $result .= substr($source, $position, $candidate - $position);
                $position = $candidate;
            }
            $ms->reprepstate();  // (re)prepare state for new match
            $end = $ms->match($position, 0);
            if ($end !== -1 && $end !== $lastMatch) {  // match?
                $count++;
                // lstrlib.c: add_value
                if ($replacementString !== null) {
                    $result .= self::expandReplacement($ms, $replacementString, $position, $end);
                    $changed = true;  // something changed
                } else {
                    if ($replacementType === Lua::LUA_TFUNCTION) {  // call the function
                        $value = Calls::callNoYield($L, $replacement, $ms->captures($position, $end))[0] ?? null;
                    } else {  // index the table
                        $value = Vm::getTable($L, $replacement, $ms->getCapture(0, $position, $end));
                    }
                    if ($value === null || $value === false) {  // nil or false?
                        $result .= substr($source, $position, $end - $position);  // keep original text
                    } elseif (!(\is_string($value) || \is_int($value) || \is_float($value))) {
                        Auxiliary::error($L, 'invalid replacement value (a ' . LuaObject::typeName($value) . ')');
                    } else {
                        $result .= LuaObject::toStringCoerced($value);  // add result to accumulator
                        $changed = true;  // something changed
                    }
                }
                $position = $lastMatch = $end;
            } elseif ($position < $sourceLength) {  // otherwise, skip one character
                $result .= $source[$position++];
            } else {
                break;  // end of subject
            }
            if ($anchor) {
                break;
            }
        }
        if (!$changed) {  // no changes?
            // return original string (a number subject was converted in place by luaL_checklstring)
            return [$source, $count];
        }
        $result .= substr($source, $position);
        return [$result, $count];  // new string and number of substitutions
    }

    /* }====================================================== */

    // lstrlib.c: createmetatable
    private static function createMetatable(Coroutine $L, LuaTable $library): void
    {
        $metatable = new LuaTable();
        $events = [
            '__add' => Vm::LUA_OPADD,
            '__sub' => Vm::LUA_OPSUB,
            '__mul' => Vm::LUA_OPMUL,
            '__mod' => Vm::LUA_OPMOD,
            '__pow' => Vm::LUA_OPPOW,
            '__div' => Vm::LUA_OPDIV,
            '__idiv' => Vm::LUA_OPIDIV,
            '__unm' => Vm::LUA_OPUNM,
        ];
        foreach ($events as $event => $operator) {
            $metatable->hash[$event] = new NativeFunction(
                'arith' . substr($event, 1),
                static fn (Coroutine $L, array $args): array => self::arith($L, $args, $operator, $event),
            );
        }
        $L->globalState->typeMetatables[Lua::LUA_TSTRING] = $metatable;  // set table as metatable for strings
        $metatable->hash['__index'] = $library;  // metatable.__index = string
    }

    /** lstrlib.c: tonum: the number an argument converts to, or null */
    private static function toNumber(mixed $value): int|float|null
    {
        if (\is_int($value) || \is_float($value)) {  // already a number?
            return $value;
        }
        if (\is_string($value)) {  // check whether it is a numerical string
            return StringToNumber::convert($value);
        }
        return null;
    }

    // lstrlib.c: arith
    private static function arith(Coroutine $L, array $args, int $operator, string $event): array
    {
        $first = self::toNumber($args[0] ?? null);
        $second = self::toNumber($args[1] ?? null);
        if ($first !== null && $second !== null) {
            return [Vm::arith($L, $operator, $first, $second)];  // result will be on the top
        }
        // lstrlib.c: trymt
        $metamethodOwner = $args[1] ?? null;
        $metamethod = \is_string($metamethodOwner) ? null : Auxiliary::getMetafield($L, $metamethodOwner, $event);
        if ($metamethod === null) {
            Auxiliary::error($L, 'attempt to ' . substr($event, 2) . " a '" . Auxiliary::argumentTypeName($args, 1)
                . "' with a '" . Auxiliary::argumentTypeName($args, 2) . "'");
        }
        $results = Calls::call($L, $metamethod, [$args[0] ?? null, $args[1] ?? null]);  // call metamethod
        return [$results[0] ?? null];
    }
}
