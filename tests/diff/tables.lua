-- tables: constructors, borders, keys, traversal
print(#{}, #{nil}, #{nil, nil}, #{1, 2, 3, nil, nil}, #{1, 2, 3}, #{nil, 2}, #{1, nil, 3}, #{n = 1})
local function values(...) return {...} end
print(#values(1, 2, 3), #values(nil, nil), #values(1, nil), #values())
local t = {}
for i = 1, 100 do t[#t + 1] = i end
print(#t, t[1], t[100], t[101])
for _ = 1, 30 do t[#t] = nil end
print(#t)
t[1.0] = "float one"
print(t[1], t[1.0], #t)
local keys = {}
keys[1] = "int"; keys["1"] = "string"; keys[1.5] = "float"; keys[true] = "bool"; keys[keys] = "self"
print(keys[1], keys["1"], keys[1.5], keys[true], keys[keys], keys[2 - 1.0], keys["01"])
keys["10"] = "ten string"; keys[10] = "ten int"
print(keys["10"], keys[10], keys[10.0])
print(2^53 == 2^53 + 1, ({[2^53] = "a"})[9007199254740992])
local count, sum = 0, 0
for k, v in pairs({10, 20, 30, x = 1, y = 2, [3.5] = 4}) do count = count + 1; sum = sum + v end
print(count, sum)
local arr = {"a", "b", "c", "d"}
local order = {}
for i, v in ipairs(arr) do order[#order + 1] = i .. v end
print(table.concat(order, ","))
-- clearing fields during traversal is allowed
local big = {}
for i = 1, 50 do big[i] = i; big["k" .. i] = i end
local visited = 0
for k in pairs(big) do big[k] = nil; visited = visited + 1 end
print(visited, next(big))
-- next
print(next({}), next({5}), rawequal(next, pairs({})))
local single = {x = 1}
local k, v = next(single)
print(k, v, next(single, k))
-- ipairs stops at the first nil and respects __index
local holes = {1, 2, nil, 4}
local n = 0
for _ in ipairs(holes) do n = n + 1 end
print(n)
local proxied = setmetatable({}, {__index = function (_, i) if i <= 3 then return i * 10 end end})
for i, x in ipairs(proxied) do print(i, x) end
-- __pairs
local custom = setmetatable({}, {__pairs = function (self) return function (_, i) if i < 3 then return i + 1, "p" .. i end end, self, 0 end})
for i, x in pairs(custom) do print(i, x) end
-- table library
local list = {1, 2, 3}
table.insert(list, 4); table.insert(list, 1, 0)
print(table.concat(list, " "), #list)
print(table.remove(list), table.remove(list, 1), table.concat(list, " "))
print(table.remove({}), table.remove({}, 1), #list)
print(table.concat({}), table.concat({1, 2.5, "x"}, "-"), table.concat({1, 2, 3}, ", ", 2, 3))
local moved = table.move({1, 2, 3, 4, 5}, 2, 4, 1)
print(table.concat(moved, ","))
print(table.concat(table.move({1, 2, 3}, 1, 3, 3), ","))
print(table.concat(table.move({1, 2, 3}, 1, 3, 1, {}), ","))
print(rawlen({1, 2}), rawlen("abc"), rawequal("a", "a"), rawequal({}, {}))
local big2 = {}
for i = 1, 1000 do big2[i] = i * 2 end
print(#big2, big2[500], select('#', table.unpack(big2)))
local constructed = {1, 2, 3, [10] = 10, x = "x", [2] = "overridden?", 4}
print(constructed[2], constructed[4], constructed[10], constructed.x, #constructed)
local nested = {{1, {2, {3}}}}
print(nested[1][2][2][1])
local items = {}
for i = 1, 300 do items[i] = i end
local long = {table.unpack(items)}
print(#long, long[300])
