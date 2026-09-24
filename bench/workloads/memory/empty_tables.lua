-- 100k empty tables, kept alive
local keep = {}
for i = 1, 100000 do keep[i] = {} end
print(io.open("/proc/self/status"):read("a"))
