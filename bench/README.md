# Benchmarks

    php bench/run.php <label> [runs]

measures against `lua5.4`, prints a markdown report and saves it as
`bench/results/<label>.md` (e.g. `baseline-2b7e6a4`, `after-teardown`). It
takes a few minutes; run it on an otherwise idle machine.

- **Memory** (`workloads/memory/*.lua`, one run each, under `php bin/lua`
  with the ambient php.ini): each workload builds its data, keeps it alive
  and prints `/proc/self/status`. *peak RSS - startup* is VmHWM minus the
  VmHWM of `empty.lua` (the interpreter's own startup, shown as absolute
  values in the first row); *VmPeak* is the peak virtual memory (address
  space, what `ulimit -v` limits). `linked_list_drop.lua` frees a 1M-node
  list and reports whether the process survived.
- **Speed** (`workloads/speed/*.lua`): what a deployed program costs. Each
  workload is transpiled with `bin/lua2php in.lua -o out.php`, and the
  saved `out.php` runs as `php out.php` with a warm opcache: `-d
  opcache.enable_cli=1 -d opcache.file_update_protection=0 -d
  opcache.file_cache=<scratch directory>` (the file cache keeps compiled
  files across processes; without `file_update_protection=0` opcache skips
  a file written less than 2 seconds ago, and the JIT with it). *saved PHP*
  runs with the JIT as php.ini sets it, *JIT* adds `-d opcache.jit=tracing`
  (with its own file cache); the report's header shows php.ini's
  `opcache.jit` (lua2php output turns the JIT on itself, as mode 1054,
  tracing without register allocation, only when it is `off` with a
  buffer). Every number is the median wall time of `runs`
  runs (default 5) after one untimed run, process startup included, and
  the ratios are to `lua5.4`. *transpile ms* times `bin/lua2php` itself
  (same options, after one untimed run); it is never part of a run time.
- **Official test suite**: `all.lua -e"_U=true"` under `php bin/lua`, which
  transpiles every file while it runs (median of `runs` runs, with the
  ambient php.ini). lua2php output cannot run all.lua:
  `files.lua` `dofile()`s a file it writes at run time, which was never
  transpiled (see `tests/unit/VirtualMemoryTest.php`).
- **Transpiling**: `bin/lua2php` on the directory of official test files in
  one process, as wall time and per 1,000 lines of Lua.
- **Virtual-memory floor**: the smallest `ulimit -v`, in 128 MB steps, under
  which all.lua under `bin/lua` still prints `final OK !!!` (doubling, then
  bisecting).

The reports up to `final-b193de9.md` timed `php bin/lua` (transpile, eval
and run in one process) as their speed, with no saved PHP or JIT columns.
