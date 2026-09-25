<?php

declare(strict_types=1);

namespace LuaPhp\Lib\String;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;

/**
 * Port of lstrlib.c's pattern matcher (MatchState and the functions that
 * work on it: match, max_expand, min_expand, start/end_capture,
 * matchbalance, matchbracketclass, singlematch, classend, match_capture,
 * get_onecapture/push_captures).
 *
 * C pointers into the subject and the pattern are byte offsets here; a
 * failed match (C's NULL) is -1. Lua strings end with a '\0' that C reads
 * past the end of the pattern, so the pattern is kept with a '\0'
 * appended ($patternEnd is its real length); the subject is not copied,
 * reads at its end give '\0' explicitly.
 *
 * Not in C: when PatternRegex can translate the pattern, the drivers'
 * question "where is the next match from here, and what are its captures"
 * (nextCandidate, matchAt) is answered by preg_match instead of match().
 */
final class MatchState
{
    // lstrlib.c: LUA_MAXCAPTURES
    public const LUA_MAXCAPTURES = 32;

    // lstrlib.c: CAP_UNFINISHED / CAP_POSITION
    public const CAP_UNFINISHED = -1;
    public const CAP_POSITION = -2;

    // lstrlib.c: MAXCCALLS (maximum recursion depth for 'match')
    private const MAXCCALLS = 200;

    // lstrlib.c: L_ESC / SPECIALS
    private const L_ESC = '%';
    public const SPECIALS = '^$*+?.([%-';

    // character class bits (C locale ctype.h)
    private const CLASS_ALPHA = 1;
    private const CLASS_CNTRL = 2;
    private const CLASS_DIGIT = 4;
    private const CLASS_GRAPH = 8;
    private const CLASS_LOWER = 16;
    private const CLASS_PUNCT = 32;
    private const CLASS_SPACE = 64;
    private const CLASS_UPPER = 128;
    private const CLASS_ALNUM = 256;
    private const CLASS_XDIGIT = 512;
    private const CLASS_ZERO = 1024;

    // class letter (lowercase) => class bit, for match_class
    private const CLASS_OF_LETTER = [
        'a' => self::CLASS_ALPHA,
        'c' => self::CLASS_CNTRL,
        'd' => self::CLASS_DIGIT,
        'g' => self::CLASS_GRAPH,
        'l' => self::CLASS_LOWER,
        'p' => self::CLASS_PUNCT,
        's' => self::CLASS_SPACE,
        'u' => self::CLASS_UPPER,
        'w' => self::CLASS_ALNUM,
        'x' => self::CLASS_XDIGIT,
        'z' => self::CLASS_ZERO,  // deprecated option
    ];

    /** @var list<int>|null byte => its class bits in the C locale */
    private static ?array $classBitsOfByte = null;

    public int $matchdepth = self::MAXCCALLS;  // control for recursive depth
    public int $level = 0;  // total number of captures (finished or unfinished)
    /** @var array<int, int> */
    public array $captureInit = [];
    /** @var array<int, int> */
    public array $captureLen = [];

    public readonly int $srcEnd;
    public readonly int $patternEnd;
    /** the pattern followed by '\0' */
    private readonly string $pattern;
    /** @var list<int> */
    private readonly array $classBits;

    /**
     * Not in C: the byte every match must start with, when the pattern
     * starts with a plain character that no '*', '?' or '-' makes
     * optional; null otherwise. match() fails at once, without errors,
     * wherever the subject has another byte, so callers may skip to the
     * next occurrence (see nextCandidate).
     */
    private readonly ?string $firstLiteral;

    /**
     * Not in C: test-only switch that makes every new MatchState run the
     * port (the pattern fuzzer compares it with the PCRE translation).
     */
    public static bool $forcePort = false;

    /** Not in C: the pattern's PCRE translation, or null to run the port */
    private ?PatternRegex $regex;

    /** Not in C: the start (or -1) and end of the last match the regex found */
    private int $regexStart = -1;
    private int $regexEnd = -1;

    // Not in C: regexSearch's result when PCRE gives up
    private const PCRE_FAILED = -2;

