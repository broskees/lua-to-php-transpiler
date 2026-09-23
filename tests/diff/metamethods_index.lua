-- __index / __newindex chains, rawget/rawset, __call, __eq, __lt, __le
local base = {x = 1, y = 2}
local middle = setmetatable({y = 20}, {__index = base})
local top = setmetatable({}, {__index = middle})
print(top.x, top.y, top.z, rawget(top, "x"))
local calls = 0
local lazy = setmetatable({}, {__index = function (t, k) calls = calls + 1; return k .. "!" end})
print(lazy.foo, lazy[1], lazy[2.5], calls)
local store = {}
local proxy = setmetatable({}, {__newindex = function (t, k, v) rawset(store, k, v * 2) end})
proxy.a = 1; proxy[1] = 5
print(rawget(proxy, "a"), store.a, store[1])
local chained = setmetatable({}, {__newindex = setmetatable({}, {__newindex = store})})
chained.q = 7
print(rawget(chained, "q"), store.q)
-- existing keys bypass __newindex
local existing = setmetatable({k = 1}, {__newindex = function () error("should not be called") end})
existing.k = 2
print(existing.k)
-- __index on non-tables through a metatable-less value is an error
print(pcall(function () local n = nil; return n.x end))
print(pcall(function () local s = 5; return s.x end))
print(pcall(function () return (true).x end))
print(pcall(function () local u; u.x = 1 end))
print(pcall(function () undefinedglobal.x = 1 end))
print(pcall(function () local t = {}; t.a.b.c = 1 end))
-- loops in __index chains
local loop = setmetatable({}, {})
getmetatable(loop).__index = loop
print(pcall(function () return loop.x end))
local nloop = setmetatable({}, {})
getmetatable(nloop).__newindex = nloop
print(pcall(function () nloop.x = 1 end))
-- __call
local callable = setmetatable({}, {__call = function (self, a, b) return "called", a, b end})
print(callable(1, 2))
local nested = setmetatable({}, {__call = callable})
print(nested(3))
print(pcall(function () local notcallable = {}; notcallable() end))
print(pcall(function () local t = {}; t.method() end))
print(pcall(function () local t = {}; t:method() end))
print(pcall(function () undefinedfunction() end))
-- __eq only between tables (or userdata), result converted to boolean
local eqmt = {__eq = function (a, b) return a.v == b.v end}
local e1, e2, e3 = setmetatable({v = 1}, eqmt), setmetatable({v = 1}, eqmt), setmetatable({v = 2}, eqmt)
print(e1 == e2, e1 == e3, e1 ~= e2, e1 == 1, rawequal(e1, e2))
local truthy = setmetatable({}, {__eq = function () return "yes" end})
print(truthy == {}, {} == truthy)
-- __lt / __le, and __le emulated with __lt (LUA_COMPAT_LT_LE)
local ordered = {__lt = function (a, b) return a.v < b.v end}
local o1, o2 = setmetatable({v = 1}, ordered), setmetatable({v = 2}, ordered)
print(o1 < o2, o2 < o1, o1 > o2, o1 <= o2, o2 <= o1, o1 >= o2)
local lemt = {__le = function (a, b) return "le" end, __lt = function () return nil end}
local l1, l2 = setmetatable({}, lemt), setmetatable({}, lemt)
print(l1 <= l2, l1 >= l2, l1 < l2)
print(pcall(function () return {} < 1 end))
-- __index with integer and float keys
local ints = setmetatable({}, {__index = function (t, k) return math.type(k) end})
print(ints[1], ints[1.0], ints[1.5], ints[2^53])
-- __tostring and __name
print(tostring(setmetatable({}, {__tostring = function () return "custom" end})))
print(tostring(setmetatable({}, {__name = "MyType"})), tostring(setmetatable({}, {__name = 42})))
print(pcall(tostring, setmetatable({}, {__tostring = function () return {} end})))
-- __metatable protects the metatable
local protected = setmetatable({}, {__metatable = "locked"})
print(getmetatable(protected), pcall(setmetatable, protected, {}))
print(getmetatable("abc").__index == string, getmetatable(1), getmetatable(nil))
