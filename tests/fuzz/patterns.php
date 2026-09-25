<?php

declare(strict_types=1);

/*
 * Differential fuzzer for Lua pattern matching: string.find (with init and
 * plain), match, gmatch (with init) and gsub (string, table, function and
 * number replacements, with and without a limit) over random patterns,
 * valid and malformed, and subjects built mostly from the pattern's bytes.
 *
 * Every batch is one binary case file replayed by
 * tests/diff/string_pattern_corpus.lua under lua5.4, bin/lua (patterns
 * translated to PCRE, src/Lib/String/PatternRegex.php, from their second
 * use: the replay runs every case twice) and bin/lua with the port of
 * lstrlib.c forced (tests/fuzz/force_port.php). The three outputs (results
 * and error messages, one line per case) must be identical.
 *
 *   php tests/fuzz/patterns.php run <first seed> <batches> [cases per batch]
 *   php tests/fuzz/patterns.php corpus
 *       rewrites tests/diff/string_pattern_corpus.bin (seed 1, 3000 cases)
 *   php tests/fuzz/patterns.php show <seed> <cases per batch> <case>
 *       prints one case of a batch
 *
 * Run it inside the memory cap and the heavy-run lock like any PHP/Lua run.
 */

const REPO_ROOT = __DIR__ . '/../..';
const CORPUS_SEED = 1;
const CORPUS_CASES = 3000;

function chance(float $probability): bool
{
    return mt_rand() / mt_getrandmax() < $probability;
}

/** @template T @param list<T> $items @return T */
function pick(array $items): mixed
{
    return $items[mt_rand(0, \count($items) - 1)];
}

/** the bytes a case's pattern and subject are mostly made of */
function randomPalette(): array
{
    $pools = [
        ['a', 'b', 'c', 'x', 'A', 'Z'],
        ['0', '1', '9'],
        ['^', '$', '*', '+', '?', '.', '(', ')', '[', ']', '%', '-'],
        [' ', "\n", "\t", "\0", "\x01", "\x7f"],
        ["\x80", "\xff", "\xe9"],
        ['_', ',', '/', '"', '\\', "'", '{', '}'],
    ];
    $weights = [5, 2, 3, 2, 1, 1, 1];  // (the last one: any byte)
    $palette = [];
    $size = mt_rand(2, 6);
    while (\count($palette) < $size) {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $pool => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                $palette[] = isset($pools[$pool]) ? pick($pools[$pool]) : \chr(mt_rand(0, 255));
                break;
            }
        }
    }
    return $palette;
}

function classLetter(): string
{
    if (chance(0.1)) {
        return pick(['q', 'Q', 'y', 'e', 'E', 'o', 'n', 'B', 'F']);  // not classes: the letter itself
    }
    return pick(str_split('acdglpsuwxzACDGLPSUWXZ'));
}

/** a set '[...]' with lstrlib.c's corner cases (']' first, '^', ranges either way, escapes) */
function randomSet(array $palette): string
{
    $set = '[';
    if (chance(0.3)) {
        $set .= '^';
    }
    if (chance(0.1)) {
        $set .= ']';
    }
    $itemCount = chance(0.05) ? 0 : mt_rand(1, 4);
    for ($i = 0; $i < $itemCount; $i++) {
        $roll = mt_rand(1, 100);
        if ($roll <= 40) {
            $set .= pick($palette);
        } elseif ($roll <= 65) {
            $set .= pick($palette) . '-' . pick($palette);
        } elseif ($roll <= 80) {
            $set .= '%' . classLetter();
        } elseif ($roll <= 90) {
            $set .= '%' . pick([...$palette, ']', '-', '^', '%', '[']);
        } elseif ($roll <= 95) {
            $set .= '-';
        } elseif ($roll <= 98) {
            $set .= '^';
        } else {
            $set .= '[';
        }
    }
    return $set . ']';
}

/**
 * A random pattern and the number of its '*', '+' and '-' items (which
 * bound how long a subject may be before backtracking gets slow).
 *
 * @return array{string, int}
 */
