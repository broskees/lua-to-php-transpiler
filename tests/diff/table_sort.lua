-- table.sort (ltablib.c auxsort/partition): results, comparison order, errors

local seed = 12345
local function nextrandom(n)  -- deterministic LCG, independent of math.random
  seed = (seed * 1103515245 + 12345) % 2147483648
  return seed % n
end

local function list(n, range)
  local t = {}
  for i = 1, n do t[i] = nextrandom(range) end
  return t
end

-- plain sorts of numbers and strings, several sizes
for _, n in ipairs{0, 1, 2, 3, 4, 5, 7, 10, 50, 99, 100, 101, 250} do
  local t = list(n, 1000)
  table.sort(t)
  local ok = true
  for i = 2, n do ok = ok and t[i - 1] <= t[i] end
  print(n, ok, t[1], t[n])
end
local words = {"Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"}
table.sort(words)
print(table.concat(words, " "))
table.sort(words, function (a, b) return a > b end)
print(table.concat(words, " "))
local mixed = {3, 1.5, -2, 2^53, -0.0, 7, math.mininteger, math.maxinteger, 1e308, -math.huge}
table.sort(mixed)
print(table.concat(mixed, " "))

-- unstable sort: the placement of equal keys must match the reference exactly
for _, n in ipairs{6, 12, 40, 150} do
  local records = {}
  for i = 1, n do records[i] = {key = nextrandom(4), id = i} end
  table.sort(records, function (a, b) return a.key < b.key end)
  local ids = {}
  for i = 1, n do ids[i] = records[i].key .. ":" .. records[i].id end
  print(table.concat(ids, " "))
end

-- the exact sequence of comparisons
local calls = {}
local t = {5, 1, 4, 2, 3, 9, 8, 6, 7, 0}
table.sort(t, function (a, b) calls[#calls + 1] = a .. "<" .. b; return a < b end)
print(#calls, table.concat(calls, " "))
print(table.concat(t, " "))

-- all equal: comparator always false
local falses = {}
for i = 1, 300 do falses[i] = false end
local count = 0
table.sort(falses, function () count = count + 1; return nil end)
print(count, falses[1], falses[300], #falses)

-- invalid order functions
local function always(a, b) assert(a and b); return true end
for _, n in ipairs{4, 5, 6, 20} do
  local u = {}
  for i = 1, n do u[i] = i end
  print(n, pcall(table.sort, u, always))
end
local function sortFromLua(u, f) table.sort(u, f) end
print(pcall(sortFromLua, {1, 2, 3, 4, 5}, always))

-- argument errors and strange lengths
print(pcall(table.sort))
print(pcall(table.sort, 1))
print(pcall(table.sort, {3, 1, 2}, 1))
print(pcall(table.sort, {3, 1, 2}, {}))
print(pcall(table.sort, {1}, 1))  -- one element: comparator not checked
print(pcall(table.sort, {3, "a", 2}))
print(pcall(table.sort, {3, nil, 2}))
print(pcall(table.sort, {{}, {}}))
print(pcall(table.sort, setmetatable({}, {__len = function () return math.maxinteger end})))
print(pcall(table.sort, setmetatable({}, {__len = function () return 2147483647 end})))
print(pcall(table.sort, setmetatable({}, {__len = function () return -1 end}), error))
print(pcall(table.sort, setmetatable({}, {__len = function () return "x" end})))
local sorted = {3, 2, 1}
table.sort(sorted, nil)
print(table.concat(sorted, " "))

-- errors raised by the comparator propagate
print(pcall(table.sort, {1, 2, 3}, function () error("cmp failed") end))
print(pcall(table.sort, {1, 2, 3}, function () error({}) end))

-- metamethods: __lt objects and proxies with __index/__newindex/__len
local mt = {__lt = function (a, b) return a.val < b.val end}
local objects = {}
for i = 1, 20 do objects[i] = setmetatable({val = nextrandom(100), id = i}, mt) end
table.sort(objects)
local vals = {}
for i = 1, 20 do vals[i] = objects[i].val .. "/" .. objects[i].id end
print(table.concat(vals, " "))

local backing = {}
for i = 1, 30 do backing[i] = nextrandom(50) end
local log = {}
local proxy = setmetatable({}, {
  __len = function () return #backing end,
  __index = function (_, k) log[#log + 1] = "r" .. k; return backing[k] end,
  __newindex = function (_, k, v) log[#log + 1] = "w" .. k; backing[k] = v end,
})
table.sort(proxy, function (a, b) return a > b end)
print(table.concat(backing, " "))
print(#log, table.concat(log, " ", 1, 40))

-- sorting a large random array, then sorted and reversed input (pivot randomization path)
local big = list(3000, 1000000)
table.sort(big)
local ok = true
for i = 2, #big do ok = ok and big[i - 1] <= big[i] end
print(ok)
table.sort(big, function (a, b) return a > b end)
ok = true
for i = 2, #big do ok = ok and big[i - 1] >= big[i] end
print(ok)
local distinct = {}
for i = 1, 2000 do distinct[i] = (i * 7919) % 2003 end
table.sort(distinct)
print(distinct[1], distinct[1000], distinct[2000])
