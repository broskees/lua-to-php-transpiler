-- one array of 1M integers
local keep = {}
for i = 1, 1000000 do keep[i] = i end
print(io.open("/proc/self/status"):read("a"))
