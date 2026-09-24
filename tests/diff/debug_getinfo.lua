-- debug.getinfo: options, levels, function names, active lines, errors
local function show (t)
  local keys = {}
  for k in pairs(t) do keys[#keys + 1] = k end
  table.sort(keys)
  local parts = {}
  for _, k in ipairs(keys) do
    local v = t[k]
    if type(v) == "function" then v = (v == print) and "print" or "function"
    elseif type(v) == "table" then
      local lines = {}
      for line in pairs(v) do lines[#lines + 1] = line end
      table.sort(lines)
      v = "{" .. table.concat(lines, ",") .. "}"
    end
    parts[#parts + 1] = k .. "=" .. tostring(v)
  end
  return table.concat(parts, " ")
end

-- a Lua function, a C function, the main chunk
local function sample (a, b, ...)
  local x = a
  return x
end
print(show(debug.getinfo(sample)))
print(show(debug.getinfo(sample, "SlutnrfL")))
print(show(debug.getinfo(print)))
print(show(debug.getinfo(print, "L")))
print(show(debug.getinfo(1, "Slnturf")))
print(show(debug.getinfo(string.gmatch("x", "x"), "u")))
print(show(debug.getinfo(function (...) end, "Lu")))
print(show(debug.getinfo(function () end, "L")))

-- levels
print(debug.getinfo(1000), debug.getinfo(-1), debug.getinfo(0, "n").name)
print(debug.getinfo(1, "l").currentline, debug.getinfo(0, "S").what)
local function level2 () return debug.getinfo(2, "l").currentline end
print(level2())

-- invalid options and arguments
print(pcall(debug.getinfo, print, "X"))
print(pcall(debug.getinfo, 0, ">"))
print(pcall(debug.getinfo, 1, "S>"))
print(pcall(debug.getinfo, "x"))
print(pcall(debug.getinfo))
print(pcall(debug.getinfo, 1, {}))
print(show(debug.getinfo(1, "S\0X")))

-- names of called functions, from the calling code
local t = {}
function t.field () return debug.getinfo(1, "n") end
function t:method () return debug.getinfo(1, "n") end
local function localf () return debug.getinfo(1, "n") end
function globalf () return debug.getinfo(1, "n") end
local up = localf
local function viaUpvalue () return (up()) end
print(show(t.field()), show(t:method()), show(localf()), show(globalf()), show(viaUpvalue()))
print(show((function () return debug.getinfo(1, "n") end)()))
t[1] = localf
print(show(t[1]()))
local key = "field"
print(show(t[key]()))
for _ in function () print(show(debug.getinfo(1, "n"))) end do end
local mt = {}
for _, event in ipairs{"__index", "__newindex", "__add", "__sub", "__mul", "__div", "__mod",
                       "__pow", "__unm", "__idiv", "__band", "__bor", "__bxor", "__shl",
                       "__shr", "__bnot", "__concat", "__len", "__eq", "__lt", "__le",
                       "__call", "__close"} do
  mt[event] = function (...)
    local info = debug.getinfo(1, "n")
    print(event, info.namewhat, info.name)
    return 1
  end
end
local o = setmetatable({}, mt)
local p = setmetatable({}, mt)
local _ = o.x; o.y = 1
_ = o + 1; _ = o - 1; _ = o * 1; _ = o / 1; _ = o % 1; _ = o ^ 1; _ = -o; _ = o // 1
_ = o & 1; _ = o | 1; _ = o ~ 1; _ = o << 1; _ = o >> 1; _ = ~o; _ = o .. "x"; _ = #o
_ = o == p; _ = o < p; _ = o <= p; _ = o > 1; _ = o >= 1; _ = 1 + o; _ = o + 3.5
_ = o(1)
do local c <close> = o end
print(show(debug.getinfo(sample, "n")))
globalf = nil

-- tail calls
local function callee () return debug.getinfo(1, "nSt"), debug.getinfo(2, "nSt") end
local function tailer () return callee() end
local a, b = tailer()
print(show(a), "|", show(b))

-- source names
local function sourceOf (chunkname)
  local f = load("return debug.getinfo(1, 'S')", chunkname)
  return show(f())
end
print(sourceOf("=custom"), sourceOf("@file.lua"), sourceOf("chunk text"), sourceOf(nil))
print(sourceOf("=" .. string.rep("x", 100)))
print(sourceOf("@" .. string.rep("y", 100)))
print(sourceOf("line one\nline two"))
print(sourceOf(string.rep("z", 100)))

-- stripped code: no line information, no names
local stripped = load(string.dump(function (x)
  local y = x
  return debug.getinfo(1, "Sl"), debug.getinfo(1, "L")
end, true))
local s, l = stripped(1)
print(show(s), show(l))
print(show(debug.getinfo(stripped, "S")))

-- active lines of vararg and fixed-parameter functions
print(show(debug.getinfo(load("\n\nlocal a = 1\n\nreturn a\n"), "L")))
print(show(debug.getinfo(function (a, b)
  local c = a

  return c
end, "L")))
