-- Calls to native functions (the dispatch pushes a CallInfo for each, so
-- hooks, tracebacks and error positions see them) and the metatable fast
-- paths: setmetatable, and method lookup through an '__index' table.

local function try(f, ...) print(pcall(f, ...)) end

print("-- call and return hooks count native calls")
local counts = {}
local function hook(event)
  local info = debug.getinfo(2, "nS")
  local key = event .. " " .. info.what .. " " .. tostring(info.name)
  counts[key] = (counts[key] or 0) + 1
end
local Class = {}
Class.__index = Class
function Class.new(v) return setmetatable({v = v}, Class) end   -- a native in a tail call
function Class:get() return self.v end
local function tailSelect(...) return select("#", ...) end
local t = {}
debug.sethook(hook, "cr")
for i = 1, 3 do table.insert(t, i) end
for _, v in ipairs(t) do local _ = v end
for _ in pairs({a = 1, b = 2}) do end
local obj = Class.new(5)
local _ = obj:get() + tailSelect(1, 2) + ("abc"):byte(1) + #("abc"):sub(1, 2)
local _ = tostring(12) .. string.format("%d%s", 1, "x") .. table.concat(t) .. tonumber("7")
local _ = table.remove(t), table.unpack(t)
local _ = setmetatable({}, {__add = function () return 1 end}) + 1
debug.sethook()
local lines = {}
for key, n in pairs(counts) do lines[#lines + 1] = key .. " x" .. n end
table.sort(lines)
print(table.concat(lines, "\n"))

print("-- natives in tracebacks and error positions")
print((select(2, pcall(string.rep))))
print((select(2, pcall(table.insert, 1, 2))))
print(pcall(function () local s = "x"; return s:rep(1, 2, 3) .. s:rep() end))
print(debug.traceback("tb", 1):gsub("\n[^\n]*%[C%]: in %?", ""):match("^[^\n]*\n[^\n]*"))
print(select(2, xpcall(string.rep, debug.traceback)):match("^[^\n]*\n[^\n]*\n[^\n]*"))
local depth = 0
local function recurse() depth = depth + 1; return pcall(recurse) end
recurse()
print("native recursion stops", depth > 150)
print(pcall(setmetatable({}, {__call = function (self, a, b) return a + b end}), 1, 2))
print(pcall(setmetatable({}, {__call = print}), "called through __call"))
print(pcall(setmetatable({}, {__call = setmetatable({}, {__call = function (...) return select("#", ...) end})}), "x"))
try(nil)
print(pcall(function () local undefinedFunction; undefinedFunction() end))
print(coroutine.wrap(function (...) return select("#", ...), ... end)(1, 2))
print(select(2, coroutine.resume(coroutine.create(string.rep), "ab", 2)))
-- a Lua function called from a native cannot yield (lua_call has no continuation)
local yielding = setmetatable({}, {__tostring = function () coroutine.yield("yielded") return "ok" end})
local function tailToString(o) return tostring(o) end
print(coroutine.resume(coroutine.create(function () local s = tostring(yielding); return s end)))
print(coroutine.resume(coroutine.create(function () return tailToString(yielding) end)))
print(coroutine.resume(coroutine.create(function () local s = table.concat(setmetatable({}, {
  __len = function () return 1 end, __index = function () return coroutine.yield("in __index") end})); return s end)))
print(coroutine.resume(coroutine.create(function () return pcall(coroutine.yield, "through pcall") end)))

print("-- setmetatable")
local mt = {}
local target = {}
print(setmetatable(target, mt) == target, getmetatable(target) == mt)
print(setmetatable(target, nil) == target, getmetatable(target))
setmetatable(target, mt)
local mt2 = {}
print(setmetatable(target, mt2) == target, getmetatable(target) == mt2)
print(setmetatable({}, mt, "extra") ~= nil)
local protected = setmetatable({}, {__metatable = "locked"})
try(setmetatable, protected, {})
try(setmetatable, protected, nil)
print(getmetatable(protected))
try(setmetatable, 1, {})
try(setmetatable, nil, {})
try(setmetatable)
try(setmetatable, {}, 1)
try(setmetatable, {}, "mt")
try(setmetatable, {})
print(pcall(setmetatable, {}, nil))
-- '__gc' present when the metatable is set marks the table for finalization
local finalizerMeta = {__gc = function (o) print("finalized", o.name) end}
setmetatable({name = "fast path"}, finalizerMeta)
local lateMeta = {}
setmetatable({name = "gc added later"}, lateMeta)
lateMeta.__gc = function (o) print("finalized", o.name) end
collectgarbage()
collectgarbage()
local weak = setmetatable({}, {__mode = "k"})
weak[{}] = true
collectgarbage()
print("weak entries", next(weak))

print("-- method lookup through __index")
local Base = {}
Base.__index = Base
function Base.hello() return "base hello" end
Base.flag = false
Base["10"] = "string ten"
local Derived = setmetatable({}, Base)
Derived.__index = Derived
function Derived.own() return "derived own" end
local instance = setmetatable({}, Derived)
print(instance.own(), instance.hello(), instance.flag, instance.missing)
local direct = setmetatable({}, Base)
print(direct.hello(), direct.flag, direct["10"], direct[10], direct[1], direct[true])
local byFunction = setmetatable({}, {__index = function (_, key) return "computed " .. tostring(key) end})
print(byFunction.x, byFunction[1])
local noIndex = setmetatable({}, {})
print(noIndex.x)
print(("abc"):upper(), ("abc"):len(), ("x"):rep(3), #("abc"))
print(pcall(function () return ("x"):nosuch() end))
print(pcall(function () local a; return a.field end))
print(pcall(function () return (5).field end))
debug.setmetatable(0, {__index = {double = function (n) return n * 2 end}})
print((21):double(), (1.5).double(3))
debug.setmetatable(0, nil)
local stringIndex = getmetatable("").__index
getmetatable("").__index = function (s, key) return "dynamic " .. key end
print(("s").anything, ("s").upper)
getmetatable("").__index = stringIndex
print(("s"):upper())
io.stdout:write("userdata method\n")
