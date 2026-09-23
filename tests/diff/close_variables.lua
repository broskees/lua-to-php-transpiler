-- to-be-closed variables: normal exit, break, return, goto, errors
local log = {}
local function closer(name)
  return setmetatable({}, {__close = function (self, err) log[#log + 1] = name .. ":" .. tostring(err) end})
end
local function flush() print(table.concat(log, " ")); log = {} end
do
  local a <close> = closer("a")
  local b <close> = closer("b")
  local c <close> = nil
  local d <close> = false
end
flush()
for i = 1, 3 do
  local x <close> = closer("loop" .. i)
  if i == 2 then break end
end
flush()
local function returns()
  local r <close> = closer("r")
  return "returned"
end
print(returns())
flush()
do
  local g <close> = closer("g")
  goto out
end
::out::
flush()
print(pcall(function ()
  local e1 <close> = closer("e1")
  local e2 <close> = closer("e2")
  error("boom")
end))
flush()
print(pcall(function ()
  local bad <close> = setmetatable({}, {__close = function () error("in close") end})
  local good <close> = closer("good")
  error("original")
end))
flush()
print(pcall(function ()
  local first <close> = closer("first")
  local second <close> = setmetatable({}, {__close = function () error("second fails") end})
  return "normal"
end))
flush()
print(pcall(function () local x <close> = {} end))
print(pcall(function () local y <close> = 42 end))
local function generic()
  local state = 0
  return function () state = state + 1; if state <= 2 then return state end end, nil, nil, closer("iterator")
end
for v in generic() do print("iter", v) end
flush()
for v in generic() do if v == 1 then break end end
flush()
local ok = pcall(function () for v in generic() do error("in loop") end end)
print(ok)
flush()
do
  local const <const> = 10
  print(const * 2)
end
local function closesWithUpvalue()
  local captured = "captured"
  local tbc <close> = setmetatable({}, {__close = function () log[#log + 1] = captured end})
  return function () return captured end
end
print(closesWithUpvalue()())
flush()
