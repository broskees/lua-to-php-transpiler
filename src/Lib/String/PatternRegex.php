<?php

declare(strict_types=1);

namespace LuaPhp\Lib\String;

/**
 * Not in C: a Lua pattern translated to a PCRE regex that matches exactly
 * like lstrlib.c's match(), so MatchState can ask preg_match instead of
 * running its port byte by byte. Only the core question changes ("does the
 * pattern match at, or after, this position, and with what captures?");
 * str_find_aux, gmatch_aux and str_gsub stay as in C.
 *
 * Why the translation is exact:
 * - A pattern (as match() sees it: the drivers strip a leading '^') is a
 *   sequence of items, and its only choices are how many bytes a quantified
 *   single character class takes. match() tries them in PCRE's
 *   backtracking order: '*', '+' and '?' most first (greedy), '-' fewest
 *   first ('*?'). Captures are markers that are never quantified, so each
 *   is a PCRE group entered exactly once, numbered in the same order, and
 *   '()' is an empty group whose offset is the position.
 * - Every single character class (a byte, '.', '%a', a set) becomes the
 *   explicit list of bytes MatchState's own singlematch accepts
 *   (MatchState::classBytes), escaped as \xHH: C-locale classes, set ranges
 *   and escapes cannot drift, and no /u, /i or /x applies. Every byte PCRE
 *   could read as syntax is escaped, so '?' after '*' stays a literal.
 * - '$' only ends a pattern as its last byte: \z (PCRE's '$' also matches
 *   before a final "\n"); anywhere else '^' and '$' are literal bytes.
 * - '%1'-'%9' are \g{n}; a back reference to a position capture never
 *   matches in C (its length is CAP_POSITION as a size_t): (*FAIL).
 * - '%f[set]' becomes lookarounds, with C's rule that the bytes before and
 *   after the subject are '\0'.
 * - '%bxy' is deterministic in C (matchbalance returns the first point where
 *   the count of x minus y reaches 0, and match() never asks again), so it
 *   becomes an atomic call of a recursive group that stops at exactly that
 *   point: x, then possessively runs of other bytes or nested balanced
 *   groups, then y ('x[^x]*+x' when x is y). The groups are defined after
 *   everything else, so Lua's captures keep PCRE's numbers 1..n.
 * - A search from offset p (preg_match's offset: lookbehinds still see the
 *   bytes before it) finds the leftmost start q >= p that matches: the
 *   answer of match() tried at p, p + 1, ..., q. The 'A' variant only
 *   matches at p, like one match() call.
 *
 * Whatever could make match() raise an error stays with the port, which
 * raises it exactly when C does (errors are lazy: "x%" never reaches its
 * '%' on "abc"): malformed patterns, bad captures and back references,
 * more than LUA_MAXCAPTURES captures, an unfinished capture, and patterns
 * with enough recursion to reach "pattern too complex". MatchState also
 * hands a call to the port when PCRE gives up at run time (backtrack or JIT
 * stack limit, e.g. '%b()' over thousands of nested parentheses).
 *
 * tests/fuzz/patterns.php checks all this against lua5.4 and against the
 * port (MatchState::$forcePort); tests/diff/string_pattern_corpus.lua
 * replays a fixed-seed corpus of its cases.
 */
final class PatternRegex
{
    /** translations (or refusals) of at most this many patterns are kept */
    private const CACHE_SIZE = 512;

    /** longer patterns are left to the port, uncached */
    private const MAX_PATTERN_LENGTH = 512;

    /**
     * longer translations are left to the port (PHP caches compiled regexes
     * too; PCRE2 refuses compiled patterns over 64 KB)
     */
    private const MAX_REGEX_LENGTH = 4096;

    /**
     * match() recurses only for captures and quantified items, always to a
     * later pattern position, so its depth is at most 1 + their count;
     * lstrlib.c's MAXCCALLS (200) bounds it. Patterns with more such items
     * than this may raise "pattern too complex": left to the port.
     */
    private const MAX_RECURSIVE_ITEMS = 190;

    /**
     * A pattern's first use on a subject shorter than this runs on the port
     * (translating it costs about as much as the port matching a few dozen
     * bytes); its next use, or a longer subject, translates it.
     */
    private const MIN_FIRST_SUBJECT_LENGTH = 64;

    /** @var array<string, self|false|true> pattern => translation, false for the port, true if used once */
    private static array $cache = [];

    /** @var array<string, string> byte after '%' => regex of that class */
    private static array $escapeRegexes = [];

    /**
     * @param string $search regex for the leftmost match at or after an offset
     * @param string $anchored regex that matches only at the offset
     * @param list<bool> $isPositionCapture capture index => is it '()'
     */
    private function __construct(
        public readonly string $search,
        public readonly string $anchored,
        public readonly array $isPositionCapture,
    ) {
    }

