<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Os;

/**
 * The parts of C's <time.h> that loslib.c uses, as glibc implements them:
 * gmtime, localtime, mktime and strftime.
 *
 * A broken-down time (C: struct tm) is an array with the C field names:
 * year (years since 1900), mon (0-11), mday, hour, min, sec, wday (0 =
 * Sunday), yday (0-365), isdst (1, 0, or -1 = unknown), gmtoff (seconds
 * east of UTC) and zone (abbreviation).
 *
 * The local time zone is glibc's (TimeZone: TZ, else /etc/localtime);
 * PHP's own default time zone is not involved.
 *
 * @internal
 */
final class CTime
{
    private const INT_MAX = 2147483647;
    private const INT_MIN = -2147483648;

    /** beyond this many seconds from the epoch no year fits in an int */
    private const TIME_LIMIT = 1 << 57;

    // mktime.c: __mon_yday[2][13], normal years then leap years
    private const MON_YDAY_FLAT = [
        0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334, 365,
        0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335, 366,
    ];

    /**
     * mktime.c: the guess of the UTC offset that __mktime64 keeps from one
     * call to the next (static mktime_offset_t localtime_offset)
     */
    private static int $localtimeOffset = 0;

    private static function floorDiv(int $a, int $b): int
    {
        $quotient = intdiv($a, $b);
        if (($a % $b !== 0) && (($a < 0) !== ($b < 0))) {
            $quotient--;
        }
        return $quotient;
    }

    /** days since 1970-01-01 of a proleptic Gregorian date (month 1-12) */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = self::floorDiv($year, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;
        return $era * 146097 + $dayOfEra - 719468;
    }

