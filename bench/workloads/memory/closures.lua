-- 100k closures, each capturing its own loop variable, kept alive
local keep = {}
for i = 1, 100000 do keep[i] = function () return i end end
print(io.open("/proc/self/status"):read("a"))
