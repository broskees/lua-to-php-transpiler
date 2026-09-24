-- 1e5 resume/yield round trips
local co = coroutine.wrap(function ()
  local n = 0
  while true do n = n + coroutine.yield(n) end
end)
co()
local last
for i = 1, 100000 do last = co(1) end
print(last)
