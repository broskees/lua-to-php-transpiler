<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Os;

/**
 * glibc's strftime (time/strftime_l.c) with the names and formats of an
 * LC_TIME locale (CTime::timeLocale): what CTime::strftime does not do
 * directly in the "C" locale. A locale's items come from nl_langinfo
 * (locale()).
 *
 * @internal
 */
final class Strftime
{
    private const INT_MAX = 2147483647;
    private const INT_MIN = -2147483648;

    /** @var array<string, array<string, mixed>> the LC_TIME items of the locales used, by name */
    private static array $locales = [];

    /**
     * The LC_TIME items of locale $name, from nl_langinfo (see
     * CTime::timeLocale), as CTime::C_LOCALE has them for the "C" locale
     *
     * @return array<string, mixed>
     */
    public static function locale(string $name): array
    {
        if (isset(self::$locales[$name])) {
            return self::$locales[$name];
        }
        $item = static fn (string $constant): string =>
            \defined($constant) ? (string) nl_langinfo(\constant($constant)) : '';
        $locale = [];
        foreach (['abday' => 'ABDAY_', 'day' => 'DAY_'] as $key => $prefix) {
            for ($i = 1; $i <= 7; $i++) {
                $locale[$key][] = $item($prefix . $i);
            }
        }
        foreach (['abmon' => 'ABMON_', 'mon' => 'MON_'] as $key => $prefix) {
            for ($i = 1; $i <= 12; $i++) {
                $locale[$key][] = $item($prefix . $i);
            }
        }
        $locale += [
            'am' => $item('AM_STR'),
            'pm' => $item('PM_STR'),
            'd_t_fmt' => $item('D_T_FMT'),
            'd_fmt' => $item('D_FMT'),
            't_fmt' => $item('T_FMT'),
            't_fmt_ampm' => $item('T_FMT_AMPM'),
            'era' => [],
            'era_d_t_fmt' => $item('ERA_D_T_FMT'),
            'era_d_fmt' => $item('ERA_D_FMT'),
            'era_t_fmt' => $item('ERA_T_FMT'),
        ];
        $era = self::eraEntry($item('ERA'));
        if ($era !== null) {
            $locale['era'][] = $era;
        }
        return self::$locales[$name] = $locale;
    }

    /**
     * An entry of a locale's era list, "direction:offset:start:end:name:
     * format" with dates yyyy/mm/dd (a negative year is BC), "-*" or
     * "+*", as glibc keeps it (localedef's ld-time.c, era.c's
     * _nl_init_era_entries); null if there is none or it is malformed.
     *
     * @return array{offset: int, start: list<int>, stop: list<int>, absoluteDirection: int,
     *     name: string, format: string}|null
     */
    private static function eraEntry(string $text): ?array
    {
        $date = '(-\*|\+\*|-?\d+/\d+/\d+)';
        if (preg_match("~^([+-]):([+-]?\\d+):$date:$date:([^:]*):(.*)$~s", $text, $fields) !== 1) {
            return null;
        }
        $start = self::eraDate($fields[3]);
        $stop = self::eraDate($fields[4]);
        $ascending = self::eraDateCompare($start, $stop);
        return [
            'offset' => CTime::int32((int) $fields[2]),
            'start' => $start,
            'stop' => $stop,
            'absoluteDirection' => ($fields[1] === '+') === $ascending ? 1 : -1,
            'name' => $fields[5],
            'format' => $fields[6],
        ];
    }

    /**
     * ld-time.c: an era's date as [tm_year, tm_mon, tm_mday] (year -1 is
     * 1 BC), "-*" and "+*" as int's extremes
     *
     * @return list<int>
     */
    private static function eraDate(string $text): array
    {
        if ($text === '-*') {
            return [self::INT_MIN, self::INT_MIN, self::INT_MIN];
        }
        if ($text === '+*') {
            return [self::INT_MAX, self::INT_MAX, self::INT_MAX];
        }
        [$year, $month, $day] = array_map('intval', explode('/', $text));
        $year = CTime::int32($year - 1900);
        if ($year < -1900) {
            $year++;
        }
        return [$year, CTime::int32($month - 1), CTime::int32($day)];
    }

    /** era.c: ERA_DATE_CMP: date $a is not after date $b */
    private static function eraDateCompare(array $a, array $b): bool
    {
        return $a[0] < $b[0] || ($a[0] === $b[0] && ($a[1] < $b[1] || ($a[1] === $b[1] && $a[2] <= $b[2])));
    }

