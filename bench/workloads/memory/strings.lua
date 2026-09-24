-- 100k distinct 106-byte strings, kept alive
local keep = {}
local prefix = string.rep("x", 100)
for i = 1, 100000 do keep[i] = prefix .. string.format("%06d", i) end
print(io.open("/proc/self/status"):read("a"))
