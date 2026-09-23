-- deep recursion ends in a catchable "stack overflow"; C-level recursion in "C stack overflow"
local depth = 0
local function recurse() depth = depth + 1; recurse() end
local ok, message = pcall(recurse)
print(ok, message)
print(depth > 1000)
-- the stack is usable again afterwards, repeatedly
for _ = 1, 3 do
  depth = 0
  local again, againMessage = pcall(recurse)
  print(again, againMessage, depth > 1000)
end
local function nonTail(n) if n == 0 then return 0 end return 1 + nonTail(n - 1) end
print(nonTail(10000))
-- recursion through metamethods counts as C calls
local t = setmetatable({}, {})
getmetatable(t).__index = function (tab, k) return tab[k] end
print(pcall(function () return t.x end))
-- xpcall with a handler that itself overflows
local function loop() return 1 + loop() end
print(xpcall(loop, loop))
print(xpcall(loop, function (m) return "handled: " .. m end))
-- error in error handling
print(xpcall(error, error))
print(select('#', xpcall(function () error("x") end, function () error("y") end)))
