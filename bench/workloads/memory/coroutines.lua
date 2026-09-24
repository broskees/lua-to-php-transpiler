-- 10k suspended coroutines, kept alive
local keep = {}
for i = 1, 10000 do
  keep[i] = coroutine.create(function () coroutine.yield(i) end)
  assert(coroutine.resume(keep[i]))
end
print(io.open("/proc/self/status"):read("a"))
