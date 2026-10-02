<?php

declare(strict_types=1);

namespace LuaPhp\Lib\Os;

use LuaPhp\Lib\Io\CFile;

/**
 * The local time zone as glibc keeps it: port of tzset.c (TZ: a zone file,
 * else a POSIX TZ string, else UTC; __tzset_parse_tz, compute_change,
 * __tz_compute) and tzfile.c (TZif zone files: __tzfile_read,
 * __tzfile_default, __tzfile_compute, leap seconds).
 *
 * Like glibc's, the state is the process's and changes as glibc's does:
 * TZ is read on first use, mktime reads it again (tzset), a TZ string
 * without rules takes the transitions of zoneinfo's posixrules and is read
 * anew at every tzset, and times after a zone file's last transition use
 * the POSIX TZ string at its end. Arithmetic that is C's 32-bit int
 * arithmetic wraps as C's does (int32()).
 *
 * @internal
 */
final class TimeZone
{
    // tzfile.h / glibc's configuration
    private const TZDEFAULT = '/etc/localtime';
    private const TZDIR = '/usr/share/zoneinfo';
    private const TZDEFRULES = 'posixrules';

    // tzset.c: SECSPERDAY
    private const SECSPERDAY = 86400;

    // tzset.c: tz_rule.type
    private const J0 = 0;
    private const J1 = 1;
    private const M = 2;

    // mktime.c: __mon_yday[2][13], row after row (normal years, then leap years)
    private const MON_YDAY_FLAT = [
        0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334, 365,
        0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335, 366,
    ];

    // tzset.c: tzset_internal's is_initialized and old_tz
    private static bool $isInitialized = false;
    private static ?string $oldTz = null;

    /**
     * tzset.c: tz_rules[0] (standard) and [1] (daylight saving): name,
     * type, m, n, d, secs, offset, change, computedFor
     *
     * @var array{array<string, mixed>, array<string, mixed>}
     */
    private static array $rules = [[], []];

    // tzfile.c: the zone file in use
    private static bool $useTzfile = false;
    /** @var array{int, int, int}|null tzfile_dev, tzfile_ino, tzfile_mtime */
    private static ?array $tzfileStatus = null;
    /** @var list<int> */
    private static array $transitions = [];
    /** @var list<int> type of each transition */
    private static array $typeIndexes = [];
    /** @var list<array{offset: int, isdst: int, idx: int, isstd: bool, isgmt: bool}> */
    private static array $types = [];
    private static string $zoneNames = '';
    /** @var list<array{int, int}> leap second transitions and corrections */
    private static array $leaps = [];
    private static ?string $tzspec = null;
    private static int $ruleStdoff = 0;
    private static int $ruleDstoff = 0;

