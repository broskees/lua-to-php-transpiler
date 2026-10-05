-- Blocks that close captured locals and to-be-closed variables at once
-- (OP_CLOSE, OP_RETURN with k), functions whose only to-be-closed variable
-- is a generic for's closing value (OP_TFORPREP, no OP_TBC), and functions
-- with captured locals only
local log = {}
local function closer(name)
  return setmetatable({}, {__close = function (_, err) log[#log + 1] = name .. ":" .. tostring(err) end})
end
local function flush() print(table.concat(log, " ")); log = {} end

-- captured locals and a <close> variable in one block
local function sameBlock()
  local getters = {}
  do
    local x = 1
    local tbc <close> = closer("block")
    local y = 2
    getters[1] = function () return x end
    getters[2] = function () return y end
    x, y = 10, 20
  end
  log[#log + 1] = getters[1]() + getters[2]()
  return getters
end
local g = sameBlock()
print(g[1](), g[2]())
flush()

-- in a loop body each iteration closes its own; break closes both too
local function loopBody()
  local fs = {}
  for i = 1, 3 do
    local captured = i * 10
    local tbc <close> = closer("iteration" .. i)
    fs[i] = function () captured = captured + 1; return captured end
    if i == 2 then break end
  end
  return fs
end
local fs = loopBody()
print(fs[1](), fs[1](), fs[2](), fs[3])
flush()

-- the generic for's closing value is the function's only to-be-closed variable
local function iterate(n)
  local i = 0
  return function () i = i + 1; if i <= n then return i end end, nil, nil, closer("for" .. n)
end
local function genericFor()
  local captured = {}
  for v in iterate(2) do
    captured[v] = function () return v end
  end
  log[#log + 1] = captured[1]() + captured[2]()
  for v in iterate(3) do
    local w = v
    captured[v] = function () return w end
    if v == 2 then break end
  end
  return captured[2]()
end
print(genericFor())
flush()
print(pcall(function ()
  for v in iterate(2) do
    local u = v
    local f = function () return u end
    error("stop " .. f())
  end
end))
flush()

-- captured locals only
local function upvaluesOnly()
  local fs = {}
  for i = 1, 3 do
    local x = i
    fs[i] = function () return x end
    if i == 3 then break end
  end
  do
    local y = 5
    fs[4] = function () y = y + 1; return y end
  end
  local z = 7
  fs[5] = function () return z end
  return fs[1]() + fs[2]() + fs[3]() + fs[4]() + fs[4]() + fs[5]()
end
print(upvaluesOnly())

-- a line hook sees OP_CLOSE's line in every kind of function
local lines = {}
debug.sethook(function (_, line) lines[#lines + 1] = line end, "l")
sameBlock()
genericFor()
upvaluesOnly()
debug.sethook()
print(table.concat(lines, " "))
flush()
