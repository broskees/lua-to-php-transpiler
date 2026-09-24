<?php

declare(strict_types=1);

namespace LuaPhp\Lib\String;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\DebugInfo;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaObject;
use LuaPhp\Runtime\NumberFormat;

/**
 * Port of lstrlib.c's STRING FORMAT section: str_format with its
 * validity checks (getformat, checkformat), '%q' (addliteral, addquoted,
 * quotefloat) and the C printf conversions it hands to l_sprintf, done
 * here for integers and by NumberFormat::formatFloat for floats.
 */
final class StringFormat
{
    // lstrlib.c: L_ESC
    private const L_ESC = '%';

    // lstrlib.c: valid flags in a format specification
    private const L_FMTFLAGSF = '-+#0 ';  // a, A, e, E, f, F, g, and G conversions
    private const L_FMTFLAGSX = '-#0';  // o, x, and X conversions
    private const L_FMTFLAGSI = '-+0 ';  // d and i conversions
    private const L_FMTFLAGSU = '-0';  // u conversions
    private const L_FMTFLAGSC = '-';  // c, p, and s conversions

    // lstrlib.c: MAX_FORMAT (maximum size of each format specification)
    private const MAX_FORMAT = 32;

    /** characters that addquoted escapes: '"', '\\', and control characters (C locale iscntrl) */
    private const QUOTED_SPECIALS = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** @var list<string> long strings whose address '%p' gave out (see stringAddress) */
    private static array $addressedLongStrings = [];

    /** holds a string while isSameString counts references */
    private static ?string $extraCopy = null;

    // lstrlib.c: str_format
    public static function format(Coroutine $L, array $args): string
    {
        $top = \count($args);
        $arg = 1;
        $format = Auxiliary::checkString($L, $args, $arg);
        $formatEnd = \strlen($format);
        $position = 0;
        $result = '';
        while ($position < $formatEnd) {
            $escapePosition = strpos($format, self::L_ESC, $position);
            if ($escapePosition === false) {
                $result .= substr($format, $position);
                break;
            }
            $result .= substr($format, $position, $escapePosition - $position);
            $position = $escapePosition + 1;
            if (($format[$position] ?? "\0") === self::L_ESC) {
                $result .= self::L_ESC;  // %%
                $position++;
                continue;
            }
            // format item
            if (++$arg > $top) {
                Auxiliary::argError($L, $arg, 'no value');
            }
            // lstrlib.c: getformat: spans flags, width, and precision ('0' is included as a flag)
            $length = strspn($format, self::L_FMTFLAGSF . '123456789.', $position) + 1;  // adds following character (should be the specifier)
            if ($length >= self::MAX_FORMAT - 10) {  // still needs space for '%', '\0', plus a length modifier
                Auxiliary::error($L, 'invalid format (too long)');
            }
            $form = self::L_ESC . substr($format, $position, $length);
            $conversion = $format[$position + $length - 1] ?? "\0";  // C reads the string's final '\0'
            $position += $length;
            $value = $args[$arg - 1];
            switch ($conversion) {
                case 'c':
                    self::checkFormat($L, $form, self::L_FMTFLAGSC, false);
                    $character = \chr(Auxiliary::checkInteger($L, $args, $arg) & 0xFF);
                    $result .= self::pad($form, $character);
                    break;
                case 'd':
                case 'i':
                    $result .= self::formatInteger($L, $args, $arg, $form, $conversion, self::L_FMTFLAGSI);
                    break;
                case 'u':
                    $result .= self::formatInteger($L, $args, $arg, $form, $conversion, self::L_FMTFLAGSU);
                    break;
                case 'o':
                case 'x':
                case 'X':
                    $result .= self::formatInteger($L, $args, $arg, $form, $conversion, self::L_FMTFLAGSX);
                    break;
                case 'a':
                case 'A':
                    self::checkFormat($L, $form, self::L_FMTFLAGSF, true);
                    $number = (float) Auxiliary::checkNumber($L, $args, $arg);
                    $result .= self::formatFloat($form, $conversion, $number);
                    break;
                case 'f':
                case 'e':
                case 'E':
                case 'g':
                case 'G':
                    $number = (float) Auxiliary::checkNumber($L, $args, $arg);
                    self::checkFormat($L, $form, self::L_FMTFLAGSF, true);
                    $result .= self::formatFloat($form, $conversion, $number);
                    break;
                case 'p':
                    $pointer = self::toPointer($value);
                    self::checkFormat($L, $form, self::L_FMTFLAGSC, false);
                    // avoid calling 'printf' with argument NULL: format "(null)" as a string
                    $result .= self::pad($form, $pointer ?? '(null)');
                    break;
                case 'q':
                    if (\strlen($form) !== 2) {  // modifiers?
                        Auxiliary::error($L, "specifier '%q' cannot have modifiers");
                    }
                    $result .= self::literal($L, $args, $arg);
                    break;
                case 's':
                    $string = Auxiliary::toLString($L, $value);
                    if (\strlen($form) === 2) {  // no modifiers?
                        $result .= $string;  // keep entire string
                        break;
                    }
                    Auxiliary::argCheck($L, !str_contains($string, "\0"), $arg, 'string contains zeros');
                    self::checkFormat($L, $form, self::L_FMTFLAGSC, true);
                    if (!str_contains($form, '.') && \strlen($string) >= 100) {
                        // no precision and string is too long to be formatted
                        $result .= $string;  // keep entire string
                        break;
                    }
                    [, , $precision] = self::parseSpecification($form);
                    if ($precision !== null) {
                        $string = substr($string, 0, $precision);
                    }
                    $result .= self::pad($form, $string);
                    break;
                default:  // also treat cases 'pnLlh'
                    Auxiliary::error($L, "invalid conversion '" . DebugInfo::cString($form) . "' to 'format'");
            }
        }
        return $result;
    }

