-- next/pairs over every key kind, deleting and nesting during traversal
local t = {["10"] = "s", [10] = "i", [1.5] = "f", [true] = "b"}
local kinds = {}
for k, v in pairs(t) do kinds[#kinds + 1] = type(k) .. ":" .. tostring(k) .. "=" .. v end
print(#kinds)
local seen = {}
for k in pairs(t) do seen[tostring(k) .. type(k)] = true end
print(seen["10string"], seen["10number"], seen["1.5number"], seen["trueboolean"])
print(pcall(next, t, "nope"))
-- delete everything while iterating, including keys in different parts
local u = {1, 2, 3, x = 1, y = 2, [2.5] = 3, [false] = 4}
local n = 0
for k in pairs(u) do u[k] = nil; n = n + 1 end
print(n, next(u))
-- nested traversal of the same table
local w = {a = 1, b = 2, c = 3}
local pairsCount = 0
for k1 in pairs(w) do for k2 in pairs(w) do pairsCount = pairsCount + 1 end end
print(pairsCount)
-- modify values during traversal
local m = {1, 2, 3, a = 4}
for k, v in pairs(m) do m[k] = v * 10 end
print(m[1], m[2], m[3], m.a)