function randomPattern(array $palette): array
{
    if (chance(0.03)) {
        return longPattern($palette);
    }
    $pattern = chance(0.3) ? '^' : '';
    $itemCount = mt_rand(0, 7);
    $openCaptures = 0;
    $closedCaptures = 0;
    $unbounded = 0;
    for ($i = 0; $i < $itemCount; $i++) {
        $roll = mt_rand(1, 100);
        $isClass = true;
        if ($roll <= 30) {
            $item = pick($palette);
        } elseif ($roll <= 35) {
            $item = '%' . pick($palette);
        } elseif ($roll <= 43) {
            $item = '.';
        } elseif ($roll <= 55) {
            $item = '%' . classLetter();
        } elseif ($roll <= 67) {
            $item = randomSet($palette);
        } else {
            $isClass = false;
            if ($roll <= 74) {
                $item = '(';
                $openCaptures++;
            } elseif ($roll <= 80) {
                $item = ($openCaptures > 0 || chance(0.1)) ? ')' : '';  // mostly balanced
                if ($openCaptures > 0) {
                    $openCaptures--;
                    $closedCaptures++;
                }
            } elseif ($roll <= 83) {
                $item = '()';
                $closedCaptures++;
            } elseif ($roll <= 87) {  // back reference, mostly to a finished capture
                $index = ($closedCaptures > 0 && chance(0.8)) ? mt_rand(1, min(9, $closedCaptures))
                    : pick([0, 1, 1, 2, 3, 4, 9]);
                $item = '%' . $index;
            } elseif ($roll <= 91) {
                $item = '%f' . randomSet($palette);
            } elseif ($roll <= 93) {
                $item = '%b' . pick($palette) . pick($palette);
            } elseif ($roll <= 97) {
                $item = pick(['$', '^']);
            } else {
                $item = pick(['*', '+', '?', '-']);
            }
        }
        if ($isClass && chance(0.45)) {
            $suffix = pick(['*', '+', '?', '-']);
            $item .= $suffix;
            if ($suffix !== '?') {
                $unbounded++;
            }
        }
        $pattern .= $item;
    }
    while ($openCaptures > 0 && chance(0.85)) {
        $pattern .= ')';
        $openCaptures--;
    }
    if (chance(0.15)) {
        $pattern .= '$';
    }
    if (chance(0.03)) {  // malformed ending
        $pattern .= pick(['%', '[', '[^', '[a', '[%', '%b', '%bx', '%f', '%fx', '%f[a', '[]', '[^]']);
    }
    return [$pattern, $unbounded];
}

/**
 * Long patterns: near lstrlib.c's MAXCCALLS (pattern too complex), past
 * LUA_MAXCAPTURES, and past the translator's length limits.
 *
 * @return array{string, int}
 */
function longPattern(array $palette): array
{
    $kind = mt_rand(1, 4);
    if ($kind === 1) {  // recursion depth: every matched 'a?' nests a match() call
        $count = mt_rand(170, 215);
        // (a longer tail of 'a' makes every implementation backtrack for seconds)
        return [(chance(0.5) ? '^' : '') . str_repeat('a?', $count) . str_repeat('a', mt_rand(0, 1)), 0];
    }
    if ($kind === 2) {  // captures around the limit of 32
        $count = mt_rand(28, 35);
        return [str_repeat(pick(['(a)', '()', '(.)', '(%a*)']), $count), 0];
    }
    if ($kind === 3) {  // quantified sets, close to the recursion limit
        $count = mt_rand(90, 105);
        return [str_repeat('(' . randomSet($palette) . '?)', $count >> 2) . str_repeat('[ab]?', $count), 0];
    }
    return [str_repeat(pick($palette) . '?', mt_rand(200, 300)), 0];  // over 512 bytes: never translated
}

/** a byte of each class letter, and one outside it (for the uppercase letter) */
const CLASS_SAMPLES = [
    'a' => ['k', 'Q'], 'c' => ["\x05", "\x7f"], 'd' => ['7', '0'], 'g' => ['#', '~'], 'l' => ['m', 'z'],
    'p' => ['!', '_'], 's' => [' ', "\n"], 'u' => ['M', 'A'], 'w' => ['w', '5'], 'x' => ['f', 'C'], 'z' => ["\0"],
];

/** bytes at the edges of the C-locale classes */
const BOUNDARY_BYTES = [
    "\0", "\x08", "\t", "\n", "\x0b", "\x0c", "\r", "\x0e", "\x1f", ' ', '!', '/', '0', '9', ':', '@', 'A', 'F',
    'G', 'Z', '[', '_', '`', 'a', 'f', 'g', 'z', '{', '~', "\x7f", "\x80", "\xff",
];

/**
 * Roughly an instance of the pattern (so that matches are common), with
 * noise: literal bytes, a byte for each class, repetitions for suffixes.
 */