    // lstrlib.c: prepstate
    public function __construct(
        public Coroutine $L,
        public readonly string $src,
        string $pattern,
    ) {
        $this->srcEnd = \strlen($src);
        $this->patternEnd = \strlen($pattern);
        $this->pattern = $pattern . "\0";
        $this->classBits = self::$classBitsOfByte ??= self::buildClassBits();
        $first = $pattern[0] ?? null;
        $second = $this->pattern[1] ?? "\0";
        $isPlain = $first !== null && !str_contains(self::SPECIALS . ')', $first);
        $isOptional = $second === '*' || $second === '?' || $second === '-';
        $this->firstLiteral = ($isPlain && !$isOptional) ? $first : null;
        $this->regex = self::$forcePort ? null : PatternRegex::forPattern($pattern, $this->srcEnd, $this);
    }

    /**
     * The first position >= $s (up to the end of the subject) where a
     * match of the whole pattern may start, or -1 if there is none. Only
     * for unanchored searches that try every position in turn: match()
     * fails at every position skipped. With a regex, the answer is where
     * the next match starts, and matchAt() then returns that very match.
     */
    public function nextCandidate(int $s): int
    {
        if ($this->regex !== null) {
            $start = $this->regexSearch($this->regex->search, $s);
            if ($start !== self::PCRE_FAILED) {
                return $start;
            }
        }
        if ($this->firstLiteral === null) {
            return $s;
        }
        $candidate = strpos($this->src, $this->firstLiteral, $s);
        return $candidate === false ? -1 : $candidate;
    }

    /**
     * lstrlib.c: reprepstate + match(ms, s, p) for the whole pattern: the
     * end of its match at $s, or -1, with the captures set for captures().
     */
    public function matchAt(int $s): int
    {
        if ($this->regex !== null) {
            if ($s === $this->regexStart) {  // found by nextCandidate
                return $this->regexEnd;
            }
            $start = $this->regexSearch($this->regex->anchored, $s);
            if ($start !== self::PCRE_FAILED) {
                return $start === -1 ? -1 : $this->regexEnd;
            }
        }
        $this->reprepstate();
        return $this->match($s, 0);
    }

    /**
     * Not in C: preg_match $regex from $s. Returns the start of the match,
     * with its end and the captures set as match() would set them, -1 if
     * there is none, or PCRE_FAILED if PCRE gave up (backtrack or JIT stack
     * limit): the port then runs this and every later match.
     */
    private function regexSearch(string $regex, int $s): int
    {
        $found = @preg_match($regex, $this->src, $groups, PREG_OFFSET_CAPTURE, $s);
        if ($found === 0) {
            $this->regexStart = -1;
            return -1;
        }
        if ($found !== 1) {
            $this->regex = null;
            return self::PCRE_FAILED;
        }
        [$matched, $start] = $groups[0];
        $this->regexStart = $start;
        $this->regexEnd = $start + \strlen($matched);
        $isPositionCapture = $this->regex->isPositionCapture;
        $this->level = \count($isPositionCapture);
        foreach ($isPositionCapture as $i => $isPosition) {
            [$captured, $captureStart] = $groups[$i + 1];
            $this->captureInit[$i] = $captureStart;
            $this->captureLen[$i] = $isPosition ? self::CAP_POSITION : \strlen($captured);
        }
        return $start;
    }

