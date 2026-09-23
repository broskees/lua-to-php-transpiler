-- multiple assignment, evaluation order, adjustment
local a, b, c = 1, 2
print(a, b, c)
a, b = b, a
print(a, b)
local t = {}
local i = 1
i, t[i] = i + 1, 20
print(i, t[1], t[2])
local function three() return 1, 2, 3 end
local x, y, z, w = three()
print(x, y, z, w)
x, y, z = three(), 10
print(x, y, z)
x, y, z = (three())
print(x, y, z)
local q = {three(), three()}
print(#q)
local r = {three(), (three())}
print(#r)
local s = {(three())}
print(#s)
local g1, g2
g1, g2 = 1
print(g1, g2)
globalA, globalB = "ga", "gb"
print(globalA, globalB)
local u = {}
u.x, u.y = 1, 2
u[1], u[2] = u.y, u.x
print(u.x, u.y, u[1], u[2])
local m = setmetatable({}, {__newindex = function (t, k, v) rawset(t, k, v * 10) end})
m.a, m.b = 1, 2
print(m.a, m.b)
local n1, n2 = nil
print(n1, n2)
local v1 = 1; local v2 = v1 + 1; local v3 = v1 + v2
print(v1, v2, v3)
