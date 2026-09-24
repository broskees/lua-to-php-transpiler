# Benchmark: baseline-2b7e6a4

- date: 2026-09-24 15:45
- commit: 2b7e6a4
- PHP 8.5.9 (cli) (built: Aug 16 2026 19:35:50) (NTS)
- Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio
- speed: median of 5 runs

## Memory (MB)

| workload | lua5.4 peak RSS - startup | ours peak RSS - startup | lua5.4 VmPeak | ours VmPeak |
|---|---:|---:|---:|---:|
| startup (empty.lua, absolute) | 2.7 | 37.2 | 5.3 | 1249.0 |
| closures | 11.1 | 40.8 | 16.4 | 1290.0 |
| coroutines | 11.9 | 109.1 | 17.3 | 2561474.6 |
| empty_tables | 8.0 | 24.6 | 13.4 | 1279.5 |
| int_array | 16.1 | 16.4 | 21.3 | 1275.0 |
| linked_list_drop | 122.0 | 554.9 | 127.4 | 1806.0 |
| records | 27.0 | 59.4 | 32.3 | 1309.5 |
| strings | 17.4 | 16.2 | 22.6 | 1265.0 |

1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 yes, ours yes

## Speed (median wall seconds)

| workload | lua5.4 | ours | ours / lua5.4 |
|---|---:|---:|---:|
| closures | 0.050 | 0.723 | 14.4 |
| coroutines | 0.006 | 0.160 | 24.9 |
| fib | 0.007 | 0.230 | 31.4 |
| oop | 0.019 | 0.518 | 27.7 |
| small_tables | 0.067 | 0.348 | 5.2 |
| strings | 0.063 | 0.686 | 10.9 |
| table_insert_sum | 0.037 | 0.767 | 20.9 |
| all.lua -e"_U=true" | 0.253 | 8.320 | 32.9 |

## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e"_U=true")

| lua5.4 | ours |
|---:|---:|
| 128 MB | > 64 GB |
