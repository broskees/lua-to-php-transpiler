# Benchmark: saved-php-38f79d7

- date: 2026-10-02 16:46
- commit: 38f79d7
- PHP 8.5.11 (cli) (built: Sep 26 2026 07:57:28) (NTS)
- Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio
- php.ini opcache.jit: disable
- speed: median of 5 runs

## Memory (MB)

| workload | lua5.4 peak RSS - startup | bin/lua peak RSS - startup | lua5.4 VmPeak | bin/lua VmPeak |
|---|---:|---:|---:|---:|
| startup (empty.lua, absolute) | 2.8 | 33.2 | 5.3 | 225.1 |
| closures | 11.1 | 16.8 | 16.4 | 243.6 |
| coroutines | 12.0 | 101.1 | 17.3 | 2942.6 |
| empty_tables | 8.1 | 18.8 | 13.4 | 249.6 |
| int_array | 16.1 | 15.9 | 21.3 | 251.1 |
| linked_list_drop | 122.1 | 490.5 | 127.4 | 716.1 |
| records | 27.0 | 52.4 | 32.3 | 277.6 |
| strings | 17.5 | 15.8 | 22.6 | 241.1 |

1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 yes, bin/lua yes

## Speed (median wall seconds, process startup included)

*lua5.4* runs the Lua file. *saved PHP* runs the file `bin/lua2php` saved (`php out.php`) with a warm opcache: `php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d opcache.file_cache=<directory>`, after one untimed run that fills the file cache. *JIT* adds `-d opcache.jit=tracing` (its own file cache). *transpile ms* is `bin/lua2php in.lua -o out.php` under the same options (one untimed run first), not part of any run time.

| workload | lua5.4 | saved PHP | saved PHP / lua5.4 | saved PHP, JIT | JIT / lua5.4 | transpile ms |
|---|---:|---:|---:|---:|---:|---:|
| closures | 0.049 | 0.690 | 14.0 | 0.417 | 8.4 | 14 |
| coroutines | 0.006 | 0.114 | 17.8 | 0.106 | 16.5 | 15 |
| fib | 0.007 | 0.190 | 25.9 | 0.144 | 19.6 | 15 |
| iterators | 0.010 | 0.258 | 25.7 | 0.206 | 20.5 | 15 |
| objects | 0.032 | 0.474 | 14.8 | 0.345 | 10.8 | 16 |
| oop | 0.020 | 0.429 | 22.0 | 0.270 | 13.9 | 15 |
| small_tables | 0.070 | 0.336 | 4.8 | 0.181 | 2.6 | 15 |
| string_library | 0.042 | 0.397 | 9.4 | 0.304 | 7.2 | 16 |
| strings | 0.062 | 0.342 | 5.5 | 0.240 | 3.9 | 15 |
| table_insert_sum | 0.035 | 0.511 | 14.4 | 0.294 | 8.3 | 16 |
| table_library | 0.030 | 0.261 | 8.6 | 0.173 | 5.7 | 16 |

## Official test suite under bin/lua (median wall seconds)

`php bin/lua -e"_U=true" all.lua` (the ambient php.ini) transpiles each file while it runs. lua2php output cannot run all.lua: files.lua `dofile()`s a file it writes at run time, never transpiled.

| | lua5.4 | bin/lua | bin/lua / lua5.4 |
|---|---:|---:|---:|
| all.lua -e"_U=true" | 0.244 | 8.133 | 33.3 |

## Transpiling (median wall seconds)

`bin/lua2php <directory> -o <output>` on the 33 official test files, 16,597 lines, in one process (startup included, the speed section's opcache options): 0.460 s, 28 ms per 1,000 lines

## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e"_U=true")

| lua5.4 | bin/lua |
|---:|---:|
| 128 MB | 1536 MB |
