# Benchmark: final-b193de9

- date: 2026-09-24 20:33
- commit: b193de9
- PHP 8.5.9 (cli) (built: Aug 16 2026 19:35:50) (NTS)
- Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio
- speed: median of 5 runs

## Memory (MB)

| workload | lua5.4 peak RSS - startup | ours peak RSS - startup | lua5.4 VmPeak | ours VmPeak |
|---|---:|---:|---:|---:|
| startup (empty.lua, absolute) | 2.7 | 37.1 | 5.3 | 225.0 |
| closures | 11.2 | 17.5 | 16.4 | 243.5 |
| coroutines | 12.0 | 101.4 | 17.3 | 2942.6 |
| empty_tables | 8.1 | 19.4 | 13.4 | 249.5 |
| int_array | 16.0 | 16.6 | 21.3 | 251.0 |
| linked_list_drop | 122.0 | 491.0 | 127.4 | 716.0 |
| records | 27.1 | 53.1 | 32.3 | 277.5 |
| strings | 17.6 | 16.4 | 22.6 | 241.0 |

1M-node linked list dropped (linked_list_drop.lua) survives: lua5.4 yes, ours yes

## Speed (median wall seconds)

| workload | lua5.4 | ours | ours / lua5.4 |
|---|---:|---:|---:|
| closures | 0.049 | 0.731 | 14.8 |
| coroutines | 0.007 | 0.155 | 22.5 |
| fib | 0.008 | 0.224 | 29.6 |
| oop | 0.019 | 0.513 | 26.4 |
| small_tables | 0.069 | 0.393 | 5.7 |
| strings | 0.065 | 0.653 | 10.0 |
| table_insert_sum | 0.035 | 0.750 | 21.4 |
| all.lua -e"_U=true" | 0.242 | 8.539 | 35.2 |

## Virtual-memory floor (smallest `ulimit -v`, in 128 MB steps, for all.lua -e"_U=true")

| lua5.4 | ours |
|---:|---:|
| 128 MB | 1536 MB |