    /** era.c: _nl_get_era_entry: the era of $tm's date, if the locale has one */
    private static function eraOf(array $tm, array $locale): ?array
    {
        $date = [$tm['year'], $tm['mon'], $tm['mday']];
        foreach ($locale['era'] as $era) {
            if (
                (self::eraDateCompare($era['start'], $date) && self::eraDateCompare($date, $era['stop']))
                || (self::eraDateCompare($era['stop'], $date) && self::eraDateCompare($date, $era['start']))
            ) {
                return $era;
            }
        }
        return null;
    }

    /**
     * strftime_l.c: cpy: $text after padding to $width (with zeros for
     * the '0' flag, else spaces), its case changed as the flags say
     */
    private static function copyPadded(string $text, int $width, string $pad, bool $toUpper, bool $toLower): string
    {
        if ($toLower) {
            $text = strtolower($text);
        } elseif ($toUpper) {
            $text = strtoupper($text);
        }
        return self::padded($text, $width, $pad);
    }

    /** strftime_l.c: add's padding of $text to $width (beyond any buffer Lua passes: as good as infinite) */
    private static function padded(string $text, int $width, string $pad): string
    {
        $delta = min($width - \strlen($text), 1024);
        return $delta > 0 ? str_repeat($pad === '0' ? '0' : ' ', $delta) . $text : $text;
    }

    /**
     * strftime_l.c: do_number (DO_NUMBER, DO_NUMBER_SPACEPAD): $value in
     * at least $digits digits (or $width); in decimal digits for %O too
     * (_nl_get_alt_digit finds none: see timeLocale)
     */
    private static function formatNumber(int $digits, int $value, bool $spacePad, string $pad, int $width): string
    {
        $digits = max($digits, $width);
        if ($spacePad && $pad !== '0' && $pad !== '-') {
            $pad = '_';  // force '_' unless overwritten by '0' or '-'
        }
        return self::signAndPadding((string) abs($value), $value < 0, $digits, $pad, $width);
    }

    /** strftime_l.c: do_number_sign_and_padding */
    private static function signAndPadding(string $number, bool $negative, int $digits, string $pad, int $width): string
    {
        $text = $negative ? "-$number" : $number;
        $prefix = '';
        if ($pad !== '-') {
            $padding = min($digits - \strlen($text), 1024);
            if ($padding > 0 && $pad === '_') {
                $prefix = str_repeat(' ', $padding);
                $width = $width > $padding ? $width - $padding : 0;
            } elseif ($padding > 0) {
                if ($negative) {
                    $prefix = '-';
                    $text = $number;
                }
                $prefix .= str_repeat('0', $padding);
                $width = 0;
            }
        }
        return $prefix . self::padded($text, $width, $pad);
    }

