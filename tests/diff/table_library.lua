-- table.insert/remove/move/concat/pack/unpack (ltablib.c): results, metamethods and errors
local maxI, minI = math.maxinteger, math.mininteger

local function dump(t, first, last)
  local parts = {}
  for i = first or 1, last or #t do parts[#parts + 1] = tostring(t[i]) end
  return table.concat(parts, ",")
end

-- insert
local a = {}
table.insert(a, 10); table.insert(a, 1, 5); table.insert(a, 3, 20); table.insert(a, 2, 7)
print(dump(a), #a)
print(pcall(table.insert, a, 0, 1))
print(pcall(table.insert, a, 6, 1))
print(pcall(table.insert, a, -1, 1))
print(pcall(table.insert, a, 1, 2, 3))
print(pcall(table.insert, a))
print(pcall(table.insert, nil, 1))
print(pcall(table.insert, a, 1.5, 1))
print(pcall(table.insert, a, "x", 1))
table.insert(a, "2", "s")
print(dump(a), #a)
table.insert(a, 6.0, "f")
print(dump(a), #a)
table.insert(a, nil)
print(dump(a), #a)
local overflow = setmetatable({}, {__len = function () return maxI end})
table.insert(overflow, 20)
print(next(overflow))
print(pcall(table.insert, setmetatable({}, {__len = function () return "abc" end}), 1))
print(pcall(table.insert, setmetatable({}, {__len = function () return 2.0 end}), "x"))
print(pcall(table.insert, setmetatable({}, {__len = function () return 2.5 end}), "x"))

-- remove
local r = {10, 20, 30, 40}
print(table.remove(r), dump(r))
print(table.remove(r, 1), dump(r))
print(table.remove(r, #r + 1), dump(r))
print(pcall(table.remove, r, 0))
print(pcall(table.remove, r, 5))
print(pcall(table.remove, r, -1))
print(table.remove({}), table.remove({}, 0), table.remove({}, 1))
print(pcall(table.remove, {}, 2))
local zero = {[0] = "z"}
print(table.remove(zero), zero[0])
local neg = {[-1] = "n"}
print(table.remove(neg), neg[-1], #neg)
print(pcall(table.remove, {1, 2}, 1.5))

-- move
local function eq(t) return dump(t, -12, 14) end
print(eq(table.move({10, 20, 30}, 1, 3, 2)))
print(eq(table.move({10, 20, 30}, 1, 3, 3)))
print(eq(table.move({10, 20, 30}, 2, 3, 1)))
print(eq(table.move({10, 20, 30}, 1, 0, 3)))
local dest = {}
print(table.move({10, 20, 30}, 1, 3, 1, dest) == dest, eq(dest))
print(eq(table.move({10, 20, 30}, 1, 3, -2, {})))
local fringe = table.move({[maxI - 2] = 1, [maxI - 1] = 2, [maxI] = 3}, maxI - 2, maxI, -10, {})
print(fringe[-10], fringe[-9], fringe[-8])
local fringe2 = table.move({[minI] = 1, [minI + 1] = 2, [minI + 2] = 3}, minI, minI + 2, -10, {})
print(fringe2[-10], fringe2[-9], fringe2[-8])
local edge = table.move({45}, 1, 1, maxI)
print(edge[1], edge[maxI])
print(pcall(table.move, {}, 0, maxI, 1))
print(pcall(table.move, {}, -1, maxI - 1, 1))
print(pcall(table.move, {}, minI, -1, 1))
print(pcall(table.move, {}, 1, maxI, 2))
print(pcall(table.move, {}, 1, 2, maxI))
print(pcall(table.move, {}, minI, -2, 2))
print(pcall(table.move, 1, 2, 3, 4))
print(pcall(table.move, {}, 1, 2))
print(pcall(table.move, {}, 1, 2, 3, 4))
print(pcall(table.move, {}, 1.5, 2, 3))
local reads = {}
local source = setmetatable({}, {__index = function (_, k) reads[#reads + 1] = k; return k * 10 end})
local target = table.move(source, 1, 5, 3, {})
print(dump(reads), eq(target))
local writes = {}
local sink = setmetatable({""}, {__index = error, __newindex = function (t, k, v) rawset(writes, #writes + 1, k .. "=" .. v) end})
table.move(source, 10, 13, 3, sink)
print(dump(writes))
print(select("#", pcall(table.move, sink, 10, 13, 3, sink)), (select(2, pcall(table.move, sink, 10, 13, 3, sink))) == sink)
-- same table through __eq: copies backwards when ranges overlap
local eqmt = {__eq = function () return true end}
local ta, tb = setmetatable({1, 2, 3, 4}, eqmt), setmetatable({1, 2, 3, 4}, eqmt)
table.move(ta, 1, 3, 2, tb)
print(dump(tb))

-- concat
print(table.concat({}), table.concat({1, 2, 3}), table.concat({1, 2, 3}, ", "), table.concat({1, 2.5, "x"}, "-", 2))
print(table.concat({1, 2, 3}, ",", 2, 3), table.concat({1, 2, 3}, ",", 3, 2), table.concat({"a", "b"}, 3))
print(pcall(table.concat, {1, {}, 3}))
print(pcall(table.concat, {1, 2}, ",", 1, 3))
print(pcall(table.concat, {true}))
print(pcall(table.concat, {1, 2}, {}))
print(pcall(table.concat, {[maxI] = "x"}, "", maxI, maxI))
print(pcall(table.concat, nil))
local virtual = setmetatable({}, {__index = function (_, k) return k + 1 end, __len = function () return 5 end})
print(table.concat(virtual, ";"))
print(pcall(table.concat, setmetatable({}, {__len = function () return 2 end})))
print(pcall(table.concat, setmetatable({}, {__index = {}})))

-- pack / unpack
local p = table.pack()
print(p.n, p[1], #p)
p = table.pack(nil, nil, 3)
print(p.n, p[1], p[3], #p)
p = table.pack(1, 2, nil)
print(p.n, #p)
print(table.unpack({1, 2, 3}))
print(table.unpack({1, 2, 3}, 2))
print(table.unpack({1, 2, 3}, 2, 5))
print(table.unpack({1, 2, 3}, -1, 1))
print(table.unpack({1, 2, 3}, 3, 2))
print(select("#", table.unpack({}, maxI, minI)))
print(table.unpack({[maxI - 1] = 12, [maxI] = 23}, maxI - 1, maxI))
print(table.unpack({[minI] = 1.5, [minI + 1] = 2.5}, minI, minI + 1))
print(pcall(table.unpack, {}, 0, maxI))
print(pcall(table.unpack, {}, 1, (1 << 31) - 1))
print(pcall(table.unpack, {}, minI, maxI))
print(pcall(table.unpack, {}, 1, 1e7))
print(pcall(table.unpack))
print(pcall(table.unpack, nil, 1, 2))
print(pcall(table.unpack, 5))
print(table.unpack("abc", 1, 2))
print(table.unpack(setmetatable({}, {__index = function (_, k) return k * 2 end, __len = function () return 3 end})))
print(pcall(table.unpack, {}, "x"))