    // lstrlib.c: get2digits
    private static function skipTwoDigits(string $form, int $position): int
    {
        if (ctype_digit($form[$position] ?? '')) {
            $position++;
            if (ctype_digit($form[$position] ?? '')) {  // (2 digits at most)
                $position++;
            }
        }
        return $position;
    }

    /**
     * lstrlib.c: checkformat: is the conversion specification $form valid
     * with the accepted $flags (and a precision, if $precision)?
     */
    private static function checkFormat(Coroutine $L, string $form, string $flags, bool $precision): void
    {
        $spec = 1;  // skip '%'
        $spec += strspn($form, $flags, $spec);  // skip flags
        if (($form[$spec] ?? '') !== '0') {  // a width cannot start with '0'
            $spec = self::skipTwoDigits($form, $spec);  // skip width
            if (($form[$spec] ?? '') === '.' && $precision) {
                $spec++;
                $spec = self::skipTwoDigits($form, $spec);  // skip precision
            }
        }
        if (!ctype_alpha($form[$spec] ?? '')) {  // did not go to the end?
            Auxiliary::error($L, "invalid conversion specification: '" . DebugInfo::cString($form) . "'");
        }
    }

    /**
     * The parts of a specification checkformat accepted: [flags, field
     * width (0 if none), precision (null if none)].
     *
     * @return array{string, int, ?int}
     */
    private static function parseSpecification(string $form): array
    {
        $flagCount = strspn($form, '-+ #0', 1);
        $flags = substr($form, 1, $flagCount);
        $widthAndPrecision = substr($form, 1 + $flagCount, -1);
        $pointPosition = strpos($widthAndPrecision, '.');
        if ($pointPosition === false) {
            return [$flags, (int) $widthAndPrecision, null];
        }
        return [$flags, (int) substr($widthAndPrecision, 0, $pointPosition), (int) substr($widthAndPrecision, $pointPosition + 1)];
    }

    /** C printf's field width for a string item ('%s', '%c', '%p'): pad with spaces */
    private static function pad(string $form, string $text): string
    {
        [$flags, $width] = self::parseSpecification($form);
        if (\strlen($text) >= $width) {
            return $text;
        }
        if (str_contains($flags, '-')) {  // left-justify
            return str_pad($text, $width);
        }
        return str_pad($text, $width, ' ', STR_PAD_LEFT);
    }

    /** lstrlib.c: str_format's 'intcase': C printf "%lld" and friends */
    private static function formatInteger(Coroutine $L, array $args, int $arg, string $form, string $conversion, string $validFlags): string
    {
        $n = Auxiliary::checkInteger($L, $args, $arg);
        self::checkFormat($L, $form, $validFlags, true);
        [$flags, $width, $precision] = self::parseSpecification($form);
        $sign = '';
        $prefix = '';
        switch ($conversion) {
            case 'd':
            case 'i':
                if ($n < 0) {
                    $sign = '-';
                    $digits = $n === PHP_INT_MIN ? '9223372036854775808' : (string) -$n;
                } else {
                    $digits = (string) $n;
                    if (str_contains($flags, '+')) {
                        $sign = '+';
                    } elseif (str_contains($flags, ' ')) {
                        $sign = ' ';
                    }
                }
                break;
            default:  // unsigned conversions (PHP's are 64-bit unsigned too)
                $digits = sprintf('%' . $conversion, $n);
                break;
        }
        if ($precision !== null) {  // minimum number of digits
            if ($precision === 0 && $n === 0) {
                $digits = '';
            } elseif (\strlen($digits) < $precision) {
                $digits = str_pad($digits, $precision, '0', STR_PAD_LEFT);
            }
        }
        if (str_contains($flags, '#')) {  // alternate form
            if ($conversion === 'o' && ($digits === '' || $digits[0] !== '0')) {
                $digits = '0' . $digits;  // first digit is a zero
            } elseif (($conversion === 'x' || $conversion === 'X') && $n !== 0) {
                $prefix = '0' . $conversion;
            }
        }
        $padding = $width - \strlen($sign) - \strlen($prefix) - \strlen($digits);
        if ($padding <= 0) {
            return $sign . $prefix . $digits;
        }
        if (str_contains($flags, '-')) {
            return $sign . $prefix . $digits . str_repeat(' ', $padding);
        }
        if (str_contains($flags, '0') && $precision === null) {  // '0' is ignored with a precision
            return $sign . $prefix . str_repeat('0', $padding) . $digits;
        }
        return str_repeat(' ', $padding) . $sign . $prefix . $digits;
    }

