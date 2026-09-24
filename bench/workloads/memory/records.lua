-- 100k records {x=, y=, name=}, kept alive
local keep = {}
for i = 1, 100000 do keep[i] = {x = i, y = i * 2, name = "n" .. i} end
print(io.open("/proc/self/status"):read("a"))
