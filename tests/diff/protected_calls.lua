-- pcall/xpcall nesting and results
print(pcall(function (...) return ... end, 1, 2, 3))
print(pcall(pcall, error, "x"))
print(pcall(pcall, pcall, error, "y"))
print(select('#', pcall(function () end)))
print(xpcall(function (a, b) return a + b end, print, 3, 4))
print(xpcall(function () error("E") end, function (m) return "handled " .. m end))
print(xpcall(function () error({code = 1}) end, function (m) return m.code end))
print(xpcall(function () local x = nil; return x.y end, function (m) return (m .. " [h]") end))
local function inner() error("inner error") end
local function middle() local ok, err = pcall(inner); error("middle saw: " .. err, 0) end
print(pcall(middle))
local depth = 0
local function nest(n) if n == 0 then error("bottom") end local ok, e = pcall(nest, n - 1); depth = depth + 1; error(e, 0) end
print(pcall(nest, 50), depth)
print(pcall(error))
print(pcall(pcall))
print(pcall(xpcall))
print(pcall(xpcall, print))
print(xpcall(error, function (m) return "got " .. tostring(m) end, "arg error"))
local results = {pcall(function () return 1, nil, 3, nil end)}
print(#results >= 0, results[1], results[2], results[3], results[4], results[5])
local co = 0
local ok, err = pcall(function ()
  local t = setmetatable({}, {__index = function (t, k) co = co + 1; error("index error " .. k) end})
  return t.foo
end)
print(ok, err, co)
print(pcall(function () return pcall(function () return 1 + nil end) end))
local handlerCalls = 0
print(xpcall(function () error("x") end, function (m) handlerCalls = handlerCalls + 1; return handlerCalls end), handlerCalls)