    private static function formatFloat(string $form, string $conversion, float $number): string
    {
        [$flags, $width, $precision] = self::parseSpecification($form);
        return NumberFormat::formatFloat($number, $conversion, $flags, $width, $precision);
    }

    // lstrlib.c: addliteral
    private static function literal(Coroutine $L, array $args, int $arg): string
    {
        $value = $args[$arg - 1];
        if (\is_string($value)) {
            return self::quoted($value);
        }
        if (\is_int($value)) {
            // corner case: LUA_MININTEGER is not a numeral; use hex
            return $value === PHP_INT_MIN ? '0x8000000000000000' : (string) $value;
        }
        if (\is_float($value)) {
            return self::quoteFloat($value);
        }
        if ($value === null || \is_bool($value)) {
            return Auxiliary::toLString($L, $value);
        }
        Auxiliary::argError($L, $arg, 'value has no literal form');
    }

    // lstrlib.c: addquoted
    private static function quoted(string $s): string
    {
        $length = \strlen($s);
        $quoted = '"';
        $position = 0;
        while ($position < $length) {
            $plainLength = strcspn($s, self::QUOTED_SPECIALS, $position);
            $quoted .= substr($s, $position, $plainLength);
            $position += $plainLength;
            if ($position >= $length) {
                break;
            }
            $character = $s[$position];
            if ($character === '"' || $character === '\\' || $character === "\n") {
                $quoted .= '\\' . $character;
            } elseif (ctype_digit($s[$position + 1] ?? '')) {  // control character before a digit
                $quoted .= sprintf('\\%03d', \ord($character));
            } else {
                $quoted .= '\\' . \ord($character);
            }
            $position++;
        }
        return $quoted . '"';
    }

    /**
     * lstrlib.c: quotefloat: a float in a form Lua reads back: "%a" for
     * common numbers; inf, -inf and NaN have fixed representations.
     */
    private static function quoteFloat(float $number): string
    {
        if ($number === INF) {
            return '1e9999';
        }
        if ($number === -INF) {
            return '-1e9999';
        }
        if (is_nan($number)) {
            return '(0/0)';
        }
        return NumberFormat::formatFloat($number, 'a', '', 0, null);
    }

    /**
     * lapi.c: lua_topointer printed by "%p": the address of a collectable
     * object (strings included), null (C's NULL) for numbers, booleans and
     * nil.
     */
    private static function toPointer(mixed $value): ?string
    {
        if (\is_string($value)) {
            return self::stringAddress($value);
        }
        if (\is_object($value)) {
            return LuaObject::address($value);
        }
        return null;
    }

    /**
     * The address of a string object. C interns short strings, so equal
     * short strings are one object and their address follows from their
     * contents. Long strings are distinct objects even when equal (and
     * string.gsub returns its very subject when nothing changed): PHP
     * strings have no observable identity except through their reference
     * count, so each long string handed to '%p' is kept, and a later one
     * gets the same address only if it is the same PHP string (see
     * isSameString).
     */
    private static function stringAddress(string $s): string
    {
        if (\strlen($s) <= Lua::LUAI_MAXSHORTLEN) {
            return sprintf('0x5556%08x', crc32($s));
        }
        foreach (self::$addressedLongStrings as $index => $addressedString) {
            if ($addressedString === $s && self::isSameString($addressedString, $s)) {
                return sprintf('0x5557%08x', $index * 16);
            }
        }
        self::$addressedLongStrings[] = $s;
        return sprintf('0x5557%08x', (\count(self::$addressedLongStrings) - 1) * 16);
    }

    /**
     * Are $first and $second the same PHP string (zend_string)? Holding one
     * more copy of $second raises the reference count of $first exactly
     * when they are. Interned strings (PHP literals) have no count and are
     * unique per contents.
     */
    private static function isSameString(string $first, string $second): bool
    {
        $countBefore = self::referenceCount($first);
        self::$extraCopy = $second;  // (a property: opcache would drop an unused local)
        $countAfter = self::referenceCount($first);
        self::$extraCopy = null;
        if ($countBefore === null || $countAfter === null) {
            return $countBefore === null && self::referenceCount($second) === null;
        }
        return $countAfter === $countBefore + 1;
    }

    /** the reference count PHP reports for a string, null for an interned one */
    private static function referenceCount(string $value): ?int
    {
        ob_start();
        debug_zval_dump($value);
        $dump = (string) ob_get_clean();
        if (preg_match('/ refcount\((\d+)\)\s*$/', $dump, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }
}