function patternInstance(string $pattern, array $palette): string
{
    $instance = '';
    $last = '';
    $length = \strlen($pattern);
    for ($i = 0; $i < $length; $i++) {
        $character = $pattern[$i];
        $piece = '';
        if ($character === '%' && $i + 1 < $length) {
            $next = $pattern[++$i];
            $samples = CLASS_SAMPLES[strtolower($next)] ?? null;
            if ($samples !== null) {
                $piece = chance(0.5) ? pick(BOUNDARY_BYTES) : (ctype_upper($next) ? pick(['Q', ' ', '7', '!', "\xe9"]) : pick($samples));
            } elseif ($next === 'b' && $i + 2 < $length) {
                $piece = $pattern[$i + 1] . pick([...$palette, '']) . $pattern[$i + 2];
                $i += 2;
            } elseif ($next === 'f' || ctype_digit($next)) {
                $piece = '';
            } else {
                $piece = $next;
            }
        } elseif ($character === '[') {
            $close = $i + 2 < $length ? strpos($pattern, ']', $i + 2) : false;
            $inside = substr($pattern, $i + 1, ($close === false ? $length : $close) - $i - 1);
            $piece = $inside === '' ? '' : $inside[mt_rand(0, \strlen($inside) - 1)];
            $i = $close === false ? $length : $close;
        } elseif ($character === '.') {
            $piece = pick($palette);
        } elseif (\in_array($character, ['*', '+', '-'], true)) {
            $piece = str_repeat($last, mt_rand(0, 3));
        } elseif ($character === '?') {
            $piece = '';
        } elseif (\in_array($character, ['(', ')', '^', '$'], true)) {
            $piece = chance(0.1) ? $character : '';
        } else {
            $piece = $character;
        }
        $instance .= $piece;
        $last = $piece;
    }
    return $instance;
}

function randomSubject(string $pattern, array $palette, int $unbounded): string
{
    if (chance(0.05)) {
        return '';
    }
    if (\strlen($pattern) > 100) {  // long patterns: long runs of the bytes they repeat
        return str_repeat(pick(['a', 'a', 'a', 'b', ' ']), mt_rand(0, 3) === 0 ? mt_rand(0, 150) : mt_rand(150, 230));
    }
    // long subjects (translated at their first use) only where backtracking stays cheap
    $maximumLength = $unbounded <= 1 && chance(0.05) ? 600 : ($unbounded <= 2 ? 40 : ($unbounded <= 4 ? 16 : 8));
    $bytes = [...$palette, ...str_split($pattern === '' ? 'a' : $pattern)];
    $noise = static function (int $length) use ($bytes): string {
        $noise = '';
        for ($i = 0; $i < $length; $i++) {
            $roll = mt_rand(1, 100);
            $noise .= $roll <= 75 ? pick($bytes) : ($roll <= 90 ? pick(BOUNDARY_BYTES) : \chr(mt_rand(0, 255)));
        }
        return $noise;
    };
    if (chance(0.6)) {
        $subject = $noise(mt_rand(0, 3)) . patternInstance($pattern, $palette) . $noise(mt_rand(0, 3));
        while (chance($maximumLength > 40 ? 0.97 : 0.3) && \strlen($subject) < $maximumLength) {
            $subject .= patternInstance($pattern, $palette) . $noise(mt_rand(0, 2));
        }
        $subject = substr($subject, 0, $maximumLength);
    } else {
        $subject = $noise(chance(0.85) ? mt_rand(0, min(12, $maximumLength)) : mt_rand(0, $maximumLength));
    }
    return chance(0.1) ? $subject . "\n" : $subject;  // (PCRE's '$' would match before it)
}

function randomPosition(int $length): int
{
    if (chance(0.7)) {
        return mt_rand(-$length - 2, $length + 2);
    }
    return pick([0, 1, $length, $length + 1, $length + 2, -1, PHP_INT_MIN, PHP_INT_MAX]);
}

function randomReplacementString(array $palette): string
{
    $replacement = '';
    $tokenCount = mt_rand(0, 6);
    for ($i = 0; $i < $tokenCount; $i++) {
        $roll = mt_rand(1, 100);
        if ($roll <= 45) {
            $replacement .= pick($palette);
        } elseif ($roll <= 85) {
            $replacement .= '%' . pick(['0', '1', '1', '2', '2', '3', '4', '9']);
        } elseif ($roll <= 95) {
            $replacement .= '%%';
        } else {
            $replacement .= pick(['%x', '%']);
        }
    }
    return $replacement;
}

/**
 * A class probe: one single character class (a class letter, a set or a
 * frontier) over random bytes, so that every class meets every byte.
 *
 * @return array{string, string}
 */
