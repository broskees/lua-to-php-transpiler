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
     * offtime.c: __offtime: $t broken down $shift seconds east of UTC (the
     * UTC offset $gmtoff less the zone's leap second correction), with
     * $extraSeconds more seconds (a leap second itself); null if the year
     * does not fit in an int (EOVERFLOW).
     */
    private static function breakDown(
        int $t,
        int $shift,
        int $isdst,
        string $zone,
        int $gmtoff,
        int $extraSeconds,
    ): ?array {
        if ($t > self::TIME_LIMIT || $t < -self::TIME_LIMIT) {
            return null;
        }
        $local = $t + $shift;
        $days = intdiv($local, 86400);
        $seconds = $local - $days * 86400;
        if ($seconds < 0) {
            $days--;
            $seconds += 86400;
        }
        // the date (civilFromDays, inline: days of a 400-year era from March 1)
        $marchDays = $days + 719468;
        $era = intdiv($marchDays, 146097);
        if ($marchDays % 146097 < 0) {
            $era--;
        }
        $dayOfEra = $marchDays - $era * 146097;
        $yearOfEra = intdiv(
            $dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096),
            365,
        );
        $dayFromMarch = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $shiftedMonth = intdiv(5 * $dayFromMarch + 2, 153);
        $day = $dayFromMarch - intdiv(153 * $shiftedMonth + 2, 5) + 1;
        $month = $shiftedMonth < 10 ? $shiftedMonth + 3 : $shiftedMonth - 9;
        $year = $yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0);
        if ($year - 1900 > self::INT_MAX || $year - 1900 < self::INT_MIN) {
            return null;
        }
        // the day of the year: from March 1, past January and February (59 days, 60 in leap years)
        $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0) ? 1 : 0;
        $yday = $month <= 2 ? $dayFromMarch - 306 : $dayFromMarch + 59 + $leap;
        return [
            'year' => $year - 1900,
            'mon' => $month - 1,
            'mday' => $day,
            'hour' => intdiv($seconds, 3600),
            'min' => intdiv($seconds % 3600, 60),
            'sec' => $seconds % 60 + $extraSeconds,
            'wday' => (($days % 7) + 11) % 7,  // 1970-01-01 was a Thursday
            'yday' => $yday,
            'isdst' => $isdst,
            'gmtoff' => $gmtoff,
            'zone' => $zone,
        ];
    }

    /**
     * __offtime (t, 0, tp)'s 1900 + tp->tm_year for TimeZone (an int in C),
     * or null if the year does not fit in an int
     */
    public static function utcYear(int $t): ?int
    {
        if ($t > self::TIME_LIMIT || $t < -self::TIME_LIMIT) {
            return null;
        }
        $year = self::civilFromDays(self::floorDiv($t, 86400))[0];
        if ($year - 1900 > self::INT_MAX || $year - 1900 < self::INT_MIN) {
            return null;
        }
        return self::int32($year);
    }

    /** the time of January 1, 0:00 UTC, of $year */
    public static function utcYearStart(int $year): int
    {
        return self::daysFromCivil($year, 1, 1) * 86400;
    }

    /**
     * time.h: gmtime (tzset.c: __tz_convert in UTC, with a zone file's leap
     * seconds; null: not representable)
     */
    public static function gmtime(int $t): ?array
    {
        // [offset, isdst, zone name, leap second correction, extra leap seconds]
        $zone = TimeZone::convert($t, false);
        return $zone === null ? null : self::breakDown($t, $zone[0] - $zone[3], $zone[1], $zone[2], $zone[0], $zone[4]);
    }

    /** time.h: localtime (tzset.c: __tz_convert in the local zone; null: not representable) */
    public static function localtime(int $t): ?array
    {
        $zone = TimeZone::convert($t, true);
        return $zone === null ? null : self::breakDown($t, $zone[0] - $zone[3], $zone[1], $zone[2], $zone[0], $zone[4]);
    }

    /** C's int: $value wrapped to 32 bits, as gcc's arithmetic on int wraps */
    public static function int32(int $value): int
    {
        $value &= 0xFFFFFFFF;
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
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
        // intervening leap days, in C's int (no wrap: the years are ints, or an int plus
        // a twelfth of one, so these stay under 2^30)
        $a4 = ($year1 >> 2) + (1900 >> 2) - (($year1 & 3) === 0 ? 1 : 0);
        $b4 = ($year0 >> 2) + (1900 >> 2) - (($year0 & 3) === 0 ? 1 : 0);
        $a100 = intdiv($a4 + ($a4 < 0 ? 1 : 0), 25) - ($a4 < 0 ? 1 : 0);
        $b100 = intdiv($b4 + ($b4 < 0 ? 1 : 0), 25) - ($b4 < 0 ? 1 : 0);
        $interveningLeapDays = ($a4 - $b4) - ($a100 - $b100) + (($a100 >> 2) - ($b100 >> 2));
        $years = $year1 - $year0;
        $days = 365 * $years + $yday1 - $yday0 + $interveningLeapDays;
        $hours = 24 * $days + $hour1 - $hour0;
        $minutes = 60 * $hours + $min1 - $min0;
        return 60 * $minutes + $sec1 - $sec0;
    }

    /** mktime's seconds (70, 0, 0, 0, 0): the epoch, 1970-01-01 00:00:00 */
    private const EPOCH_SECONDS = 2248732800;

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
     * time.h: mktime (mktime.c: __mktime64, then __mktime_internal with
     * localtime: invert localtime by probing, starting from the offset of
     * the last call): the time of local broken-down time $tp, whose fields
     * may be out of range (isdst > 0 asks for daylight saving time, 0 for
     * standard time, < 0 for whatever applies). On success $tp is
     * normalized; null (-1 in C: EOVERFLOW) leaves it alone.
     */
    public static function mktime(array &$tp): ?int
    {
        TimeZone::tzset();  // POSIX: as if tzset had been called
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
        $isLeap = ($year & 3) === 0 && ($year % 100 !== 0 || (intdiv($year, 100) & 3) === ((-19) & 3));  // leapyear
        $leapRow = $isLeap ? 13 : 0;
        $monYday = self::MON_YDAY_FLAT[$leapRow + $monRemainder + 12 * $negativeMonRemainder] - 1;
        $yday = $monYday + $mday;
        $secRequested = $sec;
        // LEAP_SECONDS_POSSIBLE: out-of-range seconds, as ydhms_diff assumes 60 a minute
        if ($sec < 0) {
            $sec = 0;
        } elseif ($sec > 59) {
            $sec = 59;
        }
        // invert localtime by probing, first with the offset of the last call
        $negativeOffsetGuess = -self::$localtimeOffset;  // (in C an int: wrapped below if it must be)
        if ($negativeOffsetGuess > self::INT_MAX || $negativeOffsetGuess < self::INT_MIN) {
            $negativeOffsetGuess = self::int32($negativeOffsetGuess);
        }
        // ydhms_diff (A, B) is seconds (A) - seconds (B), with seconds (YEAR-YDAY
        // HOUR:MIN:SEC) from a fixed origin with ydhms_diff's leap days (in C's int,
        // which no int field makes wrap): the requested side once
        $by4 = ($year >> 2) + (1900 >> 2) - (($year & 3) === 0 ? 1 : 0);
        $by100 = intdiv($by4 + ($by4 < 0 ? 1 : 0), 25) - ($by4 < 0 ? 1 : 0);
        $days = 365 * $year + $yday + $by4 - $by100 + ($by100 >> 2);
        $requested = 60 * (60 * (24 * $days + $hour) + $min) + $sec;
        $t0 = $requested - self::EPOCH_SECONDS - $negativeOffsetGuess;  // ydhms_diff (..., 70, 0, 0, 0, guess)
        $t = $t1 = $t2 = $t0;
        // repeatedly use the error to improve the guess
        while (true) {
            $tm = self::localtime($t) ?? self::rangedConvert($t);  // (ranged_convert's first try, inline)
            if ($tm === null) {
                return null;
            }
            // tm_diff: $requested - seconds ($tm)
            $tmYear = $tm['year'];
            $by4 = ($tmYear >> 2) + (1900 >> 2) - (($tmYear & 3) === 0 ? 1 : 0);
            $by100 = intdiv($by4 + ($by4 < 0 ? 1 : 0), 25) - ($by4 < 0 ? 1 : 0);
            $days = 365 * $tmYear + $tm['yday'] + $by4 - $by100 + ($by100 >> 2);
            $dt = $requested - (60 * (60 * (24 * $days + $tm['hour']) + $tm['min']) + $tm['sec']);
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
        // a match: does tm_isdst have the requested value, if any? (isdst_differ)
        if (($isdst === 0) !== ($tm['isdst'] === 0) && $isdst >= 0 && $tm['isdst'] >= 0) {
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
        if ($secRequested === $tm['sec']) {  // (offset_found's common case, inline)
            self::$localtimeOffset = $t - $t0 - $negativeOffsetGuess;
            $tp = $tm;
            return $t;
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

    public static function isLeap(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }

    /** glibc strftime_l.c: iso_week_days */
    public static function isoWeekDays(int $yday, int $wday): int
    {
        $bigEnoughMultipleOf7 = (intdiv(366, 7) + 2) * 7;
        return $yday - ($yday - $wday + 4 + $bigEnoughMultipleOf7) % 7 + 4 - 1;
    }

    /**
     * time.h: strftime of $format into a buffer of $maxSize bytes (Lua
     * passes one conversion, "%Y" or "%Ec", and loslib.c's SIZETIMEFMT):
     * the text, or "" when it does not fit (C returns 0). The names and
     * the formats of %c, %x, %X and %r are those of $locale, the LC_TIME
     * locale (timeLocale(): null for the "C" locale).
     */
    public static function strftime(string $format, array $tm, int $maxSize, ?array $locale): string
    {
        $length = \strlen($format);
        if ($locale === null && ($length === 2 || ($length === 3 && self::isPlainInTheCLocale($format)))) {
            // one of loslib.c's conversions in the "C" locale, done directly as formatTime does it
            // (no era nor alternative digits there: %E and %O format as the plain conversion)
            $hour = $tm['hour'];
            switch ($format[$length - 1]) {
                case 'a':
                    return self::C_LOCALE['abday'][$tm['wday']];
                case 'A':
                    return self::C_LOCALE['day'][$tm['wday']];
                case 'b':
                case 'h':
                    return self::C_LOCALE['abmon'][$tm['mon']];
                case 'B':
                    return self::C_LOCALE['mon'][$tm['mon']];
                case 'c':  // "%a %b %e %H:%M:%S %Y"
                    return self::C_LOCALE['abday'][$tm['wday']] . ' ' . self::C_LOCALE['abmon'][$tm['mon']] . ' '
                        . ($tm['mday'] < 10 ? ' ' : '') . $tm['mday'] . ' ' . self::twoDigits($hour) . ':'
                        . self::twoDigits($tm['min']) . ':' . self::twoDigits($tm['sec']) . ' '
                        . self::int32($tm['year'] + 1900);
                case 'C':
                    $year = self::int32($tm['year'] + 1900);
                    return (string) (intdiv($year, 100) - ($year % 100 < 0 ? 1 : 0));
                case 'd':
                    return self::twoDigits($tm['mday']);
                case 'D':
                case 'x':  // "%m/%d/%y"
                    return self::twoDigits($tm['mon'] + 1) . '/' . self::twoDigits($tm['mday']) . '/'
                        . self::twoDigits(($tm['year'] % 100 + 100) % 100);
                case 'e':
                    return ($tm['mday'] < 10 ? ' ' : '') . $tm['mday'];
                case 'F':
                    return self::int32($tm['year'] + 1900) . '-' . self::twoDigits($tm['mon'] + 1) . '-'
                        . self::twoDigits($tm['mday']);
                case 'H':
                    return self::twoDigits($hour);
                case 'I':
                    return self::twoDigits($hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour));
                case 'j':
                    return sprintf('%03d', $tm['yday'] + 1);
                case 'm':
                    return self::twoDigits($tm['mon'] + 1);
                case 'M':
                    return self::twoDigits($tm['min']);
                case 'n':
                    return "\n";
                case 'p':
                    return $hour > 11 ? 'PM' : 'AM';
                case 'r':  // "%I:%M:%S %p"
                    return self::twoDigits($hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour)) . ':'
                        . self::twoDigits($tm['min']) . ':' . self::twoDigits($tm['sec'])
                        . ($hour > 11 ? ' PM' : ' AM');
                case 'R':
                    return self::twoDigits($hour) . ':' . self::twoDigits($tm['min']);
                case 'S':
                    return self::twoDigits($tm['sec']);
                case 't':
                    return "\t";
                case 'T':
                case 'X':  // "%H:%M:%S"
                    return self::twoDigits($hour) . ':' . self::twoDigits($tm['min']) . ':'
                        . self::twoDigits($tm['sec']);
                case 'u':
                    return (string) (($tm['wday'] - 1 + 7) % 7 + 1);
                case 'U':
                    return self::twoDigits(intdiv($tm['yday'] - $tm['wday'] + 7, 7));
                case 'w':
                    return (string) $tm['wday'];
                case 'W':
                    return self::twoDigits(intdiv($tm['yday'] - ($tm['wday'] - 1 + 7) % 7 + 7, 7));
                case 'y':
                    return self::twoDigits(($tm['year'] % 100 + 100) % 100);
                case 'Y':
                    return (string) self::int32($tm['year'] + 1900);
                case 'z':
                    if ($tm['isdst'] < 0) {
                        return '';
                    }
                    $minutes = intdiv(abs($tm['gmtoff']), 60);
                    $hoursAndMinutes = intdiv($minutes, 60) * 100 + $minutes % 60;
                    return ($tm['gmtoff'] < 0 ? '-' : '+') . sprintf('%04d', $hoursAndMinutes);
                case 'Z':
                    return $tm['zone'];
                case 'g':
                case 'G':
                case 'V':
                    // the ISO 8601 week-based year and week number
                    $year = self::int32($tm['year'] + 1900);
                    $days = self::isoWeekDays($tm['yday'], $tm['wday']);
                    if ($days < 0) {  // this ISO week belongs to the previous year
                        $year = self::int32($year - 1);
                        $days = self::isoWeekDays($tm['yday'] + 365 + (self::isLeap($year) ? 1 : 0), $tm['wday']);
                    } else {
                        $next = self::isoWeekDays($tm['yday'] - 365 - (self::isLeap($year) ? 1 : 0), $tm['wday']);
                        if ($next >= 0) {  // this ISO week belongs to the next year
                            $year = self::int32($year + 1);
                            $days = $next;
                        }
                    }
                    return match ($format[$length - 1]) {
                        'g' => self::twoDigits(($year % 100 + 100) % 100),
                        'G' => (string) $year,
                        default => self::twoDigits(intdiv($days, 7) + 1),
                    };
                case '%':
                    if ($length === 2) {
                        return '%';
                    }
                    break;
            }
        }
        $result = Strftime::formatTime($format, $tm, $locale ?? self::C_LOCALE, '');
        return \strlen($result) < $maxSize ? $result : '';
    }

    /** %E or %O of a conversion that has neither in the "C" locale (loslib.c allows only these) */
    private static function isPlainInTheCLocale(string $format): bool
    {
        $modifier = $format[1];
        $allowed = $modifier === 'E' ? 'cCxXyY' : ($modifier === 'O' ? 'deHImMSuUVwWy' : '');
        return str_contains($allowed, $format[2]);
    }

    /** a number of 0 to 99 in two digits (DO_NUMBER (2, ...)) */
    private static function twoDigits(int $value): string
    {
        return $value < 10 ? "0$value" : (string) $value;
    }

    // the "C" locale's LC_TIME (glibc locale/C-time.c), as timeLocale() gives a locale's items
    public const C_LOCALE = [
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

    /**
     * The LC_TIME items of the process's LC_TIME locale (as os.setlocale
     * set it; a C program starts in "C"): the "C" locale's for C and
     * POSIX, and when the host has no nl_langinfo (or setlocale); else
     * nl_langinfo's. What PHP's nl_langinfo cannot give: of the locale's
     * eras only the first (glibc keeps the list as strings one after
     * another, and PHP stops at the first NUL: dates of other eras format
     * %EC, %Ey and %EY as %C, %y and %Y), its alternative digits (the same
     * list of 100: %O formats decimal digits), and ABALTMON/ALTMON (%Ob,
     * %OB: the month names, as in locales without them). Null for the "C"
     * locale (C_LOCALE).
     *
     * @return array<string, mixed>|null
     */
    public static function timeLocale(): ?array
    {
        if (!\function_exists('nl_langinfo') || !\function_exists('setlocale')) {
            return null;
        }
        $name = setlocale(LC_TIME, '0');
        if (!\is_string($name) || $name === 'C' || $name === 'POSIX') {
            return null;
        }
        return Strftime::locale($name);
    }
}
