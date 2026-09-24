# Benchmarks

    php bench/run.php <label> [runs]

runs every workload under `lua5.4` and under `php bin/lua` (with the ambient
php.ini), prints a markdown report and saves it as `bench/results/<label>.md`
(e.g. `baseline-2b7e6a4`, `after-teardown`). It takes a few minutes; run it on
an otherwise idle machine.

- **Memory** (`workloads/memory/*.lua`, one run each): each workload builds its
  data, keeps it alive and prints `/proc/self/status`. *peak RSS - startup* is
  VmHWM minus the VmHWM of `empty.lua` (the interpreter's own startup, shown
  as absolute values in the first row); *VmPeak* is the peak virtual memory
  (address space, what `ulimit -v` limits). `linked_list_drop.lua` frees a
  1M-node list and reports whether the process survived.
- **Speed** (`workloads/speed/*.lua` plus the official `all.lua -e"_U=true"`):
  median wall time of `runs` runs (default 5), process startup included, and
  the ratio ours / lua5.4.
- **Virtual-memory floor**: the smallest `ulimit -v`, in 128 MB steps, under
  which all.lua still prints `final OK !!!` (doubling, then bisecting).