    /** C's int: $value wrapped to 32 bits, as gcc's arithmetic on int wraps */
    private static function int32(int $value): int
    {
        $value &= 0xFFFFFFFF;
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private static function isLeap(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }

    /** time.h: tzset (tzset.c: __tzset, tzset_internal (1)) */
    public static function tzset(): void
    {
        self::tzsetInternal(true);
    }

    /**
     * tzset.c: __tz_convert's time zone part for $timer (C's localtime_r
     * and gmtime_r: tzset_internal (0)): [UTC offset, isdst, zone name,
     * leap second correction, extra leap seconds] for local time, or for
     * UTC [0, 0, "GMT", correction, extra]. $utcYear is the year of $timer
     * in UTC (null: not representable: __offtime failed). Null when the
     * time cannot be converted.
     *
     * @return array{int, int, string, int, int}|null
     */
    public static function convert(int $timer, bool $useLocaltime, ?int $utcYear): ?array
    {
        self::tzsetInternal(false);
        if (self::$useTzfile) {
            [$offset, $isdst, $zone, $leapCorrection, $leapHit] = self::tzfileCompute($timer, $useLocaltime, $utcYear);
        } else {
            if ($utcYear === null) {
                return null;
            }
            [$isdst, $zone, $offset] = self::tzCompute($timer, $utcYear);
            $leapCorrection = 0;
            $leapHit = 0;
        }
        if (!$useLocaltime) {
            return [0, 0, 'GMT', $leapCorrection, $leapHit];
        }
        return [$offset, $isdst, $zone, $leapCorrection, $leapHit];
    }

    /** tzset.c: tzset_internal: read TZ (again only if $always and it changed) */
    private static function tzsetInternal(bool $always): void
    {
        if (self::$isInitialized && !$always) {
            return;
        }
        self::$isInitialized = true;
        // examine the TZ environment variable
        $tz = getenv('TZ');
        $tz = $tz === false ? null : $tz;
        if ($tz === '') {
            $tz = 'Universal';  // user specified the empty string; use UTC explicitly
        }
        // a leading colon means "implementation defined syntax": ignored; try a data file, then POSIX
        if ($tz !== null && str_starts_with($tz, ':')) {
            $tz = substr($tz, 1);
        }
        if (self::$oldTz !== null && $tz !== null && $tz === self::$oldTz) {
            return;  // no change, simply return
        }
        $tz ??= self::TZDEFAULT;  // no user specification; use the site-wide default
        self::$rules[0]['name'] = null;
        self::$rules[1]['name'] = null;
        self::$oldTz = $tz;  // save the value of 'tz'
        self::tzfileRead($tz);  // try to read a data file
        if (self::$useTzfile) {
            return;
        }
        // no data file found: default to UTC if nothing specified
        if ($tz === '' || $tz === self::TZDEFAULT) {
            self::$rules = [self::zeroRule('UTC'), self::zeroRule('UTC')];
            self::$rules[0]['change'] = self::$rules[1]['change'] = -1;
            return;
        }
        self::tzsetParseTz($tz);
    }

    /** a tz_rule cleared by memset, named $name */
    private static function zeroRule(string $name): array
    {
        return ['name' => $name, 'type' => self::J0, 'm' => 0, 'n' => 0, 'd' => 0, 'secs' => 0,
            'offset' => 0, 'change' => 0, 'computedFor' => 0];
    }

    /** $text up to its first NUL byte (what C sees of it) */
    private static function cString(string $text): string
    {
        $end = strpos($text, "\0");
        return $end === false ? $text : substr($text, 0, $end);
    }

    /*
    ** {======================================================
    ** POSIX TZ strings (tzset.c)
    ** =======================================================
    */

    /** tzset.c: compute_offset */
    private static function computeOffset(int $ss, int $mm, int $hh): int
    {
        return min($ss, 59) + min($mm, 59) * 60 + min($hh, 24) * 60 * 60;
    }

    /**
     * sscanf's %hu at $position of $text: white space, a sign, decimal
     * digits, converted by strtoul (ULONG_MAX when too big, negated with
     * '-') and cut to an unsigned short. Null when there are no digits;
     * else $position moves past the number.
     */
    private static function scanUnsignedShort(string $text, int &$position): ?int
    {
        $at = $position + strspn($text, " \t\n\v\f\r", $position);
        $sign = $text[$at] ?? '';
        if ($sign === '+' || $sign === '-') {
            $at++;
        }
        $digitCount = strspn($text, '0123456789', $at);
        if ($digitCount === 0) {
            return null;
        }
        $digits = ltrim(substr($text, $at, $digitCount), '0');
        $position = $at + $digitCount;
        if (\strlen($digits) > 20 || (\strlen($digits) === 20 && strcmp($digits, '18446744073709551615') > 0)) {
            return 0xFFFF;  // ULONG_MAX (not negated)
        }
        $value = 0;  // the number modulo 2^16
        $length = \strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $value = ($value * 10 + \ord($digits[$i]) - 48) & 0xFFFF;
        }
        return $sign === '-' ? -$value & 0xFFFF : $value;
    }