function classProbe(array $palette): array
{
    $roll = mt_rand(1, 100);
    if ($roll <= 45) {
        $class = '%' . classLetter();
    } elseif ($roll <= 85) {
        $class = randomSet([...$palette, ...BOUNDARY_BYTES]);
    } else {
        $class = '%f' . randomSet([...$palette, ...BOUNDARY_BYTES]);
    }
    $pattern = $class . pick(['', '', '+', '*', '-', '?']);
    $subject = '';
    $length = mt_rand(16, 64);
    for ($i = 0; $i < $length; $i++) {
        $subject .= chance(0.3) ? pick(BOUNDARY_BYTES) : \chr(mt_rand(0, 255));
    }
    return [$pattern, $subject];
}

/** one case as the record string_pattern_corpus.lua reads ("<s4s4s4BjBBs4Bj") */
function randomCase(): string
{
    $palette = randomPalette();
    $isProbe = chance(0.1);
    if ($isProbe) {  // every byte of the subject counts: gmatch or gsub over all of it
        [$pattern, $subject] = classProbe($palette);
        $kind = pick(['gmatch', 'gsub']);
    } else {
        [$pattern, $unbounded] = randomPattern($palette);
        $subject = randomSubject($pattern, $palette, $unbounded);
        $kind = pick(['find', 'find', 'find', 'match', 'match', 'match', 'gmatch', 'gmatch', 'gsub', 'gsub', 'gsub']);
    }
    $hasInit = $kind !== 'gsub' && !$isProbe && chance(0.5);
    $init = $hasInit ? randomPosition(\strlen($subject)) : 0;
    $plain = $kind === 'find' ? pick([0, 0, 0, 0, 0, 1, 2]) : 0;
    $replacementKind = 0;
    $replacement = '';
    $hasLimit = false;
    $limit = 0;
    if ($kind === 'gsub') {
        $replacementKind = pick([1, 1, 1, 1, 2, 2, 3, 3, 3, 4]);
        $replacement = match ($replacementKind) {
            1 => randomReplacementString($palette),
            2 => '',
            3 => pick(['nil', 'false', 'concat', 'concat', 'count', 'table', 'first', 'first']),
            4 => pick(['42', '1.5', '-0.0', '1e100', '0', '-7', '3.0']),
        };
        $hasLimit = !$isProbe && chance(0.3);
        $limit = $hasLimit ? pick([-1, 0, 1, 1, 2, 2, 3, 4, PHP_INT_MIN, PHP_INT_MAX]) : 0;
    }
    $string = static fn (string $value): string => pack('V', \strlen($value)) . $value;
    return $string($kind) . $string($subject) . $string($pattern)
        . pack('CP', $hasInit ? 1 : 0, $init) . pack('CC', $plain, $replacementKind)
        . $string($replacement) . pack('CP', $hasLimit ? 1 : 0, $limit);
}

/** @return list<string> the records of a batch */
function batchCases(int $seed, int $count): array
{
    mt_srand($seed);
    $cases = [];
    for ($i = 0; $i < $count; $i++) {
        $cases[] = randomCase();
    }
    return $cases;
}

/** a record decoded for humans */
function describeCase(string $record): string
{
    $position = 0;
    $readString = static function () use ($record, &$position): string {
        $length = unpack('V', $record, $position)[1];
        $value = substr($record, $position + 4, $length);
        $position += 4 + $length;
        return $value;
    };
    $kind = $readString();
    $subject = $readString();
    $pattern = $readString();
    ['has' => $hasInit, 'value' => $init] = unpack('Chas/Pvalue', $record, $position);
    $position += 9;
    ['plain' => $plain, 'kind' => $replacementKind] = unpack('Cplain/Ckind', $record, $position);
    $position += 2;
    $replacement = $readString();
    ['has' => $hasLimit, 'value' => $limit] = unpack('Chas/Pvalue', $record, $position);
    $quote = static fn (string $value): string => '"' . addcslashes($value, "\0..\37\"\\\177..\377") . '"';
    $arguments = [$quote($subject), $quote($pattern)];
    if ($kind === 'gsub') {
        $arguments[] = match ($replacementKind) {
            1 => $quote($replacement),
            2 => 'replacementTable',
            3 => "function:$replacement",
            4 => $replacement,
        };
        if ($hasLimit) {
            $arguments[] = (string) $limit;
        }
    } else {
        $arguments[] = $hasInit ? (string) $init : 'nil';
        if ($kind === 'find') {
            $arguments[] = ['nil', 'true', 'false'][$plain];
        }
    }
    return "string.$kind(" . implode(', ', $arguments) . ')';
}