    /**
     * The translation of $pattern (what match() sees: no leading anchor) for
     * a subject of $subjectLength bytes, or null when the port runs it. $ms
     * is a MatchState of $pattern.
     */
    public static function forPattern(string $pattern, int $subjectLength, MatchState $ms): ?self
    {
        $cached = self::$cache[$pattern] ?? null;
        if ($cached instanceof self) {
            return $cached;
        }
        if ($cached === false || \strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return null;
        }
        if (\count(self::$cache) >= self::CACHE_SIZE) {
            self::$cache = [];  // (dropping the oldest one by one makes PHP skip its holes)
        }
        if ($cached === null && $subjectLength < self::MIN_FIRST_SUBJECT_LENGTH) {
            self::$cache[$pattern] = true;  // used once
            return null;
        }
        $translation = self::translate($pattern, $ms);
        self::$cache[$pattern] = $translation ?? false;
        return $translation;
    }

    /** the items of lstrlib.c's match(), in its order of cases; null where it could raise */
    private static function translate(string $pattern, MatchState $ms): ?self
    {
        $patternEnd = \strlen($pattern);
        $regex = '';
        $isPositionCapture = [];
        $openCaptures = [];  // indexes of the unfinished captures, innermost last
        $closedCaptures = [];  // capture index => true
        $recursiveItems = 0;
        $balanceGroups = '';  // the recursive groups '%b' calls
        $p = 0;
        while ($p < $patternEnd) {
            $character = $pattern[$p];
            if ($character === '(') {  // start capture
                if (\count($isPositionCapture) >= MatchState::LUA_MAXCAPTURES) {
                    return null;  // "too many captures"
                }
                $recursiveItems++;
                if (($pattern[$p + 1] ?? '') === ')') {  // position capture
                    $closedCaptures[\count($isPositionCapture)] = true;
                    $isPositionCapture[] = true;
                    $regex .= '()';
                    $p += 2;
                    continue;
                }
                $openCaptures[] = \count($isPositionCapture);
                $isPositionCapture[] = false;
                $regex .= '(';
                $p++;
                continue;
            }
            if ($character === ')') {  // end capture
                if ($openCaptures === []) {
                    return null;  // "invalid pattern capture"
                }
                $recursiveItems++;
                $closedCaptures[array_pop($openCaptures)] = true;
                $regex .= ')';
                $p++;
                continue;
            }
            if ($character === '$' && $p + 1 === $patternEnd) {  // the '$' is the last char in pattern
                $regex .= '\z';
                $p++;
                continue;
            }
            if ($character === '%') {
                $next = $pattern[$p + 1] ?? '';
                if ($next === 'b') {  // balanced string
                    if ($p + 3 >= $patternEnd) {
                        return null;  // "malformed pattern (missing arguments to '%b')"
                    }
                    $open = \ord($pattern[$p + 2]);
                    $close = \ord($pattern[$p + 3]);
                    $others = str_repeat("\1", 256);
                    $others[$open] = $others[$close] = "\0";
                    if ($open === $close) {
                        $regex .= self::byteRegex($open) . self::byteSetRegex($others) . '*+' . self::byteRegex($close);
                    } else {
                        $name = 'b' . \strlen($balanceGroups);  // (unique: the definitions only grow)
                        $balanceGroups .= '(?<' . $name . '>' . self::byteRegex($open) . '(?:' . self::byteSetRegex($others)
                            . '++|(?&' . $name . '))*+' . self::byteRegex($close) . ')';
                        $regex .= '(?>(?&' . $name . '))';
                    }
                    $p += 4;
                    continue;
                }
                if ($next === 'f') {  // frontier
                    $p += 2;
                    if (($pattern[$p] ?? '') !== '[') {
                        return null;  // "missing '[' after '%f' in pattern"
                    }
                    $ep = self::classEnd($pattern, $patternEnd, $p);
                    if ($ep === null) {
                        return null;
                    }
                    $regex .= self::frontierRegex($ms->classBytes($p, $ep));
                    $p = $ep;
                    continue;
                }
                if ($next !== '' && $next >= '0' && $next <= '9') {  // capture results (%0-%9)
                    $index = \ord($next) - \ord('1');
                    if (!isset($closedCaptures[$index])) {
                        return null;  // "invalid capture index" (%0, not started or unfinished)
                    }
                    $regex .= $isPositionCapture[$index] ? '(*FAIL)' : '\g{' . ($index + 1) . '}';
                    $p += 2;
                    continue;
                }
                // else a single character class
            }
            // default: single character class plus optional suffix
            $ep = self::classEnd($pattern, $patternEnd, $p);
            if ($ep === null) {
                return null;
            }
            $regex .= self::classRegex($pattern, $p, $ep, $ms);
            $suffix = $pattern[$ep] ?? '';
            if ($suffix === '*' || $suffix === '+' || $suffix === '?') {
                $regex .= $suffix;
                $recursiveItems++;
                $ep++;
            } elseif ($suffix === '-') {
                $regex .= '*?';
                $recursiveItems++;
                $ep++;
            }
            $p = $ep;
        }
        if ($openCaptures !== []) {
            return null;  // "unfinished capture" once captures are pushed
        }
        if ($recursiveItems > self::MAX_RECURSIVE_ITEMS || \strlen($regex . $balanceGroups) > self::MAX_REGEX_LENGTH) {
            return null;
        }
        if ($balanceGroups !== '') {
            $regex .= '(?(DEFINE)' . $balanceGroups . ')';
        }
        // (should PCRE refuse to compile it, preg_match fails and MatchState runs the port)
        $search = '/' . $regex . '/';
        return new self($search, $search . 'A', $isPositionCapture);
    }