    /** the C-locale ctype classes of every byte */
    private static function buildClassBits(): array
    {
        $bits = [];
        for ($c = 0; $c < 256; $c++) {
            $isUpper = $c >= 0x41 && $c <= 0x5A;
            $isLower = $c >= 0x61 && $c <= 0x7A;
            $isDigit = $c >= 0x30 && $c <= 0x39;
            $isGraph = $c >= 0x21 && $c <= 0x7E;
            $classes = 0;
            if ($isUpper || $isLower) {
                $classes |= self::CLASS_ALPHA;
            }
            if ($c < 0x20 || $c === 0x7F) {
                $classes |= self::CLASS_CNTRL;
            }
            if ($isDigit) {
                $classes |= self::CLASS_DIGIT;
            }
            if ($isGraph) {
                $classes |= self::CLASS_GRAPH;
            }
            if ($isLower) {
                $classes |= self::CLASS_LOWER;
            }
            if ($isGraph && !$isUpper && !$isLower && !$isDigit) {
                $classes |= self::CLASS_PUNCT;
            }
            if ($c === 0x20 || ($c >= 0x09 && $c <= 0x0D)) {
                $classes |= self::CLASS_SPACE;
            }
            if ($isUpper) {
                $classes |= self::CLASS_UPPER;
            }
            if ($isUpper || $isLower || $isDigit) {
                $classes |= self::CLASS_ALNUM;
            }
            if ($isDigit || ($c >= 0x41 && $c <= 0x46) || ($c >= 0x61 && $c <= 0x66)) {
                $classes |= self::CLASS_XDIGIT;
            }
            if ($c === 0) {
                $classes |= self::CLASS_ZERO;
            }
            $bits[] = $classes;
        }
        return $bits;
    }

    // lstrlib.c: reprepstate
    private function reprepstate(): void
    {
        $this->matchdepth = self::MAXCCALLS;
        $this->level = 0;
    }

    // lstrlib.c: check_capture
    private function checkCapture(int $l): int
    {
        $l -= \ord('1');
        if ($l < 0 || $l >= $this->level || $this->captureLen[$l] === self::CAP_UNFINISHED) {
            Auxiliary::error($this->L, 'invalid capture index %' . ($l + 1));
        }
        return $l;
    }

    // lstrlib.c: capture_to_close
    private function captureToClose(): int
    {
        for ($level = $this->level - 1; $level >= 0; $level--) {
            if ($this->captureLen[$level] === self::CAP_UNFINISHED) {
                return $level;
            }
        }
        Auxiliary::error($this->L, 'invalid pattern capture');
    }

    // lstrlib.c: classend
    private function classEnd(int $p): int
    {
        $pattern = $this->pattern;
        $character = $pattern[$p++];
        if ($character === self::L_ESC) {
            if ($p === $this->patternEnd) {
                Auxiliary::error($this->L, "malformed pattern (ends with '%')");
            }
            return $p + 1;
        }
        if ($character === '[') {
            if ($pattern[$p] === '^') {
                $p++;
            }
            do {  // look for a ']'
                if ($p === $this->patternEnd) {
                    Auxiliary::error($this->L, "malformed pattern (missing ']')");
                }
                if ($pattern[$p++] === self::L_ESC && $p < $this->patternEnd) {
                    $p++;  // skip escapes (e.g. '%]')
                }
            } while ($pattern[$p] !== ']');
            return $p + 1;
        }
        return $p;
    }

    // lstrlib.c: match_class
    private function matchClass(int $c, int $cl): bool
    {
        $classLetter = \chr($cl | 0x20);  // tolower (only letters matter)
        $classBit = self::CLASS_OF_LETTER[$classLetter] ?? null;
        if ($classBit === null || !(($cl >= 0x41 && $cl <= 0x5A) || ($cl >= 0x61 && $cl <= 0x7A))) {
            return $cl === $c;
        }
        $result = ($this->classBits[$c] & $classBit) !== 0;
        return $cl >= 0x61 ? $result : !$result;  // islower(cl) ? res : !res
    }

    /** lstrlib.c: matchbracketclass; $p is the '[', $ec the closing ']' */
    private function matchBracketClass(int $c, int $p, int $ec): bool
    {
        $pattern = $this->pattern;
        $sig = true;
        if ($pattern[$p + 1] === '^') {
            $sig = false;
            $p++;  // skip the '^'
        }
        while (++$p < $ec) {
            if ($pattern[$p] === self::L_ESC) {
                $p++;
                if ($this->matchClass($c, \ord($pattern[$p]))) {
                    return $sig;
                }
            } elseif ($pattern[$p + 1] === '-' && $p + 2 < $ec) {
                $p += 2;
                if (\ord($pattern[$p - 2]) <= $c && $c <= \ord($pattern[$p])) {
                    return $sig;
                }
            } elseif (\ord($pattern[$p]) === $c) {
                return $sig;
            }
        }
        return !$sig;
    }

