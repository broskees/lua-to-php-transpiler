-- constructors of constants: emitted as runs (FunctionEmitter, TableConstructor)
-- that must leave the same tables, registers and hook events as their instructions

local function describe(value)
  if type(value) == "table" then
    local keys = {}
    for k in pairs(value) do keys[#keys + 1] = k end
    table.sort(keys, function(a, b)
      if type(a) == type(b) and (type(a) == "number" or type(a) == "string") then return a < b end
      return type(a) < type(b)
    end)
    local parts = {}
    for _, k in ipairs(keys) do parts[#parts + 1] = tostring(k) .. "=" .. describe(value[k]) end
    return "{#" .. #value .. " " .. table.concat(parts, ",") .. "}"
  end
  return math.type(value) == "float" and string.format("%.17g(f)", value) or tostring(value)
end

-- keys in traversal order, to compare two builds of the same constructor
local function inOrder(t)
  local parts = {}
  for k, v in pairs(t) do
    parts[#parts + 1] = tostring(k) .. "=" .. (type(v) == "table" and inOrder(v) or tostring(v))
  end
  return "{" .. table.concat(parts, ",") .. "}"
end

-- every kind of constant, nested tables, nil items, repeated keys, an item
-- and a field for the same index (the item wins: SETLIST comes last)
local function mixed()
  return {1, 2.5, "three", true, false, nil, -7, 1e300, -0.0, 9223372036854775807,
    x = 1, y = "why", ["with space"] = 2, [10] = 10, ["10"] = "ten", [3.0] = "three float",
    [-1] = -1, [0] = 0, [9223372036854775807] = "max", [-9223372036854775807 - 1] = "min",
    nested = {a = {b = {c = "deep"}}}, list = {10, 20, {30, 40}}, empty = {},
    same = 1, same = 2, gone = 1, gone = nil, [1.5] = "float key", [true] = "true key"}
end
print(describe(mixed()))
print(describe({[1] = "field", "item"}), describe({x = 1, x = nil, 5, nil, nil}))

-- big constructors: array sizes and SETLIST counts in OP_EXTRAARG
local items, fields, rows = {}, {}, {}
for i = 1, 600 do items[#items + 1] = tostring(i * 3) end
for i = 1, 300 do fields[#fields + 1] = "k" .. i .. " = " .. i end
for i = 1, 2000 do rows[#rows + 1] = string.format('{id = %d, name = "n%d", ok = %s}', i, i, tostring(i % 2 == 0)) end
local big = load("return {" .. table.concat(items, ", ") .. "}")()
local wide = load("return {" .. table.concat(fields, ", ") .. "}")()
local data = load("return {" .. table.concat(rows, ",\n") .. "}")()
print(#big, big[1], big[256], big[600], wide.k1, wide.k300, #data, data[1].name, data[2000].id, data[2000].ok)

-- the same constructor built without a hook (at once) and stepping under a
-- count hook that never fires: same contents in the same order
local function sameBothWays(build)
  local atOnce = inOrder(build())
  debug.sethook(function() end, "", 1000000000)
  local stepped = inOrder(build())
  debug.sethook()
  return atOnce == stepped
end
print(sameBothWays(mixed), sameBothWays(function() return {x = 1, x = nil, y = 2, 5, {6, z = 7}} end),
  sameBothWays(load("return {" .. table.concat(rows, ",") .. "}")))

-- the registers a constructor leaves: its temporaries, seen by a return hook
-- (all registers of the returning function), without and with stepping
local function registersAtReturn(f, count)
  local seen
  debug.sethook(function()
    if seen == nil and debug.getinfo(2, "f").func == f then
      seen = {}
      for i = 1, 250 do
        local name, value = debug.getlocal(2, i)
        -- (above the registers, lua5.4's debug library keeps its hook table)
        if name == nil or (type(value) == "table" and rawget(value, "__mode")) then break end
        seen[#seen + 1] = name .. "=" .. describe(value)
      end
    end
  end, "r", count)
  f()
  debug.sethook()
  return table.concat(seen, " ")
end
local function small() local t = {10, 20, {30}, x = "s", y = nil} end
local function lastBatch() local t = {1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
  21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48,
  49, 50, 51, 52, {53}, "54"} end
local function afterwards() local t = {a = 1}; local u = {2, 3}; local s, n = "x", nil end
local huge = load("return function() local t = {" .. table.concat(rows, ",") .. "} end")()
for _, f in ipairs({small, lastBatch, afterwards, huge}) do
  local atOnce, stepped = registersAtReturn(f), registersAtReturn(f, 1000000000)
  print(atOnce == stepped, #atOnce, atOnce:sub(1, 300))
end

-- line and count hooks inside a constructor
local function lines()
  local t = {
    1,
    x = 2,
    {3,
     4},
    y = {z = {
      5}},
  }
  return t
end
local events = {}
debug.sethook(function(event, line) events[#events + 1] = line end, "l")
lines()
debug.sethook()
print("lines", table.concat(events, " "))
local counted = 0
debug.sethook(function() counted = counted + 1 end, "", 1)
lines()
debug.sethook()
print("instructions", counted)
counted = 0
debug.sethook(function() counted = counted + 1 end, "", 7)
data = load("return {" .. table.concat(rows, ",") .. "}")()
debug.sethook()
print("count hook calls", counted, #data)

-- a hook that changes the table being built (it steps from there on as
-- its instructions do: here into __newindex)
local log = {}
local function built()
  local t = {
    a = 1,
    b = 2,
    c = 3,
  }
  return t
end
local lineOfB = debug.getinfo(built, "S").linedefined + 3
debug.sethook(function(event, line)
  if line == lineOfB then
    local _, t = debug.getlocal(2, 1)
    setmetatable(t, {__newindex = function(t, k, v) log[#log + 1] = k .. "=" .. v; rawset(t, k, v * 10) end})
  end
end, "l")
local ok, result = pcall(built)
debug.sethook()
print(ok, describe(result), table.concat(log, " "))

-- long strings keep their identity (one object per chunk, as in the Proto)
local long = "a long string constant, well over forty bytes long, to be one object"
local function strings()
  return {"a long string constant, well over forty bytes long, to be one object",
    k = "a long string constant, well over forty bytes long, to be one object", n = 1}
end
local s = strings()
print(string.format("%p", s[1]) == string.format("%p", s.k), string.format("%p", s[1]) == string.format("%p", long))

-- errors inside a constructor
print(pcall(load("local t = {1, 2, [nil] = 3}")))
