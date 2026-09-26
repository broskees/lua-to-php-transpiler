# Benchmark: the embedding API (lane/embed-api, with lane/embed-rtm merged)

- date: 2026-09-26
- PHP 8.5.9 (cli) (NTS); Lua 5.4.9
- `bench/embed.php` (one warm process, 7 runs, medians), each in a 3 GB
  systemd scope with an 8 MB stack

## Sandboxes, through the public API

| measure | eval | include (opcache) | include (opcache, tracing JIT) | target |
|---|---:|---:|---:|---:|
| new sandbox, SAFE, default limits | 0.037 ms | 0.038 ms | 0.033 ms | <= 0.05 ms |
| new sandbox, Libraries::ALL | 0.055 ms | 0.056 ms | 0.047 ms | |
| `Environment::run` of the discount example | 0.099 ms | 0.095 ms | 0.071 ms | |
| memory per empty SAFE sandbox (kept alive) | 75.3 KB | 75.3 KB | 75.6 KB | |
| memory per SAFE sandbox after the discount example | 77.2 KB | 77.2 KB | 77.5 KB | |
| brief's benchmark, `Limits::none()` | 367.7 ms | 329.5 ms | 93.7 ms | |
| brief's benchmark, every limit set | 392.5 ms | 339.1 ms | 106.1 ms | |
| ratio | 1.067 | 1.029 | 1.132 | <= 1.15 |

Commands: `php -d memory_limit=1G bench/embed.php eval`;
`php -d memory_limit=1G -d opcache.enable_cli=1 -d opcache.file_update_protection=0 bench/embed.php include`;
the same with `-d opcache.jit=tracing -d opcache.jit_buffer_size=64M`.

"include" measures a later process: new Environments that find the scripts
in the cacheDir and include them, so opcache and its JIT compile them. The
process that compiled a script runs it from eval'd code (as load() does),
which the JIT does not compile: in one process, "include" without a new
Environment measured like eval (378 ms, 339 ms with the JIT).

The discount example is the brief's: a module factory with one PHP
function, a `print`, and a table back (`Result::$values` and `$output`).
"Every limit set" is steps (1e9), memoryBytes, seconds, outputBytes,
coroutines and callDepth; the benchmark is counted as 16,817,033 steps. A
new sandbox is `Coroutine::newState` (0.002 ms) plus `openSelected` for
SAFE (0.034 ms); the rest is the Budget, require, the sink and the handles'
table.

## Command line unchanged (fair.sh)

`/var/tmp/luaphp-profiling/fair.sh` on bin/lua2php output: main (7a2e3f9,
exported to a path of the same length) and this branch, one workload at a
time, CPU ms including PHP startup, medians of 7 interleaved runs. The
generated files are identical but for their paths.

| workload | main php | branch php | main php+jit | branch php+jit |
|---|---:|---:|---:|---:|
| empty (startup) | 31-33 | 31-33 | 34-41 | 35-36 |
| closures | 685 | 687 | 422 | 417 |
| coroutines | 135 | 136 | 113 | 104 |
| fib | 199 | 199 | 145 | 140 |
| iterators | 265 | 265 | 207 | 207 |
| objects | 476 | 484 | 352 | 349 |
| oop | 423 | 427 | 269 | 275 |
| small_tables | 342 | 335 | 187 | 187 |
| string_library | 410 | 407 | 310 | 311 |
| strings | 357 | 360 | 239 | 243 |
| table_insert_sum | 525-536 | 541-549 | 267-279 | 285-290 |
| table_library | 269-271 | 275-277 | 168-172 | 172-179 |

table_insert_sum and table_library (three runs each) are the two a million
native calls dominate. Isolated (11 interleaved runs, opcache on, JIT off,
equal paths): table_insert_sum main 532-536 ms, branch 538-542, the branch
without `CallInfo::$depth` 530; table_library main 268, branch 274, without
it 272. So the one cost the command line pays is the call-depth limit's
`CallInfo::$depth` property (about 1% on native-call-heavy code: one more
property to initialize per CallInfo); the rest is noise.