    /**
     * sscanf ($text + $position, "%hu%n:%hu%n:%hu%n", ...): up to three
     * numbers separated by ':'. Returns [values found (0 to 3), consumed]
     * where consumed counts the characters up to the last number read.
     *
     * @return array{list<int>, int}
     */
    private static function scanHoursMinutesSeconds(string $text, int $position): array
    {
        $values = [];
        $consumed = 0;
        $at = $position;
        for ($field = 0; $field < 3; $field++) {
            if ($field > 0) {
                if (($text[$at] ?? '') !== ':') {
                    break;
                }
                $at++;
            }
            $value = self::scanUnsignedShort($text, $at);
            if ($value === null) {
                break;
            }
            $values[] = $value;
            $consumed = $at - $position;
        }
        return [$values, $consumed];
    }

    /** tzset.c: parse_tzname: a name of 3+ letters, or <3+ letters, digits, '+' or '-'> */
    private static function parseTzname(string $tz, int &$position, int $whichRule): bool
    {
        $start = $position;
        $length = strspn($tz, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $start);
        $end = $start + $length;
        if ($length < 3) {
            if (($tz[$position] ?? '') !== '<') {
                return false;
            }
            $start = $position + 1;
            $length = strspn($tz, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+-', $start);
            if (($tz[$start + $length] ?? '') !== '>' || $length < 3) {
                return false;
            }
            $end = $start + $length + 1;
        }
        self::$rules[$whichRule]['name'] = substr($tz, $start, $length);
        $position = $end;
        return true;
    }

    /** tzset.c: parse_offset: [+|-]hh[:mm[:ss]], west of UTC positive */
    private static function parseOffset(string $tz, int &$position, int $whichRule): bool
    {
        $character = $tz[$position] ?? '';
        $startsOffset = $character === '+' || $character === '-' || ctype_digit($character);
        if ($whichRule === 0 && !$startsOffset) {
            return false;
        }
        $sign = -1;
        if ($character === '-' || $character === '+') {
            $sign = $character === '-' ? 1 : -1;
            $position++;
        }
        [$values, $consumed] = self::scanHoursMinutesSeconds($tz, $position);
        if ($values !== []) {
            [$hours, $minutes, $seconds] = $values + [1 => 0, 2 => 0];
            self::$rules[$whichRule]['offset'] = $sign * self::computeOffset($seconds, $minutes, $hours);
        } elseif ($whichRule === 0) {
            self::$rules[0]['offset'] = 0;  // standard time defaults to offset zero
            return false;
        } else {
            self::$rules[1]['offset'] = self::$rules[0]['offset'] + 60 * 60;  // DST: one hour later than standard
        }
        $position += $consumed;
        return true;
    }

    /** tzset.c: parse_rule: Jn, n or Mm.w.d, then /time (default 2:00) */
    private static function parseRule(string $tz, int &$position, int $whichRule): bool
    {
        $at = $position;
        $rule = &self::$rules[$whichRule];
        if (($tz[$at] ?? '') === ',') {
            $at++;  // ignore comma (early POSIX.1 printings)
        }
        $character = $tz[$at] ?? '';
        if ($character === 'J' || ctype_digit($character)) {
            $rule['type'] = $character === 'J' ? self::J1 : self::J0;
            if ($rule['type'] === self::J1 && !ctype_digit($tz[++$at] ?? '')) {
                return false;
            }
            $digits = strspn($tz, '0123456789', $at);
            $number = ltrim(substr($tz, $at, $digits), '0');
            if ($digits === 0 || \strlen($number) > 3 || (int) $number > 365) {
                return false;
            }
            if ($rule['type'] === self::J1 && (int) $number === 0) {
                return false;
            }
            $rule['d'] = (int) $number;
            $at += $digits;
        } elseif ($character === 'M') {
            // sscanf (tz, "M%hu.%hu.%hu%n", &m, &n, &d, &consumed) == 3, assigning what it reads
            $rule['type'] = self::M;
            $scan = $at + 1;
            $fields = [];
            foreach (['m', 'n', 'd'] as $index => $field) {
                if ($index > 0) {
                    if (($tz[$scan] ?? '') !== '.') {
                        break;
                    }
                    $scan++;
                }
                $value = self::scanUnsignedShort($tz, $scan);
                if ($value === null) {
                    break;
                }
                $rule[$field] = $fields[$field] = $value;
            }
            if (
                \count($fields) !== 3 || $rule['m'] < 1 || $rule['m'] > 12 || $rule['n'] < 1 || $rule['n'] > 5
                || $rule['d'] > 6
            ) {
                return false;
            }
            $at = $scan;
        } elseif ($character === '') {
            // the U.S. rules of the Energy Policy Act of 2005: "M3.2.0,M11.1.0"
            $rule['type'] = self::M;
            if ($whichRule === 0) {
                [$rule['m'], $rule['n'], $rule['d']] = [3, 2, 0];
            } else {
                [$rule['m'], $rule['n'], $rule['d']] = [11, 1, 0];
            }
        } else {
            return false;
        }
        $character = $tz[$at] ?? '';
        if ($character !== '' && $character !== '/' && $character !== ',') {
            return false;
        } elseif ($character === '/') {
            // the time of day of the change
            $at++;
            if (($tz[$at] ?? '') === '') {
                return false;
            }
            $negative = $tz[$at] === '-';
            $at += $negative ? 1 : 0;
            [$values, $consumed] = self::scanHoursMinutesSeconds($tz, $at);
            $at += $consumed;
            [$hours, $minutes, $seconds] = $values + [2, 0, 0];  // (2:00 AM unless given)
            $rule['secs'] = ($negative ? -1 : 1) * ($hours * 60 * 60 + $minutes * 60 + $seconds);
        } else {
            $rule['secs'] = 2 * 60 * 60;  // default to 2:00 AM
        }
        $rule['computedFor'] = -1;
        $position = $at;
        return true;
    }

    /** tzset.c: __tzset_parse_tz */
    private static function tzsetParseTz(string $tz): void
    {
        // clear out old state and reset to unnamed UTC
        self::$rules = [self::zeroRule(''), self::zeroRule('')];
        $position = 0;
        // the standard time zone abbreviation and offset
        if (!self::parseTzname($tz, $position, 0) || !self::parseOffset($tz, $position, 0)) {
            return;
        }
        if ($position >= \strlen($tz)) {
            // there is no DST
            self::$rules[1]['name'] = self::$rules[0]['name'];
            self::$rules[1]['offset'] = self::$rules[0]['offset'];
            return;
        }
        // the DST time zone abbreviation (if any)
        if (self::parseTzname($tz, $position, 1)) {
            self::parseOffset($tz, $position, 1);
            $rest = substr($tz, $position);
            if ($rest === '' || $rest === ',') {
                // there is no rule: see if there is a default rule file
                self::tzfileDefault(
                    self::$rules[0]['name'],
                    self::$rules[1]['name'],
                    self::$rules[0]['offset'],
                    self::$rules[1]['offset'],
                );
                if (self::$useTzfile) {
                    self::$oldTz = null;
                    return;
                }
            }
        }
        // figure out the standard <-> DST rules
        if (self::parseRule($tz, $position, 0)) {
            self::parseRule($tz, $position, 1);
        }
    }

    /** tzset.c: compute_change: when in $year the change of rule $which happens */
    private static function computeChange(int $which, int $year): void
    {
        $rule = &self::$rules[$which];
        if ($year !== -1 && $rule['computedFor'] === $year) {
            return;
        }
        // January 1st, 0:00:00 GMT in YEAR (a count of days in C's int)
        $t = 0;
        if ($year > 1970) {
            $t = self::int32(
                ($year - 1970) * 365
                + (intdiv($year - 1, 4) - intdiv(1970, 4))
                - (intdiv($year - 1, 100) - intdiv(1970, 100))
                + (intdiv($year - 1, 400) - intdiv(1970, 400))
            ) * self::SECSPERDAY;
        }
        $leap = self::isLeap($year) ? 1 : 0;
        switch ($rule['type']) {
            case self::J1:
                // Jn: Julian day, 1 == January 1, 60 == March 1 even in leap years
                $t += ($rule['d'] - 1) * self::SECSPERDAY;
                if ($rule['d'] >= 60 && $leap === 1) {
                    $t += self::SECSPERDAY;
                }
                break;
            case self::J0:
                // n: day of year
                $t += $rule['d'] * self::SECSPERDAY;
                break;
            case self::M:
                // Mm.n.d: Nth "Dth day" of month M (myday = &__mon_yday[leap][m]; C reads past its
                // row for a month the parser rejected, and 0 here past the table)
                $monthStart = self::MON_YDAY_FLAT[$leap * 13 + $rule['m'] - 1] ?? 0;
                $t += $monthStart * self::SECSPERDAY;
                // Zeller's Congruence: day-of-week of the first day of the month
                $m1 = ($rule['m'] + 9) % 12 + 1;
                $yy0 = $rule['m'] <= 2 ? self::int32($year - 1) : $year;
                $yy1 = intdiv($yy0, 100);
                $yy2 = $yy0 % 100;
                $dow = (intdiv(26 * $m1 - 2, 10) + 1 + $yy2 + intdiv($yy2, 4) + intdiv($yy1, 4) - 2 * $yy1) % 7;
                if ($dow < 0) {
                    $dow += 7;
                }
                // the day-of-month (zero-origin) of the first DOW day of the month
                $d = $rule['d'] - $dow;
                if ($d < 0) {
                    $d += 7;
                }
                for ($i = 1; $i < $rule['n']; $i++) {
                    if ($d + 7 >= (self::MON_YDAY_FLAT[$leap * 13 + $rule['m']] ?? 0) - $monthStart) {
                        break;
                    }
                    $d += 7;
                }
                $t += $d * self::SECSPERDAY;
                break;
        }
        // the time of day and the local offset from GMT
        $rule['change'] = $t - $rule['offset'] + $rule['secs'];
        $rule['computedFor'] = $year;
    }

    /**
     * tzset.c: __tz_compute for local time: [isdst, zone name, offset]
     * of $timer, whose year in UTC is $utcYear
     *
     * @return array{int, string, int}
     */
    private static function tzCompute(int $timer, int $utcYear): array
    {
        self::computeChange(0, $utcYear);
        self::computeChange(1, $utcYear);
        $start = self::$rules[0]['change'];
        $end = self::$rules[1]['change'];
        // northern and southern hemisphere (DST ends in the next year)
        if ($start > $end) {
            $isdst = $timer < $end || $timer >= $start ? 1 : 0;
        } else {
            $isdst = $timer >= $start && $timer < $end ? 1 : 0;
        }
        return [$isdst, self::$rules[$isdst]['name'] ?? '', self::$rules[$isdst]['offset']];
    }

    /* }====================================================== */

    /*
    ** {======================================================
    ** Zone files (tzfile.c)
    ** =======================================================
    */

    /** tzfile.c: __tzfile_read: read zone file $file (relative: under TZDIR); sets $useTzfile */
    private static function tzfileRead(string $file): void
    {
        $wasUsingTzfile = self::$useTzfile;
        self::$useTzfile = false;
        if ($file === '') {
            return;  // the empty string: UTC with no leap seconds
        }
        if ($file[0] !== '/') {
            $directory = getenv('TZDIR');
            $file = (\is_string($directory) && $directory !== '' ? $directory : self::TZDIR) . '/' . $file;
        }
        $path = CFile::plainPath($file);
        // if we were already using a zone file, check whether the file changed
        if ($wasUsingTzfile && self::$tzfileStatus !== null) {
            clearstatcache(true, $path);
            $status = @stat($path);
            if (\is_array($status) && [$status['dev'], $status['ino'], $status['mtime']] === self::$tzfileStatus) {
                self::$useTzfile = true;
                return;
            }
        }
        $stream = @fopen($path, 'r');
        if ($stream === false) {
            return;
        }
        try {
            self::readZoneFile($stream);
        } finally {
            fclose($stream);
        }
    }

    /** the signed big-endian 32-bit number at $offset of $bytes (tzfile.c: decode) */
    private static function decode(string $bytes, int $offset = 0): int
    {
        $value = unpack('N', $bytes, $offset)[1];
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /** exactly $count bytes of $stream, or null (fread came short) */
    private static function readBytes($stream, int $count): ?string
    {
        if ($count === 0) {
            return '';
        }
        $data = '';
        while (\strlen($data) < $count) {
            $piece = @fread($stream, $count - \strlen($data));
            if ($piece === false || $piece === '') {
                return null;
            }
            $data .= $piece;
        }
        return $data;
    }

    /** tzfile.c: __tzfile_read from the opened file (anything wrong: 'lose', no zone file) */
    private static function readZoneFile($stream): void
    {
        $status = @fstat($stream);
        if (!\is_array($status)) {
            return;
        }
        self::$tzfileStatus = [$status['dev'], $status['ino'], $status['mtime']];
        $transitionWidth = 4;
        while (true) {
            // struct tzhead: magic, version, 15 reserved bytes, six counts
            $header = self::readBytes($stream, 44);
            if ($header === null || !str_starts_with($header, 'TZif')) {
                return;
            }
            $isgmtCount = self::decode($header, 20);
            $isstdCount = self::decode($header, 24);
            $leapCount = self::decode($header, 28);
            $transitionCount = self::decode($header, 32);
            $typeCount = self::decode($header, 36);
            $characterCount = self::decode($header, 40);
            foreach ([$isgmtCount, $isstdCount, $leapCount, $transitionCount, $typeCount, $characterCount] as $count) {
                if ($count < 0) {
                    return;  // (size_t: too big to read)
                }
            }
            if ($isstdCount > $typeCount || $isgmtCount > $typeCount) {
                return;
            }
            if ($transitionWidth === 8 || $header[4] === "\0") {
                break;
            }
            // version 2+: skip the 32-bit data to the second header
            $transitionWidth = 8;
            $skip = $transitionCount * 5 + $typeCount * 6 + $characterCount + $leapCount * 8 + $isstdCount
                + $isgmtCount;
            if (@fseek($stream, $skip, SEEK_CUR) !== 0) {
                return;
            }
        }
        // the size of the POSIX TZ string at the end
        $specLength = 0;
        if ($transitionWidth === 8) {
            $remaining = $status['size'] - (int) ftell($stream);
            $data = $transitionCount * 9 + $typeCount * 6 + $characterCount;
            if ($remaining < $data) {
                return;
            }
            $specLength = $remaining - $data;
            if ($specLength < $leapCount * 12) {
                return;
            }
            $specLength -= $leapCount * 12;
            if ($specLength < $isstdCount) {
                return;
            }
            $specLength -= $isstdCount;
            if ($specLength === 0 || $specLength - 1 < $isgmtCount) {
                return;
            }
            $specLength -= $isgmtCount + 1;
            if ($specLength === 0) {
                return;
            }
        }
        $transitionBytes = self::readBytes($stream, $transitionCount * $transitionWidth);
        $typeIndexBytes = self::readBytes($stream, $transitionCount);
        if ($transitionBytes === null || $typeIndexBytes === null) {
            return;
        }
        $transitions = [];
        $typeIndexes = [];
        for ($i = 0; $i < $transitionCount; $i++) {
            $typeIndexes[] = \ord($typeIndexBytes[$i]);
            if ($typeIndexes[$i] >= $typeCount) {
                return;  // bogus index
            }
            $transitions[] = $transitionWidth === 4
                ? self::decode($transitionBytes, $i * 4)
                : unpack('J', $transitionBytes, $i * 8)[1];
        }
        $types = [];
        for ($i = 0; $i < $typeCount; $i++) {
            $typeBytes = self::readBytes($stream, 6);
            if ($typeBytes === null || \ord($typeBytes[4]) > 1 || \ord($typeBytes[5]) > $characterCount) {
                return;
            }
            $types[] = [
                'offset' => self::decode($typeBytes),
                'isdst' => \ord($typeBytes[4]),
                'idx' => \ord($typeBytes[5]),
                'isstd' => false,
                'isgmt' => false,
            ];
        }
        $zoneNames = self::readBytes($stream, $characterCount);
        if ($zoneNames === null) {
            return;
        }
        $leaps = [];
        for ($i = 0; $i < $leapCount; $i++) {
            $leapBytes = self::readBytes($stream, $transitionWidth + 4);
            if ($leapBytes === null) {
                return;
            }
            $transition = $transitionWidth === 4 ? self::decode($leapBytes) : unpack('J', $leapBytes)[1];
            $leaps[] = [$transition, self::decode($leapBytes, $transitionWidth)];
        }
        $isstdBytes = self::readBytes($stream, $isstdCount);
        $isgmtBytes = self::readBytes($stream, $isgmtCount);
        if ($isstdBytes === null || $isgmtBytes === null) {
            return;
        }
        for ($i = 0; $i < $isstdCount; $i++) {
            $types[$i]['isstd'] = $isstdBytes[$i] !== "\0";
        }
        for ($i = 0; $i < $isgmtCount; $i++) {
            $types[$i]['isgmt'] = $isgmtBytes[$i] !== "\0";
        }
        // the POSIX TZ string, after a newline (not used if empty)
        $spec = null;
        if ($transitionWidth === 8) {
            $specBytes = self::readBytes($stream, $specLength);
            if ($specBytes !== null && $specBytes[0] === "\n") {
                $spec = self::cString(substr($specBytes, 1, $specLength - 1));
            }
        }
        self::$transitions = $transitions;
        self::$typeIndexes = $typeIndexes;
        self::$types = $types;
        self::$zoneNames = $zoneNames;
        self::$leaps = $leaps;
        self::$tzspec = $spec === '' ? null : $spec;
        // the standard offset: of the last transition to standard time
        if ($transitionCount === 0) {
            self::$ruleStdoff = self::$ruleDstoff = $types[0]['offset'] ?? 0;
        } else {
            self::$ruleStdoff = 0;
            for ($i = $transitionCount - 1; $i >= 0; $i--) {
                if (!$types[$typeIndexes[$i]]['isdst']) {
                    self::$ruleStdoff = $types[$typeIndexes[$i]]['offset'];
                    break;
                }
            }
        }
        self::$useTzfile = true;
    }

    /** the zone name at $index of the file's abbreviations */
    private static function zoneName(int $index): string
    {
        return self::cString(substr(self::$zoneNames, $index));
    }

    /**
     * tzfile.c: __tzfile_default: a TZ string with names and offsets but
     * no rules takes the transitions of TZDEFRULES (posixrules), moved to
     * the given offsets, with only two types: standard and daylight time.
     */
    private static function tzfileDefault(string $standardName, string $daylightName, int $stdoff, int $dstoff): void
    {
        self::tzfileRead(self::TZDEFRULES);
        if (!self::$useTzfile) {
            return;
        }
        if (\count(self::$types) < 2) {
            self::$useTzfile = false;
            return;
        }
        // correct the transition times for the user's standard and daylight offsets
        $isdst = false;
        foreach (self::$transitions as $i => $transition) {
            $type = self::$types[self::$typeIndexes[$i]];
            self::$typeIndexes[$i] = $type['isdst'];  // only types 0 (standard) and 1 (daylight)
            if ($type['isgmt']) {
                // the transition time is in GMT: no correction
            } elseif ($isdst && !$type['isstd']) {
                self::$transitions[$i] = $transition + $dstoff - self::$ruleDstoff;
            } else {
                self::$transitions[$i] = $transition + $stdoff - self::$ruleStdoff;
            }
            $isdst = (bool) $type['isdst'];
        }
        self::$ruleStdoff = $stdoff;
        self::$ruleDstoff = $dstoff;
        self::$zoneNames = "$standardName\0$daylightName\0";
        self::$types = [
            ['offset' => $stdoff, 'isdst' => 0, 'idx' => 0, 'isstd' => false, 'isgmt' => false],
            ['offset' => $dstoff, 'isdst' => 1, 'idx' => \strlen($standardName) + 1, 'isstd' => false,
                'isgmt' => false],
        ];
        self::$tzfileStatus = null;  // read TZDEFRULES again the next time it is used
    }

    /**
     * tzfile.c: __tzfile_compute: [offset, isdst, zone name, leap second
     * correction, extra leap seconds] at $timer
     *
     * @return array{int, int, string, int, int}
     */
    private static function tzfileCompute(int $timer, bool $useLocaltime, ?int $utcYear): array
    {
        $offset = 0;
        $isdst = 0;
        $zone = '';
        $transitionCount = \count(self::$transitions);
        if ($useLocaltime) {
            if ($transitionCount === 0 || $timer < self::$transitions[0]) {
                // before any transition: the first non-DST type (or the first if all are DST)
                $type = 0;
                $typeCount = \count(self::$types);
                while ($type < $typeCount && self::$types[$type]['isdst']) {
                    $type++;
                }
                if ($type === $typeCount) {
                    $type = 0;
                }
            } elseif ($timer >= self::$transitions[$transitionCount - 1]) {
                $type = self::$typeIndexes[$transitionCount - 1];  // use the last...
                if (self::$tzspec !== null) {
                    // ...unless the file ends with a POSIX TZ string: its rules
                    self::tzsetParseTz(self::$tzspec);
                    if ($utcYear !== null) {
                        [$isdst, $zone, $offset] = self::tzCompute($timer, $utcYear);
                        return [$offset, $isdst, $zone, ...self::leapCorrection($timer)];
                    }
                }
            } else {
                // the type of the last transition at or before $timer
                $low = 0;
                $high = $transitionCount - 1;
                while ($low + 1 < $high) {
                    $middle = intdiv($low + $high, 2);
                    if ($timer < self::$transitions[$middle]) {
                        $high = $middle;
                    } else {
                        $low = $middle;
                    }
                }
                $type = self::$typeIndexes[$low];
            }
            $offset = self::$types[$type]['offset'] ?? 0;
            $isdst = self::$types[$type]['isdst'] ?? 0;
            $zone = self::zoneName(self::$types[$type]['idx'] ?? 0);
        }
        return [$offset, $isdst, $zone, ...self::leapCorrection($timer)];
    }

    /**
     * tzfile.c: __tzfile_compute's leap: [correction, extra seconds] of the
     * last leap second transition at or before $timer
     *
     * @return array{int, int}
     */
    private static function leapCorrection(int $timer): array
    {
        $i = \count(self::$leaps);
        do {
            if ($i-- === 0) {
                return [0, 0];
            }
        } while ($timer < self::$leaps[$i][0]);
        $correction = self::$leaps[$i][1];
        $hit = 0;
        if ($timer === self::$leaps[$i][0] && $correction > ($i === 0 ? 0 : self::$leaps[$i - 1][1])) {
            $hit = 1;
            while (
                $i > 0 && self::$leaps[$i][0] === self::$leaps[$i - 1][0] + 1
                && self::$leaps[$i][1] === self::$leaps[$i - 1][1] + 1
            ) {
                $hit++;
                $i--;
            }
        }
        return [$correction, $hit];
    }

    /* }====================================================== */
}
