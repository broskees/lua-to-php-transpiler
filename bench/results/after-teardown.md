# Benchmark: after-teardown

- date: 2026-09-24 18:02
- commit: 2b7e6a4 (src/bin modified)
- PHP 8.5.9 (cli) (built: Aug 16 2026 19:35:50) (NTS)
- Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio
- speed: median of 5 runs

## Memory (MB)

| workload | lua5.4 peak RSS - startup | ours peak RSS - startup | lua5.4 VmPeak | ours VmPeak |
|---|---:|---:|---:|---:|
| startup (empty.lua, absolute) | 2.7 | 37.3 | 5.3 | 225.0 |
| closures | 11.2 | 40.5 | 16.4 | 266.0 |
| coroutines | 12.0 | 109.2 | 17.3 | 2950.6 |
| empty_tables | 8.1 | 24.5 | 13.4 | 255.5 |
| int_array | 16.1 | 16.5 | 21.3 | 251.0 |
| linked_list_drop | 122.1 | 555.0 | 127.4 | 782.0 |
| records | 27.1 | 59.2 | 32.3 | 285.5 |
| strings | 17.6 | 15.9 | 22.6 | 241.0 |

1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 yes, ours yes

## Speed (median wall seconds)

| workload | lua5.4 | ours | ours / lua5.4 |
|---|---:|---:|---:|
| closures | 0.050 | 0.787 | 15.7 |
| coroutines | 0.007 | 0.164 | 24.7 |
| fib | 0.008 | 0.227 | 29.6 |
| oop | 0.019 | 0.534 | 27.7 |
| small_tables | 0.069 | 0.414 | 6.0 |
| strings | 0.067 | 0.673 | 10.0 |
| table_insert_sum | 0.036 | 0.769 | 21.3 |
| all.lua -e"_U=true" | 0.256 | 8.635 | 33.8 |

## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e"_U=true")

| lua5.4 | ours |
|---:|---:|
| 128 MB | 1536 MB |