    /**
     * The date of day number $days since 1970-01-01: [year, month 1-12, day].
     *
     * @return array{int, int, int}
     */
    private static function civilFromDays(int $days): array
    {
        $days += 719468;
        $era = self::floorDiv($days, 146097);
        $dayOfEra = $days - $era * 146097;
        $yearOfEra = intdiv(
            $dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096),
            365,
        );
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $shiftedMonth = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $shiftedMonth + 2, 5) + 1;
        $month = $shiftedMonth < 10 ? $shiftedMonth + 3 : $shiftedMonth - 9;
        return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    /**
     * Break down $t shifted by $offset seconds; null if the year does not
     * fit in an int (glibc: EOVERFLOW).
     */
    private static function breakDown(int $t, int $offset, int $isdst, string $zone): ?array
    {
        if ($t > self::TIME_LIMIT || $t < -self::TIME_LIMIT) {
            return null;
        }
        $local = $t + $offset;
        $days = self::floorDiv($local, 86400);
        $seconds = $local - $days * 86400;
        [$year, $month, $day] = self::civilFromDays($days);
        if ($year - 1900 > self::INT_MAX || $year - 1900 < self::INT_MIN) {
            return null;
        }
        return [
            'year' => $year - 1900,
            'mon' => $month - 1,
            'mday' => $day,
            'hour' => intdiv($seconds, 3600),
            'min' => intdiv($seconds % 3600, 60),
            'sec' => $seconds % 60,
            'wday' => (($days % 7) + 11) % 7,  // 1970-01-01 was a Thursday
            'yday' => $days - self::daysFromCivil($year, 1, 1),
            'isdst' => $isdst,
            'gmtoff' => $offset,
            'zone' => $zone,
        ];
    }

    /** time.h: gmtime (null: not representable) */
    public static function gmtime(int $t): ?array
    {
        return self::convert($t, false);
    }

    /** time.h: localtime (null: not representable) */
    public static function localtime(int $t): ?array
    {
        return self::convert($t, true);
    }

    /**
     * tzset.c: __tz_convert: $t broken down in local time or UTC, with
     * the zone's leap second correction (TimeZone::convert); null if the
     * year does not fit in an int.
     */
    private static function convert(int $t, bool $useLocaltime): ?array
    {
        $utc = self::breakDown($t, 0, 0, '');
        $zone = TimeZone::convert($t, $useLocaltime, $utc === null ? null : self::int32($utc['year'] + 1900));
        if ($zone === null) {
            return null;
        }
        [$offset, $isdst, $abbreviation, $leapCorrection, $leapExtraSeconds] = $zone;
        $tm = self::breakDown($t, $offset - $leapCorrection, $isdst, $abbreviation);
        if ($tm === null) {
            return null;
        }
        $tm['gmtoff'] = $offset;
        $tm['sec'] += $leapExtraSeconds;
        return $tm;
    }

    /**
     * time.h: mktime (mktime.c: __mktime64): the time of local
     * broken-down time $tm, whose fields may be out of range (isdst > 0
     * asks for daylight saving time, 0 for standard time, < 0 for
     * whatever applies). On success $tm is normalized; null (-1 in C:
     * EOVERFLOW) leaves it alone.
     */
    public static function mktime(array &$tm): ?int
    {
        TimeZone::tzset();  // POSIX: as if tzset had been called
        return self::mktimeInternal($tm);
    }

    /** C's int: $value wrapped to 32 bits, as gcc's arithmetic on int wraps */
    private static function int32(int $value): int
    {
        $value &= 0xFFFFFFFF;
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /** mktime.c: leapyear: is year $year + 1900 a leap year? */
    private static function tmYearIsLeap(int $year): bool
    {
        return ($year & 3) === 0 && ($year % 100 !== 0 || ((intdiv($year, 100)) & 3) === ((-19) & 3));
    }

    /** mktime.c: shr: $a >> $b rounding toward minus infinity */
    private static function shr(int $a, int $b): int
    {
        return $a >> $b;
    }

    /**
     * mktime.c: ydhms_diff: (YEAR1-YDAY1 HOUR1:MIN1:SEC1) - (YEAR0-YDAY0
     * HOUR0:MIN0:SEC0) in seconds, years as tm_year
     */
    private static function ydhmsDiff(
        int $year1,
        int $yday1,
        int $hour1,
        int $min1,
        int $sec1,
        int $year0,
        int $yday0,
        int $hour0,
        int $min0,
        int $sec0,
    ): int {
        // intervening leap days, in C's int
        $a4 = self::int32(self::shr($year1, 2) + self::shr(1900, 2) - (($year1 & 3) === 0 ? 1 : 0));
        $b4 = self::int32(self::shr($year0, 2) + self::shr(1900, 2) - (($year0 & 3) === 0 ? 1 : 0));
        $a100 = intdiv($a4 + ($a4 < 0 ? 1 : 0), 25) - ($a4 < 0 ? 1 : 0);
        $b100 = intdiv($b4 + ($b4 < 0 ? 1 : 0), 25) - ($b4 < 0 ? 1 : 0);
        $a400 = self::shr($a100, 2);
        $b400 = self::shr($b100, 2);
        $interveningLeapDays = self::int32(($a4 - $b4) - ($a100 - $b100) + ($a400 - $b400));
        $years = $year1 - $year0;
        $days = 365 * $years + $yday1 - $yday0 + $interveningLeapDays;
        $hours = 24 * $days + $hour1 - $hour0;
        $minutes = 60 * $hours + $min1 - $min0;
        return 60 * $minutes + $sec1 - $sec0;
    }

    /** mktime.c: tm_diff: (YEAR-YDAY HOUR:MIN:SEC) - $tm in seconds */
    private static function tmDiff(int $year, int $yday, int $hour, int $min, int $sec, array $tm): int
    {
        return self::ydhmsDiff(
            $year,
            $yday,
            $hour,
            $min,
            $sec,
            $tm['year'],
            $tm['yday'],
            $tm['hour'],
            $tm['min'],
            $tm['sec'],
        );
    }

    /**
     * mktime.c: ranged_convert: localtime of $t, or of the nearest time
     * to it that converts (a binary search toward 0); $t becomes that
     * time. Null if none does.
     */
    private static function rangedConvert(int &$t): ?array
    {
        $tm = self::localtime($t);
        if ($tm !== null) {
            return $tm;
        }
        $bad = $t;
        $ok = 0;
        $okTm = null;
        while (true) {
            $middle = self::shr($ok, 1) + self::shr($bad, 1) + (($ok | $bad) & 1);  // long_int_avg
            if ($middle === $ok || $middle === $bad) {
                break;
            }
            $middleTm = self::localtime($middle);
            if ($middleTm !== null) {
                [$ok, $okTm] = [$middle, $middleTm];
            } else {
                $bad = $middle;
            }
        }
        if ($okTm === null) {
            return null;
        }
        $t = $ok;
        return $okTm;
    }

    /** mktime.c: isdst_differ: one zero and the other positive */
    private static function isdstDiffer(int $a, int $b): bool
    {
        return ($a === 0) !== ($b === 0) && $a >= 0 && $b >= 0;
    }

    /**
     * mktime.c: __mktime_internal with localtime: invert localtime by
     * probing, starting from the offset of the last call.
     */
    private static function mktimeInternal(array &$tp): ?int
    {
        $remainingProbes = 6;
        $sec = $tp['sec'];
        $min = $tp['min'];
        $hour = $tp['hour'];
        $mday = $tp['mday'];
        $mon = $tp['mon'];
        $yearRequested = $tp['year'];
        $isdst = $tp['isdst'];
        $dst2 = 0;  // 1 if the previous probe was DST
        // ensure that mon is in range, and set year accordingly
        $monRemainder = $mon % 12;
        $negativeMonRemainder = $monRemainder < 0 ? 1 : 0;
        $monYears = intdiv($mon, 12) - $negativeMonRemainder;
        $year = $yearRequested + $monYears;
        // day of year from year, month, and day of month (need not be in range)
        $leapRow = self::tmYearIsLeap($year) ? 13 : 0;
        $monYday = self::MON_YDAY_FLAT[$leapRow + $monRemainder + 12 * $negativeMonRemainder] - 1;
        $yday = $monYday + $mday;
        $secRequested = $sec;
        // LEAP_SECONDS_POSSIBLE: out-of-range seconds, as ydhms_diff assumes 60 a minute
        $sec = max(0, min(59, $sec));
        // invert localtime by probing, first with the offset of the last call
        $negativeOffsetGuess = self::int32(-self::$localtimeOffset);
        $t0 = self::ydhmsDiff($year, $yday, $hour, $min, $sec, 70, 0, 0, 0, $negativeOffsetGuess);
        $t = $t1 = $t2 = $t0;
        // repeatedly use the error to improve the guess
        while (true) {
            $tm = self::rangedConvert($t);
            if ($tm === null) {
                return null;
            }
            $dt = self::tmDiff($year, $yday, $hour, $min, $sec, $tm);
            if ($dt === 0) {
                break;
            }
            if (
                $t === $t1 && $t !== $t2
                && ($tm['isdst'] < 0
                    || ($isdst < 0 ? $dst2 <= ($tm['isdst'] !== 0 ? 1 : 0) : ($isdst !== 0) !== ($tm['isdst'] !== 0)))
            ) {
                // oscillating between two values: a spring-forward gap; return a time DT away,
                // preferring the tm_isdst that differs from the requested one
                return self::offsetFound($tp, $t, $t0, $negativeOffsetGuess, $tm, $sec, $secRequested);
            }
            $remainingProbes--;
            if ($remainingProbes === 0) {
                return null;  // EOVERFLOW
            }
            [$t1, $t2, $t, $dst2] = [$t2, $t, $t + $dt, $tm['isdst'] !== 0 ? 1 : 0];
        }
        // a match: does tm_isdst have the requested value, if any?
        if (self::isdstDiffer($isdst, $tm['isdst'])) {
            // look for a neighboring time with the right isdst and use its UTC offset
            $dstDifference = ($isdst === 0 ? 1 : 0) - ($tm['isdst'] === 0 ? 1 : 0);
            $stride = 601200;
            $durationMax = 457243209;
            $deltaBound = intdiv($durationMax, 2) + $stride;
            for ($delta = $stride; $delta < $deltaBound; $delta += $stride) {
                for ($direction = -1; $direction <= 1; $direction += 2) {
                    $probe = $t + $delta * $direction;  // (no overflow: $t is far from the limits)
                    $probeTm = self::rangedConvert($probe);
                    if ($probeTm === null) {
                        return null;
                    }
                    if (!self::isdstDiffer($isdst, $probeTm['isdst'])) {
                        // the desired tm_isdst: extrapolate back to the desired time
                        $goal = $probe + self::tmDiff($year, $yday, $hour, $min, $sec, $probeTm);
                        $goalTm = self::localtime($goal);
                        if ($goalTm !== null) {
                            $t = $goal;
                            $tm = $goalTm;
                            return self::offsetFound($tp, $t, $t0, $negativeOffsetGuess, $tm, $sec, $secRequested);
                        }
                    }
                }
            }
            // no unusual DST offset nearby: assume one-hour DST
            $t += 60 * 60 * $dstDifference;
            $tm = self::localtime($t);
            if ($tm === null) {
                return null;
            }
        }
        return self::offsetFound($tp, $t, $t0, $negativeOffsetGuess, $tm, $sec, $secRequested);
    }

    /**
     * mktime.c: __mktime_internal's offset_found: remember the offset for
     * the next call, give the requested seconds back, normalize $tp
     */
    private static function offsetFound(
        array &$tp,
        int $t,
        int $t0,
        int $negativeOffsetGuess,
        array $tm,
        int $sec,
        int $secRequested,
    ): ?int {
        self::$localtimeOffset = $t - $t0 - $negativeOffsetGuess;
        if ($secRequested !== $tm['sec']) {
            // the requested tm_sec, not the normalized one; repair a false match on a leap second
            $secAdjustment = ($sec === 0 && $tm['sec'] === 60 ? 1 : 0) - $sec + $secRequested;
            $t += $secAdjustment;
            $tm = self::localtime($t);
            if ($tm === null) {
                return null;
            }
        }
        $tp = $tm;
        return $t;
    }

    private static function isLeap(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }

    /** glibc strftime_l.c: iso_week_days */
    private static function isoWeekDays(int $yday, int $wday): int
    {
        $bigEnoughMultipleOf7 = (intdiv(366, 7) + 2) * 7;
        return $yday - ($yday - $wday + 4 + $bigEnoughMultipleOf7) % 7 + 4 - 1;
    }

    /**
     * time.h: strftime of $format into a buffer of $maxSize bytes (Lua
     * passes one conversion, "%Y" or "%Ec", and loslib.c's SIZETIMEFMT):
     * the text, or "" when it does not fit (C returns 0). The names and
     * the formats of %c, %x, %X and %r are the LC_TIME locale's
     * (timeLocale).
     */
    public static function strftime(string $format, array $tm, int $maxSize): string
    {
        $result = self::formatTime($format, $tm, self::timeLocale(), '');
        return \strlen($result) < $maxSize ? $result : '';
    }

    // the "C" locale's LC_TIME (glibc locale/C-time.c), as timeLocale() gives a locale's items
    private const C_LOCALE = [
        'abday' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        'day' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'abmon' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'mon' => [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ],
        'am' => 'AM',
        'pm' => 'PM',
        'd_t_fmt' => '%a %b %e %H:%M:%S %Y',
        'd_fmt' => '%m/%d/%y',
        't_fmt' => '%H:%M:%S',
        't_fmt_ampm' => '%I:%M:%S %p',
        'era' => [],
        'era_d_t_fmt' => '',
        'era_d_fmt' => '',
        'era_t_fmt' => '',
    ];

    /** @var array<string, array<string, mixed>> the LC_TIME items of the locales used, by name */
    private static array $locales = [];

    /**
     * The LC_TIME items of the process's LC_TIME locale (as os.setlocale
     * set it; a C program starts in "C"): the "C" locale's for C and
     * POSIX, and when the host has no nl_langinfo (or setlocale); else
     * nl_langinfo's. What PHP's nl_langinfo cannot give: of the locale's
     * eras only the first (glibc keeps the list as strings one after
     * another, and PHP stops at the first NUL: dates of other eras format
     * %EC, %Ey and %EY as %C, %y and %Y), its alternative digits (the same
     * list of 100: %O formats decimal digits), and ABALTMON/ALTMON (%Ob,
     * %OB: the month names, as in locales without them).
     *
     * @return array<string, mixed>
     */
    private static function timeLocale(): array
    {
        if (!\function_exists('nl_langinfo') || !\function_exists('setlocale')) {
            return self::C_LOCALE;
        }
        $name = setlocale(LC_TIME, '0');
        if (!\is_string($name) || $name === 'C' || $name === 'POSIX') {
            return self::C_LOCALE;
        }
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
            'offset' => self::int32((int) $fields[2]),
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
        $year = self::int32($year - 1900);
        if ($year < -1900) {
            $year++;
        }
        return [$year, self::int32($month - 1), self::int32($day)];
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
    private static function formatTime(string $format, array $tm, array $locale, string $yearSpec): string
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
                    $year = self::int32($tm['year'] + 1900);
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
                    $seconds = self::mktime($fields) ?? -1;
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
                    $year = self::int32($tm['year'] + 1900);
                    $days = self::isoWeekDays($tm['yday'], $tm['wday']);
                    if ($days < 0) {
                        // this ISO week belongs to the previous year
                        $year = self::int32($year - 1);
                        $days = self::isoWeekDays($tm['yday'] + (365 + (self::isLeap($year) ? 1 : 0)), $tm['wday']);
                    } else {
                        $next = self::isoWeekDays($tm['yday'] - (365 + (self::isLeap($year) ? 1 : 0)), $tm['wday']);
                        if ($next >= 0) {
                            // this ISO week belongs to the next year
                            $year = self::int32($year + 1);
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
                    $number = [1, self::int32($tm['year'] + 1900), false];
                    break;
                case 'y':
                    if ($modifier === 'E') {
                        $era = self::eraOf($tm, $locale);
                        if ($era !== null) {
                            $delta = self::int32($tm['year'] - $era['start'][0]);
                            if ($yearSpec !== '') {
                                $pad = $yearSpec;
                            }
                            $number = [2, self::int32($era['offset'] + $delta * $era['absoluteDirection']), false];
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
