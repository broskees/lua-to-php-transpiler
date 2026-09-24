# Benchmark: after-slim

- date: 2026-09-24 18:53
- commit: 79c750c (src/bin modified)
- PHP 8.5.9 (cli) (built: Aug 16 2026 19:35:50) (NTS)
- Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio
- speed: median of 5 runs

## Memory (MB)

| workload | lua5.4 peak RSS - startup | ours peak RSS - startup | lua5.4 VmPeak | ours VmPeak |
|---|---:|---:|---:|---:|
| startup (empty.lua, absolute) | 2.7 | 37.3 | 5.3 | 225.0 |
| closures | 11.1 | 17.4 | 16.4 | 243.5 |
| coroutines | 12.0 | 101.2 | 17.3 | 2942.6 |
| empty_tables | 8.1 | 19.4 | 13.4 | 249.5 |
| int_array | 16.0 | 16.5 | 21.3 | 251.0 |
| linked_list_drop | 122.1 | 491.0 | 127.4 | 716.0 |
| records | 27.1 | 52.9 | 32.3 | 277.5 |
| strings | 17.5 | 16.1 | 22.6 | 241.0 |

1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 yes, ours yes

## Speed (median wall seconds)

| workload | lua5.4 | ours | ours / lua5.4 |
|---|---:|---:|---:|
| closures | 0.050 | 0.751 | 15.1 |
| coroutines | 0.006 | 0.159 | 24.5 |
| fib | 0.007 | 0.228 | 31.4 |
| oop | 0.019 | 0.530 | 27.6 |
| small_tables | 0.069 | 0.401 | 5.8 |
| strings | 0.070 | 0.660 | 9.4 |
| table_insert_sum | 0.037 | 0.772 | 20.9 |
| all.lua -e"_U=true" | 0.255 | 8.758 | 34.3 |

## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e"_U=true")

| lua5.4 | ours |
|---:|---:|
| 128 MB | 1536 MB |