    // lstrlib.c: singlematch
    private function singleMatch(int $s, int $p, int $ep): bool
    {
        if ($s >= $this->srcEnd) {
            return false;
        }
        $patternCharacter = $this->pattern[$p];
        switch ($patternCharacter) {
            case '.':
                return true;  // matches any char
            case self::L_ESC:
                return $this->matchClass(\ord($this->src[$s]), \ord($this->pattern[$p + 1]));
            case '[':
                return $this->matchBracketClass(\ord($this->src[$s]), $p, $ep - 1);
            default:
                return $patternCharacter === $this->src[$s];
        }
    }

    /**
     * Not in C: which bytes singlematch accepts for the single character
     * class at pattern position $p ($ep is its classend), for PatternRegex:
     * 256 flags, "\1" at the offset of every accepted byte, else "\0".
     */
    public function classBytes(int $p, int $ep): string
    {
        $patternCharacter = $this->pattern[$p];
        if ($patternCharacter === '.') {
            return str_repeat("\1", 256);
        }
        if ($patternCharacter === self::L_ESC) {
            return $this->matchClassBytes(\ord($this->pattern[$p + 1]));
        }
        if ($patternCharacter === '[') {
            return $this->matchBracketClassBytes($p, $ep - 1);
        }
        $accepted = str_repeat("\0", 256);
        $accepted[\ord($patternCharacter)] = "\1";
        return $accepted;
    }

    /** @var array<int, string> class byte => its matchClassBytes */
    private static array $classBytesOfClass = [];

    /** Not in C: match_class(c, cl) for every byte c, as classBytes' flags */
    private function matchClassBytes(int $cl): string
    {
        if (!isset(self::$classBytesOfClass[$cl])) {
            $accepted = '';
            for ($c = 0; $c < 256; $c++) {
                $accepted .= $this->matchClass($c, $cl) ? "\1" : "\0";
            }
            self::$classBytesOfClass[$cl] = $accepted;
        }
        return self::$classBytesOfClass[$cl];
    }

    /**
     * Not in C: matchbracketclass(c, p, ec) for every byte c, as classBytes'
     * flags. Its loop, collecting what each item of the set accepts: it
     * returns 'sig' for a byte some item accepts, else '!sig'.
     */
    private function matchBracketClassBytes(int $p, int $ec): string
    {
        $pattern = $this->pattern;
        $acceptedByItem = str_repeat("\0", 256);
        $sig = true;
        if ($pattern[$p + 1] === '^') {
            $sig = false;
            $p++;  // skip the '^'
        }
        while (++$p < $ec) {
            if ($pattern[$p] === self::L_ESC) {
                $p++;
                $acceptedByItem |= $this->matchClassBytes(\ord($pattern[$p]));
            } elseif ($pattern[$p + 1] === '-' && $p + 2 < $ec) {
                $p += 2;
                $first = \ord($pattern[$p - 2]);
                $last = \ord($pattern[$p]);
                if ($first <= $last) {  // (an inverted range accepts nothing)
                    $acceptedByItem |= str_repeat("\0", $first) . str_repeat("\1", $last - $first + 1) . str_repeat("\0", 255 - $last);
                }
            } else {
                $acceptedByItem[\ord($pattern[$p])] = "\1";
            }
        }
        return $sig ? $acceptedByItem : $acceptedByItem ^ str_repeat("\1", 256);
    }

    // lstrlib.c: matchbalance
    private function matchBalance(int $s, int $p): int
    {
        if ($p >= $this->patternEnd - 1) {
            Auxiliary::error($this->L, "malformed pattern (missing arguments to '%b')");
        }
        $src = $this->src;
        $srcEnd = $this->srcEnd;
        // at the end of the subject C compares the terminating '\0': no match either way
        if ($s >= $srcEnd || $src[$s] !== $this->pattern[$p]) {
            return -1;
        }
        $begin = $this->pattern[$p];
        $end = $this->pattern[$p + 1];
        $cont = 1;
        while (++$s < $srcEnd) {
            $character = $src[$s];
            if ($character === $end) {
                if (--$cont === 0) {
                    return $s + 1;
                }
            } elseif ($character === $begin) {
                $cont++;
            }
        }
        return -1;  // string ends out of balance
    }