    /**
     * glibc strftime_l.c: __strftime_internal: $format with $tm's fields,
     * the names and formats of $locale (timeLocale); $yearSpec is the pad
     * flag of an enclosing %EY. Flags ('_', '-', '0', '^', '#'), widths and
     * the E and O modifiers as glibc reads them (the locale's formats may
     * use them all).
     */
    public static function formatTime(string $format, array $tm, array $locale, string $yearSpec): string
    {
        $hour12 = $tm['hour'];
        if ($hour12 > 12) {
            $hour12 -= 12;
        } elseif ($hour12 === 0) {
            $hour12 = 12;
        }
        $result = '';
        $length = \strlen($format);
        for ($f = 0; $f < $length; $f++) {
            if ($format[$f] !== '%') {
                $result .= $format[$f];  // (a multibyte character goes byte by byte)
                continue;
            }
            $percent = $f;
            // flags that can modify a format
            $pad = '';
            $toUpper = false;
            $toLower = false;
            $changeCase = false;
            for ($f++; $f < $length; $f++) {
                $flag = $format[$f];
                if ($flag === '_' || $flag === '-' || $flag === '0') {
                    $pad = $flag;
                } elseif ($flag === '^') {
                    $toUpper = true;
                } elseif ($flag === '#') {
                    $changeCase = true;
                } else {
                    break;
                }
            }
            // the field width (a GNU extension)
            $width = -1;
            if ($f < $length && ctype_digit($format[$f])) {
                $width = 0;
                for (; $f < $length && ctype_digit($format[$f]); $f++) {
                    $digit = \ord($format[$f]) - 48;
                    $tooBig = $width > intdiv(self::INT_MAX, 10)
                        || ($width === intdiv(self::INT_MAX, 10) && $digit > self::INT_MAX % 10);
                    $width = $tooBig ? self::INT_MAX : $width * 10 + $digit;
                }
            }
            $modifier = '';
            if ($f < $length && ($format[$f] === 'E' || $format[$f] === 'O')) {
                $modifier = $format[$f++];
            }
            $specifier = $f < $length ? $format[$f] : '';
            $badFormat = false;
            $number = null;  // [digits, value, space padded] for do_number
            $subformat = null;
            switch ($specifier) {
                case '%':
                    if ($modifier !== '') {
                        $badFormat = true;
                        break;
                    }
                    $result .= self::padded('%', $width, $pad);
                    break;
                case 'a':
                case 'A':
                    if ($modifier !== '') {
                        $badFormat = true;
                        break;
                    }
                    if ($changeCase) {
                        [$toUpper, $toLower] = [true, false];
                    }
                    $names = $locale[$specifier === 'a' ? 'abday' : 'day'];
                    $name = $tm['wday'] < 0 || $tm['wday'] > 6 ? '?' : $names[$tm['wday']];
                    $result .= self::copyPadded($name, $width, $pad, $toUpper, $toLower);
                    break;
                case 'b':
                case 'h':
                case 'B':
                    if ($changeCase) {
                        [$toUpper, $toLower] = [true, false];
                    }
                    if ($modifier === 'E') {
                        $badFormat = true;
                        break;
                    }
                    $names = $locale[$specifier === 'B' ? 'mon' : 'abmon'];  // (also for %O: see timeLocale)
                    $name = $tm['mon'] < 0 || $tm['mon'] > 11 ? '?' : $names[$tm['mon']];
                    $result .= self::copyPadded($name, $width, $pad, $toUpper, $toLower);
                    break;
                case 'c':
                    if ($modifier === 'O') {
                        $badFormat = true;
                        break;
                    }
                    $subformat = $modifier === 'E' && $locale['era_d_t_fmt'] !== ''
                        ? $locale['era_d_t_fmt'] : $locale['d_t_fmt'];
                    break;
                case 'C':
                    if ($modifier === 'E') {
                        $era = self::eraOf($tm, $locale);
                        if ($era !== null) {
                            $result .= self::copyPadded($era['name'], $width, $pad, $toUpper, $toLower);
                            break;
                        }
                    }
                    $year = CTime::int32($tm['year'] + 1900);
                    $number = [1, intdiv($year, 100) - ($year % 100 < 0 ? 1 : 0), false];
                    break;
                case 'x':
                    if ($modifier === 'O') {
                        $badFormat = true;
                        break;
                    }
                    $subformat = $modifier === 'E' && $locale['era_d_fmt'] !== ''
                        ? $locale['era_d_fmt'] : $locale['d_fmt'];
                    break;
                case 'D':
                case 'F':
                    if ($modifier !== '') {
                        $badFormat = true;
                        break;
                    }
                    $subformat = $specifier === 'D' ? '%m/%d/%y' : '%Y-%m-%d';
                    break;
                case 'd':
                case 'e':
                case 'H':
                case 'I':
                case 'k':
                case 'l':
                case 'j':
                case 'M':
                case 'm':
                case 'S':
                case 'U':
                case 'W':
                case 'w':
                    if ($modifier === 'E') {
                        $badFormat = true;
                        break;
                    }
                    $number = match ($specifier) {
                        'd' => [2, $tm['mday'], false],
                        'e' => [2, $tm['mday'], true],
                        'H' => [2, $tm['hour'], false],
                        'I' => [2, $hour12, false],
                        'k' => [2, $tm['hour'], true],
                        'l' => [2, $hour12, true],
                        'j' => [3, 1 + $tm['yday'], false],
                        'M' => [2, $tm['min'], false],
                        'm' => [2, $tm['mon'] + 1, false],
                        'S' => [2, $tm['sec'], false],
                        'U' => [2, intdiv($tm['yday'] - $tm['wday'] + 7, 7), false],
                        'W' => [2, intdiv($tm['yday'] - ($tm['wday'] - 1 + 7) % 7 + 7, 7), false],
                        'w' => [1, $tm['wday'], false],
                    };
                    break;
                case 'n':
                    $result .= self::padded("\n", $width, $pad);
                    break;
                case 't':
                    $result .= self::padded("\t", $width, $pad);
                    break;
                case 'P':
                case 'p':
                    if ($specifier === 'P' || $changeCase) {
                        [$toUpper, $toLower] = [false, true];
                    }
                    $text = $tm['hour'] > 11 ? $locale['pm'] : $locale['am'];
                    $result .= self::copyPadded($text, $width, $pad, $toUpper, $toLower);
                    break;
                case 'R':
                    $subformat = '%H:%M';
                    break;
                case 'r':
                    $subformat = $locale['t_fmt_ampm'] !== '' ? $locale['t_fmt_ampm'] : '%I:%M:%S %p';
                    break;
                case 's':
                    // (a GNU extension) the fields as local time, as mktime reads them
                    $fields = $tm;
                    $seconds = CTime::mktime($fields) ?? -1;
                    $result .= self::signAndPadding((string) abs($seconds), $seconds < 0, 1, $pad, $width);
                    break;
                case 'X':
                    if ($modifier === 'O') {
                        $badFormat = true;
                        break;
                    }
                    $subformat = $modifier === 'E' && $locale['era_t_fmt'] !== ''
                        ? $locale['era_t_fmt'] : $locale['t_fmt'];
                    break;
                case 'T':
                    $subformat = '%H:%M:%S';
                    break;
                case 'u':
                    $number = [1, ($tm['wday'] - 1 + 7) % 7 + 1, false];
                    break;
                case 'V':
                case 'g':
                case 'G':
                    if ($modifier === 'E') {
                        $badFormat = true;
                        break;
                    }
                    $year = CTime::int32($tm['year'] + 1900);
                    $days = CTime::isoWeekDays($tm['yday'], $tm['wday']);
                    if ($days < 0) {
                        // this ISO week belongs to the previous year
                        $year = CTime::int32($year - 1);
                        $days = CTime::isoWeekDays($tm['yday'] + (365 + (CTime::isLeap($year) ? 1 : 0)), $tm['wday']);
                    } else {
                        $next = CTime::isoWeekDays($tm['yday'] - (365 + (CTime::isLeap($year) ? 1 : 0)), $tm['wday']);
                        if ($next >= 0) {
                            // this ISO week belongs to the next year
                            $year = CTime::int32($year + 1);
                            $days = $next;
                        }
                    }
                    $number = match ($specifier) {
                        'g' => [2, ($year % 100 + 100) % 100, false],
                        'G' => [1, $year, false],
                        default => [2, intdiv($days, 7) + 1, false],
                    };
                    break;
                case 'Y':
                    if ($modifier === 'E') {
                        $era = self::eraOf($tm, $locale);
                        if ($era !== null) {
                            $subformat = $era['format'];
                            if ($pad !== '') {
                                $yearSpec = $pad;
                            }
                            break;
                        }
                    }
                    if ($modifier === 'O') {
                        $badFormat = true;
                        break;
                    }
                    $number = [1, CTime::int32($tm['year'] + 1900), false];
                    break;
                case 'y':
                    if ($modifier === 'E') {
                        $era = self::eraOf($tm, $locale);
                        if ($era !== null) {
                            $delta = CTime::int32($tm['year'] - $era['start'][0]);
                            if ($yearSpec !== '') {
                                $pad = $yearSpec;
                            }
                            $number = [2, CTime::int32($era['offset'] + $delta * $era['absoluteDirection']), false];
                            break;
                        }
                    }
                    $number = [2, ($tm['year'] % 100 + 100) % 100, false];
                    break;
                case 'Z':
                    if ($changeCase) {
                        [$toUpper, $toLower] = [false, true];
                    }
                    $result .= self::copyPadded($tm['zone'], $width, $pad, $toUpper, $toLower);
                    break;
                case 'z':
                    if ($tm['isdst'] < 0) {
                        break;
                    }
                    $difference = $tm['gmtoff'];
                    $result .= self::padded($difference < 0 ? '-' : '+', $width, $pad);
                    $minutes = intdiv(abs($difference), 60);
                    $number = [4, intdiv($minutes, 60) * 100 + $minutes % 60, false];
                    break;
                case '':
                    // % at the end of the format: output it as it is
                    $f = $length - 1;
                    $badFormat = true;
                    break;
                default:
                    $badFormat = true;
                    break;
            }
            if ($badFormat) {
                // an unknown format: output it, '%' and all
                $text = substr($format, $percent, $f - $percent + 1);
                $result .= self::copyPadded($text, $width, $pad, $toUpper, $toLower);
            } elseif ($number !== null) {
                [$digits, $value, $spacePad] = $number;
                $result .= self::formatNumber($digits, $value, $spacePad, $pad, $width);
            } elseif ($subformat !== null) {
                $text = self::padded(self::formatTime($subformat, $tm, $locale, $yearSpec), $width, $pad);
                $result .= $toUpper ? strtoupper($text) : $text;
            }
        }
        return $result;
    }
}
