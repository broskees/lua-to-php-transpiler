<?php

declare(strict_types=1);

namespace LuaPhp\Emitter;

/**
 * PHP source literals for Lua constants (null|bool|int|float|string),
 * exact to the bit: floats round-trip, strings are binary-safe.
 *
 * @internal
 */
final class PhpLiteral
{
    public static function of(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (\is_int($value)) {
            return $value === PHP_INT_MIN ? '\PHP_INT_MIN' : (string) $value;
        }
        if (\is_float($value)) {
            return self::float($value);
        }
        if (\is_string($value)) {
            return self::string($value);
        }
        throw new \LogicException('not a Lua constant: ' . get_debug_type($value));
    }

    public static function float(float $value): string
    {
        if (is_nan($value)) {
            // keep the exact bits (sign) of the NaN
            return "\\unpack('E', " . self::string(pack('E', $value)) . ')[1]';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '\INF' : '-\INF';
        }
        $text = var_export($value, true);  // shortest round-trip text (serialize_precision = -1)
        if ((float) $text === $value && (ord(pack('E', (float) $text)[0]) & 0x80) === (ord(pack('E', $value)[0]) & 0x80)) {
            return $text;
        }
        return "\\unpack('E', " . self::string(pack('E', $value)) . ')[1]';
    }

    /** a single-quoted PHP string: only backslash and quote need escaping */
    public static function string(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }

    /** text safe inside a PHP '//' comment (no newlines, no '?>') */
    public static function commentText(string $text, int $maximumLength = 40): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $text);
        $clean = str_replace('?>', '? >', $clean);
        if (\strlen($clean) > $maximumLength) {
            $clean = substr($clean, 0, $maximumLength) . '...';
        }
        return $clean;
    }
}