    // lstrlib.c: max_expand
    private function maxExpand(int $s, int $p, int $ep): int
    {
        $i = 0;  // counts maximum expand for item
        while ($this->singleMatch($s + $i, $p, $ep)) {
            $i++;
        }
        // keeps trying to match with the maximum repetitions
        while ($i >= 0) {
            $result = $this->match($s + $i, $ep + 1);
            if ($result !== -1) {
                return $result;
            }
            $i--;  // else didn't match; reduce 1 repetition to try again
        }
        return -1;
    }

    // lstrlib.c: min_expand
    private function minExpand(int $s, int $p, int $ep): int
    {
        for (;;) {
            $result = $this->match($s, $ep + 1);
            if ($result !== -1) {
                return $result;
            }
            if ($this->singleMatch($s, $p, $ep)) {
                $s++;  // try with one more repetition
            } else {
                return -1;
            }
        }
    }

    // lstrlib.c: start_capture
    private function startCapture(int $s, int $p, int $what): int
    {
        $level = $this->level;
        if ($level >= self::LUA_MAXCAPTURES) {
            Auxiliary::error($this->L, 'too many captures');
        }
        $this->captureInit[$level] = $s;
        $this->captureLen[$level] = $what;
        $this->level = $level + 1;
        $result = $this->match($s, $p);
        if ($result === -1) {  // match failed?
            $this->level--;  // undo capture
        }
        return $result;
    }

    // lstrlib.c: end_capture
    private function endCapture(int $s, int $p): int
    {
        $l = $this->captureToClose();
        $this->captureLen[$l] = $s - $this->captureInit[$l];  // close capture
        $result = $this->match($s, $p);
        if ($result === -1) {  // match failed?
            $this->captureLen[$l] = self::CAP_UNFINISHED;  // undo capture
        }
        return $result;
    }

    // lstrlib.c: match_capture
    private function matchCapture(int $s, int $l): int
    {
        $l = $this->checkCapture($l);
        $length = $this->captureLen[$l];
        // a position capture's length (CAP_POSITION) is a huge size_t in C: never matches
        if ($length >= 0 && $this->srcEnd - $s >= $length
            && substr($this->src, $s, $length) === substr($this->src, $this->captureInit[$l], $length)) {
            return $s + $length;
        }
        return -1;
    }

