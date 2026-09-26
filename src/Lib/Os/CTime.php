<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Os;

/**
 * The parts of C's <time.h> that loslib.c uses, as glibc behaves in the
 * "C" locale: gmtime, localtime, mktime and strftime.
 *
 * A broken-down time (C: struct tm) is an array with the C field names:
 * year (years since 1900), mon (0-11), mday, hour, min, sec, wday (0 =
 * Sunday), yday (0-365), isdst (1, 0, or -1 = unknown), gmtoff (seconds
 * east of UTC) and zone (abbreviation).
 *
 * The local time zone is the one C uses: TZ, else /etc/localtime; PHP's
 * own default time zone is not involved.
 *
 * @internal
 */
final class CTime
{
    private const INT_MAX = 2147483647;
    private const INT_MIN = -2147483648;

    /** beyond this many seconds from the epoch no year fits in an int */
    private const TIME_LIMIT = 1 << 57;

    private const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    private const MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /** @var array{\DateTimeZone|null}|null the local zone (null inside: UTC) */
    private static ?array $localZone = null;

    /** the local time zone, or null for UTC (glibc: TZ unset, empty or unknown) */
    private static function localZone(): ?\DateTimeZone
    {
        if (self::$localZone !== null) {
            return self::$localZone[0];
        }
        $name = getenv('TZ');
        if ($name === false) {
            $link = @readlink('/etc/localtime');
            $name = '';
            if ($link !== false && ($position = strpos($link, 'zoneinfo/')) !== false) {
                $name = substr($link, $position + \strlen('zoneinfo/'));
            }
        } else {
            $name = ltrim($name, ':');
            if (($position = strpos($name, 'zoneinfo/')) !== false) {
                $name = substr($name, $position + \strlen('zoneinfo/'));
            }
        }
        $zone = null;
        if ($name !== '' && strtoupper($name) !== 'UTC') {
            try {
                $zone = new \DateTimeZone($name);
            } catch (\Exception) {
                $zone = null;
            }
        }
        self::$localZone = [$zone];
        return $zone;
    }

