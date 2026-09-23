-- an uncaught error 40 calls deep: the traceback skips the middle levels
local function recurse(n)
  if n == 0 then
    local t = setmetatable({}, {__index = function (t, k) error("deep error: " .. k) end})
    return t.missing
  end
  return (recurse(n - 1))
end
local co = {method = function (self, n) return recurse(n) end}
print(pcall(co.method, co, 3))
co:method(40)
