<?php

/**
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
 * The generator and the replay are tests/fuzz/pattern_fuzzer.php.
 */

declare(strict_types=1);

require __DIR__ . '/pattern_fuzzer.php';

$command = $argv[1] ?? '';
if ($command === 'corpus') {
    file_put_contents(
        REPO_ROOT . '/tests/diff/string_pattern_corpus.bin',
        implode('', batchCases(CORPUS_SEED, CORPUS_CASES)),
    );
    exit(0);
}
if ($command === 'show' && \count($argv) === 5) {
    echo describeCase(batchCases((int) $argv[2], (int) $argv[3])[(int) $argv[4] - 1]), "\n";
    exit(0);
}
if ($command !== 'run' || \count($argv) < 4) {
    fwrite(STDERR, "usage: php tests/fuzz/patterns.php run <first seed> <batches> [cases per batch]"
        . " | corpus | show <seed> <cases> <case>\n");
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
    printf(
        "seed %d: %d cases, %d differing (%.1fs)\n",
        $seed,
        $casesPerBatch,
        $differing,
        microtime(true) - $startTime,
    );
}
rmdir($scratchDirectory);
printf("total: %d cases, %d differing\n", $totalCases, $totalDiffering);
exit($totalDiffering === 0 ? 0 : 1);
