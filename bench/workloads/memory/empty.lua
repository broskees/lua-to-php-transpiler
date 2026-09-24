-- startup only: the other memory workloads are measured against this
print(io.open("/proc/self/status"):read("a"))