    /** lstrlib.c: classend, with null where it raises "malformed pattern" */
    private static function classEnd(string $pattern, int $patternEnd, int $p): ?int
    {
        $character = $pattern[$p++];
        if ($character === '%') {
            return $p === $patternEnd ? null : $p + 1;  // "(ends with '%')"
        }
        if ($character === '[') {
            if (($pattern[$p] ?? '') === '^') {
                $p++;
            }
            do {  // look for a ']'
                if ($p === $patternEnd) {
                    return null;  // "(missing ']')"
                }
                if ($pattern[$p++] === '%' && $p < $patternEnd) {
                    $p++;  // skip escapes (e.g. '%]')
                }
            } while (($pattern[$p] ?? '') !== ']');
            return $p + 1;
        }
        return $p;
    }

    /** the single character class at $p..$ep (classend) as one PCRE atom */
    private static function classRegex(string $pattern, int $p, int $ep, MatchState $ms): string
    {
        $character = $pattern[$p];
        if ($character === '.') {
            return '[\x00-\xff]';
        }
        if ($character === '%') {  // a class like '%a' depends only on its letter
            return self::$escapeRegexes[$pattern[$p + 1]] ??= self::byteSetRegex($ms->classBytes($p, $ep));
        }
        if ($character === '[') {
            return self::byteSetRegex($ms->classBytes($p, $ep));
        }
        return self::byteRegex(\ord($character));
    }

    /**
     * lstrlib.c: match's case 'f': the byte before $s (or '\0' at the start)
     * is not in the set and the byte at $s (or '\0' at the end) is.
     *
     * @param string $inSet MatchState::classBytes flags of the set
     */
    private static function frontierRegex(string $inSet): string
    {
        $set = self::byteSetRegex($inSet);
        if ($inSet[0] === "\1") {  // '\0' is in the set: needs a real previous byte outside it, and the end counts
            return '(?<=' . self::byteSetRegex($inSet ^ str_repeat("\1", 256)) . ')(?=' . $set . '|\z)';
        }
        return '(?<!' . $set . ')(?=' . $set . ')';
    }

    /**
     * One byte of a set of bytes: a literal, or a class of explicit bytes
     * (whichever of the set or its complement is shorter).
     *
     * @param string $accepted MatchState::classBytes flags
     */
    private static function byteSetRegex(string $accepted): string
    {
        $memberCount = substr_count($accepted, "\1");
        if ($memberCount === 0) {
            return '[^\x00-\xff]';  // matches nothing
        }
        if ($memberCount === 1) {
            return self::byteRegex(strpos($accepted, "\1"));
        }
        if ($memberCount === 256) {
            return '[\x00-\xff]';
        }
        $included = self::byteRanges($accepted, "\1");
        $excluded = self::byteRanges($accepted, "\0");
        return \strlen($included) <= \strlen($excluded) ? '[' . $included . ']' : '[^' . $excluded . ']';
    }

    /** the runs of bytes whose flag in $accepted is $flag, as class ranges */
    private static function byteRanges(string $accepted, string $flag): string
    {
        $ranges = '';
        for ($first = strcspn($accepted, $flag); $first < 256; $first = $last + 1 + strcspn($accepted, $flag, $last + 1)) {
            $last = $first + strspn($accepted, $flag, $first) - 1;
            $ranges .= \sprintf($last === $first ? '\x%02x' : '\x%02x-\x%02x', $first, $last);
        }
        return $ranges;
    }

    /** a literal byte: letters and digits as themselves, anything else escaped */
    private static function byteRegex(int $byte): string
    {
        $isAlphanumeric = ($byte >= 0x30 && $byte <= 0x39) || ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A);
        return $isAlphanumeric ? \chr($byte) : \sprintf('\x%02x', $byte);
    }
}
