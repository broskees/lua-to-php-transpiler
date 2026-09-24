-- debug.traceback: message forms, levels, names of C functions, tail calls,
-- long tracebacks, debug.setcstacklimit, debug.debug at end of input
print(debug.traceback(print) == print, debug.traceback(print, 4) == print)
local t = {}
print(debug.traceback(t) == t, debug.traceback(true), debug.traceback(false))
print(debug.traceback())
print(debug.traceback(nil))
print(debug.traceback("hi"))
print(debug.traceback("hi", 0))
print(debug.traceback("hi", 2))
print(debug.traceback("hi", 50))
print(debug.traceback(12))
print(debug.traceback(1.5, 1))
print(debug.traceback("with\0zero"))
print(pcall(debug.traceback, "x", "y"))

-- names: global, local, method, field, upvalue, C functions through package.loaded
local obj = {}
function obj:method () return debug.traceback("m") end
function obj.field () return obj:method() end
local function localf () return (obj.field()) end
function globalf () return (localf()) end
print(globalf())
globalf = nil
print(select(2, pcall(debug.traceback)))
print((function () return pcall end)()(debug.traceback, "via pcall"))
print(select(2, xpcall(function () error("boom") end, debug.traceback)))
print(select(2, xpcall(function () local x = nil; return x.y end, debug.traceback)))
print(string.gsub("a", "a", function () return debug.traceback("in gsub") end))
table.sort({3, 2, 1}, function (a, b)
  if a == 3 then print(debug.traceback("in sort", 1)) end
  return a < b
end)

-- tail calls
local function deep () return debug.traceback("tail") end
local function tail1 () return deep() end
local function tail2 () return tail1() end
print(tail2())

-- long tracebacks are cut in the middle
local function rec (n) if n == 0 then return debug.traceback("rec") end return (rec(n - 1)) end
print(rec(30))
print(rec(19))
print(rec(20))
print(rec(21))

-- metamethods and iterators
local mt = setmetatable({}, {__index = function () return debug.traceback("index") end,
                             __add = function () return debug.traceback("add") end})
print(mt.x)
print(mt + 1)
for v in function (_, c) if not c then return debug.traceback("iter") end end do print(v) end

print(debug.setcstacklimit(100), debug.setcstacklimit(1e5))
print(pcall(debug.setcstacklimit))

-- debug.debug with no more input: prompt on stderr, then return
print(debug.debug())
print("after debug")