    /**
     * lstrlib.c: match. Returns the end of the match of pattern position
     * $p at subject position $s, or -1.
     */
    private function match(int $s, int $p): int
    {
        if ($this->matchdepth-- === 0) {
            Auxiliary::error($this->L, 'pattern too complex');
        }
        $pattern = $this->pattern;
        $patternEnd = $this->patternEnd;
        while ($p !== $patternEnd) {  // end of pattern? (loop: C's 'goto init')
            $patternCharacter = $pattern[$p];
            if ($patternCharacter === '(') {  // start capture
                if ($pattern[$p + 1] === ')') {  // position capture?
                    $s = $this->startCapture($s, $p + 2, self::CAP_POSITION);
                } else {
                    $s = $this->startCapture($s, $p + 1, self::CAP_UNFINISHED);
                }
                break;
            }
            if ($patternCharacter === ')') {  // end capture
                $s = $this->endCapture($s, $p + 1);
                break;
            }
            if ($patternCharacter === '$' && $p + 1 === $patternEnd) {  // is the '$' the last char in pattern?
                $s = ($s === $this->srcEnd) ? $s : -1;  // check end of string
                break;
            }
            if ($patternCharacter === self::L_ESC) {  // escaped sequences not in the format class[*+?-]?
                $next = $pattern[$p + 1];
                if ($next === 'b') {  // balanced string?
                    $s = $this->matchBalance($s, $p + 2);
                    if ($s !== -1) {
                        $p += 4;
                        continue;  // return match(ms, s, p + 4);
                    }
                    break;  // else fail (s == NULL)
                }
                if ($next === 'f') {  // frontier?
                    $p += 2;
                    if ($pattern[$p] !== '[') {
                        Auxiliary::error($this->L, "missing '[' after '%f' in pattern");
                    }
                    $ep = $this->classEnd($p);  // points to what is next
                    $previous = ($s === 0) ? 0 : \ord($this->src[$s - 1]);
                    $current = ($s < $this->srcEnd) ? \ord($this->src[$s]) : 0;
                    if (!$this->matchBracketClass($previous, $p, $ep - 1)
                        && $this->matchBracketClass($current, $p, $ep - 1)) {
                        $p = $ep;
                        continue;  // return match(ms, s, ep);
                    }
                    $s = -1;  // match failed
                    break;
                }
                if ($next >= '0' && $next <= '9') {  // capture results (%0-%9)?
                    $s = $this->matchCapture($s, \ord($next));
                    if ($s !== -1) {
                        $p += 2;
                        continue;  // return match(ms, s, p + 2)
                    }
                    break;
                }
                // else goto dflt
            }
            // default: pattern class plus optional suffix
            if ($patternCharacter === self::L_ESC || $patternCharacter === '[') {
                $ep = $this->classEnd($p);  // points to optional suffix
                $matched = $this->singleMatch($s, $p, $ep);
            } else {  // (classend and singlematch of a single character, inlined)
                $ep = $p + 1;
                $matched = $s < $this->srcEnd && ($patternCharacter === '.' || $patternCharacter === $this->src[$s]);
            }
            $suffix = $pattern[$ep];
            if (!$matched) {  // does not match at least once?
                if ($suffix === '*' || $suffix === '?' || $suffix === '-') {  // accept empty?
                    $p = $ep + 1;
                    continue;  // return match(ms, s, ep + 1);
                }
                $s = -1;  // '+' or no suffix: fail
                break;
            }
            // matched once
            if ($suffix === '?') {  // optional
                $result = $this->match($s + 1, $ep + 1);
                if ($result !== -1) {
                    $s = $result;
                    break;
                }
                $p = $ep + 1;
                continue;  // else return match(ms, s, ep + 1);
            }
            if ($suffix === '+') {  // 1 or more repetitions
                $s = $this->maxExpand($s + 1, $p, $ep);  // 1 match already done
                break;
            }
            if ($suffix === '*') {  // 0 or more repetitions
                $s = $this->maxExpand($s, $p, $ep);
                break;
            }
            if ($suffix === '-') {  // 0 or more repetitions (minimum)
                $s = $this->minExpand($s, $p, $ep);
                break;
            }
            // no suffix
            $s++;
            $p = $ep;  // return match(ms, s + 1, ep);
        }
        $this->matchdepth++;
        return $s;
    }

    /**
     * lstrlib.c: get_onecapture + push_onecapture: capture $i of a match
     * $s..$e (the whole match when there are no captures and $i is 0): a
     * string, or the position (integer) of a position capture.
     */
    public function getCapture(int $i, int $s, int $e): string|int
    {
        if ($i >= $this->level) {
            if ($i !== 0) {
                Auxiliary::error($this->L, 'invalid capture index %' . ($i + 1));
            }
            return substr($this->src, $s, $e - $s);
        }
        $captureLength = $this->captureLen[$i];
        if ($captureLength === self::CAP_UNFINISHED) {
            Auxiliary::error($this->L, 'unfinished capture');
        }
        if ($captureLength === self::CAP_POSITION) {
            return $this->captureInit[$i] + 1;
        }
        return substr($this->src, $this->captureInit[$i], $captureLength);
    }

    /**
     * lstrlib.c: push_captures: all captures of a match $s..$e (the whole
     * match if there are none; with $s === -1, C's NULL, nothing then).
     *
     * @return list<string|int>
     */
    public function captures(int $s, int $e): array
    {
        $levelCount = ($this->level === 0 && $s !== -1) ? 1 : $this->level;
        Auxiliary::checkStack($this->L, $levelCount, 'too many captures');
        $captures = [];
        for ($i = 0; $i < $levelCount; $i++) {
            $captures[] = $this->getCapture($i, $s, $e);
        }
        return $captures;
    }
}
