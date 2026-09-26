# Benchmark: embedding runtime (lane/embed-rtm, from 7a2e3f9)

- date: 2026-09-26
- PHP 8.5.9 (cli) (NTS); Lua 5.4.9
- `bench/embed_runtime.php` (one warm process, 7 or 9 runs, medians), each in a
  3 GB systemd scope with an 8 MB stack

## Embedded states

| measure | eval | include (opcache) | include (opcache, tracing JIT) |
|---|---:|---:|---:|
| state creation, SAFE libraries + budget | 0.034-0.041 ms | 0.034 ms | 0.029 ms |
| state creation, all libraries (as bin/lua) | 0.048-0.057 ms | 0.049 ms | 0.042 ms |
| memory per empty SAFE state | 71.6 KB | 71.6 KB | 71.9 KB |
| brief's benchmark, no step counting, no budget | 404.6 ms | 328.7 ms | 90.4 ms |
| same, step counting and every limit set | 418.1 ms | 342.2 ms | 102.5 ms |
| ratio | 1.033 | 1.041 | 1.134 (1.09-1.17 over 5 runs) |

Commands: `php -d memory_limit=1G bench/embed_runtime.php eval`;
`php -d memory_limit=1G -d opcache.enable_cli=1 -d opcache.file_update_protection=0 bench/embed_runtime.php include`;
the same with `-d opcache.jit=tracing -d opcache.jit_buffer_size=64M`.

The brief's benchmark:

```lua
local s = 0
for i = 1, 3000000 do s = s + i % 7 end
local t = {}
for i = 1, 200000 do t[#t + 1] = tostring(i) end
return s, #table.concat(t)
```

It is counted as 16.8 million steps (the first loop's body is 5
instructions: MODK, MMBINK, ADD, MMBIN, FORLOOP, where C runs 3). A
call-heavy fib(27) (eval, `local function fib(n) ...`) is 11-12% slower:
each call charges its size (about 5% of it) and counts a call level (the
rest).

## Command line unchanged (fair.sh)

`/var/tmp/luaphp-profiling/fair.sh` on bin/lua2php output: main (7a2e3f9,
from a path of the same length) and this branch, one workload at a time,
CPU ms including PHP startup, medians of 7 interleaved runs. The generated
files are identical but for their paths.

| workload | main php | branch php | main php+jit | branch php+jit |
|---|---:|---:|---:|---:|
| empty (startup) | 31-32 | 31-33 | 34-35 | 35-36 |
| closures | 694 | 691 | 424 | 422 |
| coroutines | 133 | 134 | 117 | 105 |
| fib | 196 | 201 | 143 | 149 |
| iterators | 263 | 263 | 206 | 205 |
| objects | 479 | 480 | 345 | 356 |
| oop | 425 | 430 | 270 | 271 |
| small_tables | 334 | 335 | 187 | 185 |
| string_library | 407 | 412 | 311 | 312 |
| strings | 353 | 361 | 238 | 242 |
| table_insert_sum | 525 | 538 | 279 | 280 |
| table_library | 271 | 272 | 171 | 175 |

A print-heavy script (300,000 `print(i, "x")` through bin/lua, CPU ms):
main 396-414, branch 409-420 (print checks for the command line's
output once per call before echoing).