    /**
     * The local zone's rules at time $t: [UTC offset, isdst, abbreviation].
     *
     * @return array{int, int, string}
     */
    private static function localRules(int $t): array
    {
        $zone = self::localZone();
        if ($zone === null) {
            return [0, 0, 'UTC'];
        }
        $transition = $zone->getTransitions($t, $t)[0] ?? null;
        if ($transition === null) {
            return [0, 0, 'UTC'];
        }
        return [$transition['offset'], $transition['isdst'] ? 1 : 0, $transition['abbr']];
    }

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
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
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
        return self::breakDown($t, 0, 0, 'GMT');
    }

    /** time.h: localtime (null: not representable) */
    public static function localtime(int $t): ?array
    {
        if ($t > self::TIME_LIMIT || $t < -self::TIME_LIMIT) {
            return null;
        }
        [$offset, $isdst, $abbreviation] = self::localRules($t);
        return self::breakDown($t, $offset, $isdst, $abbreviation);
    }

    /**
     * time.h: mktime: the time of local broken-down time $tm (fields may
     * be out of range; isdst > 0 asks for daylight saving time, 0 for
     * standard time, < 0 for whatever applies). $tm is normalized in
     * place. Returns null if the result is not representable.
     */
    public static function mktime(array &$tm): ?int
    {
        $year = $tm['year'] + 1900 + self::floorDiv($tm['mon'], 12);
        $month = $tm['mon'] - self::floorDiv($tm['mon'], 12) * 12;
        $days = self::daysFromCivil($year, $month + 1, 1) + $tm['mday'] - 1;
        $wallClock = $days * 86400 + $tm['hour'] * 3600 + $tm['min'] * 60 + $tm['sec'];  // as if UTC
        if ($wallClock > self::TIME_LIMIT || $wallClock < -self::TIME_LIMIT) {
            return null;
        }
        $t = self::localTimeOfWallClock($wallClock, $tm['isdst']);
        $normalized = self::localtime($t);
        if ($normalized === null) {
            return null;
        }
        $tm = $normalized;
        return $t;
    }

    /**
     * The time whose local wall clock reads $wallClock (seconds as if
     * UTC), like glibc's __mktime_internal: converge on the UTC offset
     * (see below for a wall clock in a spring-forward gap); if $isdst
     * (>= 0) disagrees with the result, use the offset of the nearest
     * time (probing a week at a time) that agrees.
     */
    private static function localTimeOfWallClock(int $wallClock, int $isdst): int
    {
        if (self::localZone() === null) {
            return $wallClock;
        }
        $t = $wallClock - self::localRules($wallClock)[0];
        $previous = null;
        for ($iteration = 0; $iteration < 4; $iteration++) {
            $next = $wallClock - self::localRules($t)[0];
            if ($next === $t) {
                break;
            }
            if ($next === $previous) {
                // oscillating: the wall clock is in a spring-forward gap. glibc takes the
                // candidate whose isdst differs from the requested one (if none was
                // requested, the one with daylight saving time), and no further search
                $wanted = $isdst < 0 ? 1 : ($isdst > 0 ? 0 : 1);
                if (self::localRules($t)[1] === $wanted) {
                    return $t;
                }
                return self::localRules($next)[1] === $wanted ? $next : max($t, $next);
            }
            $previous = $t;
            $t = $next;
        }
        if ($isdst < 0 || self::localRules($t)[1] === ($isdst > 0 ? 1 : 0)) {
            return $t;
        }
        // glibc: look for a neighboring time with the requested isdst and use its UTC offset
        $stride = 601200;
        $deltaBound = intdiv(536454000, 2) + $stride;
        for ($delta = $stride; $delta < $deltaBound; $delta += $stride) {
            foreach ([-1, 1] as $direction) {
                [$offset, $probeIsdst] = self::localRules($t + $delta * $direction);
                if ($probeIsdst === ($isdst > 0 ? 1 : 0)) {
                    return $wallClock - $offset;
                }
            }
        }
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
     * ISO 8601 week-based year and week number of $tm.
     *
     * @return array{int, int}
     */
    private static function isoWeek(array $tm): array
    {
        $year = $tm['year'] + 1900;
        $days = self::isoWeekDays($tm['yday'], $tm['wday']);
        if ($days < 0) {  // this ISO week belongs to the previous year
            $year--;
            $days = self::isoWeekDays($tm['yday'] + (365 + (self::isLeap($year) ? 1 : 0)), $tm['wday']);
        } else {
            $nextYearDays = self::isoWeekDays($tm['yday'] - (365 + (self::isLeap($year) ? 1 : 0)), $tm['wday']);
            if ($nextYearDays >= 0) {  // this ISO week belongs to the next year
                $year++;
                $days = $nextYearDays;
            }
        }
        return [$year, intdiv($days, 7) + 1];
    }

    private static function twoDigits(int $value): string
    {
        return sprintf('%02d', $value);
    }

    /**
     * time.h: strftime of one conversion ("%Y", "%Ec", ...; one of
     * loslib.c's LUA_STRFTIMEOPTIONS) in the "C" locale. Years are not
     * zero-padded (%Y, %C, %G), two-digit years are floor modulo 100.
     */
    public static function strftime(string $conversion, array $tm): string
    {
        $year = $tm['year'] + 1900;
        $specifier = substr($conversion, -1);  // 'E' and 'O' change nothing in the "C" locale
        switch ($specifier) {
            case 'a':
                return substr(self::WEEKDAY_NAMES[$tm['wday']], 0, 3);
            case 'A':
                return self::WEEKDAY_NAMES[$tm['wday']];
            case 'b':
            case 'h':
                return substr(self::MONTH_NAMES[$tm['mon']], 0, 3);
            case 'B':
                return self::MONTH_NAMES[$tm['mon']];
            case 'c':
                return self::strftime('%a', $tm) . ' ' . self::strftime('%b', $tm) . ' ' . self::strftime('%e', $tm)
                    . ' ' . self::strftime('%T', $tm) . ' ' . $year;
            case 'C':
                return (string) self::floorDiv($year, 100);
            case 'd':
                return self::twoDigits($tm['mday']);
            case 'D':
            case 'x':
                return self::twoDigits($tm['mon'] + 1) . '/' . self::twoDigits($tm['mday']) . '/' . self::strftime('%y', $tm);
            case 'e':
                return sprintf('%2d', $tm['mday']);
            case 'F':
                return $year . '-' . self::twoDigits($tm['mon'] + 1) . '-' . self::twoDigits($tm['mday']);
            case 'g':
                return self::twoDigits(self::isoWeek($tm)[0] - self::floorDiv(self::isoWeek($tm)[0], 100) * 100);
            case 'G':
                return (string) self::isoWeek($tm)[0];
            case 'H':
                return self::twoDigits($tm['hour']);
            case 'I':
                return self::twoDigits(($tm['hour'] % 12) === 0 ? 12 : $tm['hour'] % 12);
            case 'j':
                return sprintf('%03d', $tm['yday'] + 1);
            case 'm':
                return self::twoDigits($tm['mon'] + 1);
            case 'M':
                return self::twoDigits($tm['min']);
            case 'n':
                return "\n";
            case 'p':
                return $tm['hour'] > 11 ? 'PM' : 'AM';
            case 'r':
                return self::strftime('%I', $tm) . ':' . self::twoDigits($tm['min']) . ':' . self::twoDigits($tm['sec'])
                    . ' ' . self::strftime('%p', $tm);
            case 'R':
                return self::twoDigits($tm['hour']) . ':' . self::twoDigits($tm['min']);
            case 'S':
                return self::twoDigits($tm['sec']);
            case 't':
                return "\t";
            case 'T':
            case 'X':
                return self::twoDigits($tm['hour']) . ':' . self::twoDigits($tm['min']) . ':' . self::twoDigits($tm['sec']);
            case 'u':
                return (string) (($tm['wday'] + 6) % 7 + 1);
            case 'U':
                return self::twoDigits(intdiv($tm['yday'] - $tm['wday'] + 7, 7));
            case 'V':
                return self::twoDigits(self::isoWeek($tm)[1]);
            case 'w':
                return (string) $tm['wday'];
            case 'W':
                return self::twoDigits(intdiv($tm['yday'] - ($tm['wday'] + 6) % 7 + 7, 7));
            case 'y':
                return self::twoDigits($year - self::floorDiv($year, 100) * 100);
            case 'Y':
                return (string) $year;
            case 'z':
                $minutes = intdiv(abs($tm['gmtoff']), 60);
                return ($tm['gmtoff'] < 0 ? '-' : '+') . self::twoDigits(intdiv($minutes, 60)) . self::twoDigits($minutes % 60);
            case 'Z':
                return $tm['zone'];
            default:  // '%'
                return '%';
        }
    }
}
