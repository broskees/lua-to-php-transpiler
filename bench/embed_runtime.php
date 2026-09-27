<?php

/**
 * The runtime costs of embedding (AGENTS.md "Embedding conventions"), in
 * one warm process:
 *
 *     php bench/embed_runtime.php [eval|include] [runs]
 *
 * - creating a state with the SAFE libraries (sandboxed, with a budget),
 *   and with all libraries as bin/lua opens them;
 * - PHP memory per empty SAFE state kept alive;
 * - the embedding brief's benchmark compiled with step counting and run
 *   with every limit set, against the same code compiled without and run
 *   without a budget. "eval": the code is eval'd (as load() does without
 *   a disk cache); "include": it is included from files (as the disk cache
 *   and lua2php output are), so opcache (and its JIT, when on) compile it:
 *   run with -d opcache.enable_cli=1 -d opcache.file_update_protection=0,
 *   and -d opcache.jit=tracing -d opcache.jit_buffer_size=64M for the JIT.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/embed_runtime_functions.php';

use LuaPhp\Compiler\Compiler;
use LuaPhp\Emitter\Emitter;

$mode = $argv[1] ?? 'eval';
$runs = (int) ($argv[2] ?? 7);

for ($i = 0; $i < 200; $i++) {  // warm up
    safeState();
    allState();
}
printf("| state creation, SAFE + budget | %.4f ms |\n", creation(safeState(...), $runs));
printf("| state creation, all libraries | %.4f ms |\n", creation(allState(...), $runs));
printf("| memory per empty SAFE state | %.0f bytes |\n", memoryPerState(safeState(...)));

$proto = Compiler::compile(BENCHMARK, '=benchmark');
$plain = factory(Emitter::emitChunk($proto), $mode, 'plain');
$counted = factory(Emitter::emitChunk($proto, true), $mode, 'counted');
$plainTimes = [];
$countedTimes = [];
for ($run = 0; $run < $runs; $run++) {  // interleaved
    $plainTimes[] = benchmarkRun($plain, false);
    $countedTimes[] = benchmarkRun($counted, true);
}
$jit = \function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false) ? ', JIT' : '';
printf("| benchmark ($mode$jit), no step counting, no budget | %.1f ms |\n", median($plainTimes));
printf("| benchmark ($mode$jit), step counting, every limit | %.1f ms |\n", median($countedTimes));
printf("| ratio | %.3f |\n", median($countedTimes) / median($plainTimes));
