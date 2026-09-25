<?php

declare(strict_types=1);

namespace Tests\PatternRegexTest;

use LuaPhp\Lib\String\MatchState;
use LuaPhp\Lib\String\PatternRegex;
use LuaPhp\Runtime\Standalone;

/*
 * Lua patterns run either on the port of lstrlib.c's matcher or on their
 * PCRE translation (src/Lib/String/PatternRegex.php); both must behave
 * exactly like lua5.4. The fuzz corpus (tests/diff/string_pattern_corpus.*,
 * made by tests/fuzz/patterns.php) runs with the translation as a
 * differential case; here it runs with the port forced.
 */

const CORPUS = 'string_pattern_corpus.lua';

/** @param list<string> $phpOptions */
function assertCorpusBehavesLikeLua(array $phpOptions, string $label): void
{
    $diffDirectory = REPO_ROOT . '/tests/diff';
    $expected = runCommand(['lua5.4', CORPUS], '', $diffDirectory);
    $actual = runCommand(['php', ...$phpOptions, REPO_ROOT . '/bin/lua', CORPUS], '', $diffDirectory);
    assertSame(0, $expected[0], 'lua5.4 exit status');
    assertSame([0, ''], [$actual[0], $actual[2]], "$label: exit status and stderr");
    if ($expected[1] !== $actual[1]) {
        throw new \AssertionFailed("$label: stdout differs" . firstDifference($expected[1], $actual[1]));
    }
}

function test_corpus_with_the_port_forced_behaves_like_lua(): void
{
    assertCorpusBehavesLikeLua(['-d', 'auto_prepend_file=' . REPO_ROOT . '/tests/fuzz/force_port.php'], 'port');
}

function test_pcre_gives_up_on_the_deep_nesting_of_the_fallback_diff_case(): void
{
    // tests/diff/string_pattern_regex.lua relies on it to check that the port takes over mid-call
    $deep = str_repeat('(', 200000) . str_repeat(')', 200000);
    $L = Standalone::newStateWithLibraries();
    $ms = new MatchState($L, $deep, '%b()');
    $translation = PatternRegex::forPattern('%b()', \strlen($deep), $ms);
    assertTrue($translation !== null, '%b() is translated');
    foreach (['JIT' => '1', 'no JIT' => '0'] as $label => $jit) {
        $previous = ini_set('pcre.jit', $jit);
        $found = @preg_match($translation->search, $deep);
        ini_set('pcre.jit', $previous);
        assertSame(false, $found, "$label: preg_match fails");
    }
}

function test_patterns_are_translated_unless_the_matcher_could_raise_an_error(): void
{
    $L = Standalone::newStateWithLibraries();
    $longSubject = str_repeat('x', 100);  // (translated on first use)
    $isTranslated = static function (string $pattern) use ($L, $longSubject): bool {
        $ms = new MatchState($L, $longSubject, $pattern);
        return PatternRegex::forPattern($pattern, \strlen($longSubject), $ms) !== null;
    };
    $translated = ['', 'item', '%d+', '[^,]+', '(%w+)=(%w+)', '%s*(.-)%s*$', '()a()', '(a)%1', '()%1', '[]]', '[z-a]',
        '%f[%w]%w+', '%b()', '%b""', 'a$b', '^a', '%%', 'a**', str_repeat('a?', 190), str_repeat('(a)', 32)];
    foreach ($translated as $pattern) {
        assertTrue($isTranslated($pattern), "translated: '$pattern'");
    }
    // (a pattern here is what match() sees: the drivers strip a leading '^')
    $leftToThePort = ['%', 'x%', '[a', '[^]', '(a', 'a)', '())', '%0', '%1', '(%1)', '(a)%2', '%b', '%bx', '%f', '%fx', '%f[a',
        str_repeat('a?', 191), str_repeat('(a)', 33), str_repeat('a', 513)];
    foreach ($leftToThePort as $pattern) {
        assertTrue(!$isTranslated($pattern), "left to the port: '$pattern'");
    }
}

function test_first_use_on_a_short_subject_runs_the_port(): void
{
    $L = Standalone::newStateWithLibraries();
    $pattern = '%d+' . bin2hex(random_bytes(8));  // never used before
    $short = new MatchState($L, 'short', $pattern);
    assertSame(null, PatternRegex::forPattern($pattern . 'x', 5, $short));
    assertTrue(PatternRegex::forPattern($pattern . 'x', 5, $short) !== null, 'second use');
    $otherPattern = $pattern . 'y';
    $long = new MatchState($L, str_repeat('7', 64), $otherPattern);
    assertTrue(PatternRegex::forPattern($otherPattern, 64, $long) !== null, 'first use on 64 bytes');
}