/** @return array{int, string, string} [exit status, stdout, stderr] */
function runReplay(array $command, string $caseFile): array
{
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        REPO_ROOT . '/tests/diff',
        ['LUA_PATTERN_CASES' => $caseFile, 'PATH' => getenv('PATH')],
    );
    $standardOutput = stream_get_contents($pipes[1]);
    $standardError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $standardOutput, $standardError];
}

/**
 * Replay one batch under the three implementations; returns the number of
 * cases whose outputs differ (and prints them).
 */
function runBatch(int $seed, int $count, string $scratchDirectory): int
{
    $cases = batchCases($seed, $count);
    $caseFile = "$scratchDirectory/cases-$seed.bin";
    file_put_contents($caseFile, implode('', $cases));
    $script = 'string_pattern_corpus.lua';
    $ourLua = realpath(REPO_ROOT . '/bin/lua');
    $commands = [
        'lua5.4' => ['lua5.4', $script],
        'regex' => ['php', $ourLua, $script],
        'port' => ['php', '-d', 'auto_prepend_file=' . realpath(__DIR__ . '/force_port.php'), $ourLua, $script],
    ];
    $runs = [];
    $seconds = [];
    foreach ($commands as $name => $command) {
        $startTime = microtime(true);
        $runs[$name] = runReplay($command, $caseFile);
        $seconds[] = \sprintf('%s %.1fs', $name, microtime(true) - $startTime);
    }
    echo "seed $seed: " . implode(', ', $seconds) . "\n";
    unlink($caseFile);
    $differing = 0;
    foreach ($runs as $name => [$status, , $standardError]) {
        if ($status !== 0 || $standardError !== '') {
            echo "seed $seed: $name exited with $status: " . substr($standardError, 0, 2000) . "\n";
            $differing++;
        }
    }
    // case number => its output lines
    $outputs = [];
    foreach ($runs as $name => [, $standardOutput]) {
        $outputs[$name] = [];
        foreach (explode("\n", rtrim($standardOutput, "\n")) as $line) {
            $index = (int) strstr($line, "\t", true);
            $outputs[$name][$index] = isset($outputs[$name][$index]) ? $outputs[$name][$index] . "\n  " . $line : $line;
        }
    }
    for ($index = 1; $index <= $count; $index++) {
        $reference = $outputs['lua5.4'][$index] ?? '<missing>';
        if (($outputs['regex'][$index] ?? '<missing>') === $reference && ($outputs['port'][$index] ?? '<missing>') === $reference) {
            continue;
        }
        $differing++;
        if ($differing <= 20) {
            echo "seed $seed case $index: " . describeCase($cases[$index - 1]) . "\n";
            foreach ($outputs as $name => $output) {
                printf("  %-7s %s\n", $name, substr($output[$index] ?? '<missing>', 0, 800));
            }
        }
    }
    return $differing;
}

$command = $argv[1] ?? '';
if ($command === 'corpus') {
    file_put_contents(REPO_ROOT . '/tests/diff/string_pattern_corpus.bin', implode('', batchCases(CORPUS_SEED, CORPUS_CASES)));
    exit(0);
}
if ($command === 'show' && \count($argv) === 5) {
    echo describeCase(batchCases((int) $argv[2], (int) $argv[3])[(int) $argv[4] - 1]), "\n";
    exit(0);
}
if ($command !== 'run' || \count($argv) < 4) {
    fwrite(STDERR, "usage: php tests/fuzz/patterns.php run <first seed> <batches> [cases per batch] | corpus | show <seed> <cases> <case>\n");
    exit(2);
}
$firstSeed = (int) $argv[2];
$batchCount = (int) $argv[3];
$casesPerBatch = (int) ($argv[4] ?? 5000);
$scratchDirectory = sys_get_temp_dir() . '/luaphp-pattern-fuzz-' . getmypid();
@mkdir($scratchDirectory);
$totalCases = 0;
$totalDiffering = 0;
for ($seed = $firstSeed; $seed < $firstSeed + $batchCount; $seed++) {
    $startTime = microtime(true);
    $differing = runBatch($seed, $casesPerBatch, $scratchDirectory);
    $totalCases += $casesPerBatch;
    $totalDiffering += $differing;
    printf("seed %d: %d cases, %d differing (%.1fs)\n", $seed, $casesPerBatch, $differing, microtime(true) - $startTime);
}
rmdir($scratchDirectory);
printf("total: %d cases, %d differing\n", $totalCases, $totalDiffering);
exit($totalDiffering === 0 ? 0 : 1);
