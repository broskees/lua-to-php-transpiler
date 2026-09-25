-- ipairs, next/pairs and the generic 'for' calling its iterator: the fast
-- paths and every case that takes the C port. Output does not depend on
-- traversal order.

local function try(f, ...) print(pcall(f, ...)) end
local function sorted(t) table.sort(t); return table.concat(t, " ") end

print("-- ipairs")
local list = {10, 20, 30, nil, 50}
local seen = {}
for i, v in ipairs(list) do seen[#seen + 1] = i .. "=" .. v end
print(table.concat(seen, " "))
local lazy = setmetatable({1}, {__index = function (_, i) if i <= 4 then return i * 100 end end})
seen = {}
for i, v in ipairs(lazy) do seen[#seen + 1] = i .. "=" .. v end
print(table.concat(seen, " "))
local inherited = setmetatable({"own", nil, "own3"}, {__index = {"base1", "base2", "base3", "base4"}})
seen = {}
for i, v in ipairs(inherited) do seen[#seen + 1] = i .. "=" .. v end
print(table.concat(seen, " "))
local count = 0
for _ in ipairs(setmetatable({}, {})) do count = count + 1 end
for _ in ipairs("abc") do count = count + 1 end
print("empty and string", count)
local f, state, control = ipairs({10, 20, [math.mininteger] = "min"})
print(f(state, 0))
print(f(state, 1.0))
print(f(state, "1"))
print(f(state, 2))
print(f(state, -1))
print(f(state, math.maxinteger))
print(f(state, 1 << 62))
try(f, state, 1.5)
try(f, state, nil)
try(f, state)
try(f, nil, 0)
try(f, 5, 0)
try(ipairs)
print(pcall(function () for _ in ipairs(nil) do end end))
print(pcall(function () for _ in ipairs(true) do end end))
local proxyCalls = 0
local proxy = setmetatable({}, {__index = function (_, i) proxyCalls = proxyCalls + 1; if i < 3 then return i end end})
for _ in ipairs(proxy) do end
print("proxy __index calls", proxyCalls)

print("-- next and pairs")
local mixed = {1, 2, 3, x = "a", y = "b", [2.5] = "f", [true] = "t", [10] = "ten", ["10"] = "string ten"}
mixed[mixed] = "self"
local keys, total = {}, 0
for k, v in pairs(mixed) do
  keys[#keys + 1] = math.type(k) or type(k) .. ":" .. (type(k) == "table" and "t" or tostring(k))
  total = total + 1
end
print(total, sorted(keys))
keys = {}
local k, v = next(mixed)
while k ~= nil do
  keys[#keys + 1] = type(k)
  k, v = next(mixed, k)
end
print(#keys, sorted(keys))
print(next({}), next({}, nil), rawequal(next({5}), 1))
print(select("#", next({})))
try(next, {}, "missing")
try(next, {1, 2}, 3)
try(next, {x = 1}, "y")
try(next, {1}, 1.0)
try(next, {[2.5] = 1}, 3.5)
try(next, nil)
try(next)
try(next, "abc")
try(next, setmetatable({}, {__pairs = function () end}), "x")
print(next(setmetatable({"raw"}, {__index = function () return "meta" end})))
-- clearing fields during a traversal, and a nested traversal of the same table
local big = {}
for i = 1, 50 do big[i] = i; big["k" .. i] = i end
local visited, sum = 0, 0
for key, value in pairs(big) do
  visited = visited + 1
  sum = sum + value
  big[key] = nil
end
print("cleared", visited, sum, next(big))
for i = 1, 50 do big[i] = i; big["k" .. i] = i end
visited, sum = 0, 0
for key, value in pairs(big) do                -- clearing every other field
  visited = visited + 1
  if value % 2 == 0 then big[key] = nil end
end
for _, value in pairs(big) do sum = sum + value end
print("cleared half", visited, sum)
for i = 1, 20 do big[i] = i; big["k" .. i] = i end
local pairsCount = 0
for _ in pairs(big) do
  for _ in pairs(big) do pairsCount = pairsCount + 1 end
end
print("nested", pairsCount)
-- restarting a traversal from a key given out earlier
local restart = {a = 1, b = 2, c = 3, 7, 8, 9}
local firstKey = next(restart)
local rest = 0
for _ in next, restart, firstKey do rest = rest + 1 end
local again = 0
for _ in next, restart, firstKey do again = again + 1 end
print("restart", rest, again)
local pairsMeta = setmetatable({}, {__pairs = function (t) return function (_, c) if c < 3 then return c + 1, "p" end end, t, 0 end})
seen = {}
for i, v in pairs(pairsMeta) do seen[#seen + 1] = i .. v end
print(table.concat(seen, " "))
try(pairs)
print(pcall(function () for _ in pairs(nil) do end end))

print("-- the generic for calling its iterator")
local function range(n)
  return function (limit, i) if i < limit then return i + 1 end end, n, 0
end
total = 0
for i in range(5) do total = total + i end
print("lua iterator", total)
local callable = setmetatable({}, {__call = function (self, limit, i) if i < limit then return i + 1 end end})
total = 0
for i in callable, 4, 0 do total = total + i end
print("__call iterator", total)
print(pcall(function () for _ in 42 do end end))
print(pcall(function () for _ in {} do end end))
print(pcall(function () for _ in function () error("iterator failed") end do end end))
print(select(2, pcall(function () for _ in function () error({code = 1}) end do end end)).code)
local function deep() for _ in deep do end end
local ok, message = pcall(deep)
print(ok, message)
local co = coroutine.wrap(function ()
  for i in function (_, c) c = c or 0; coroutine.yield("yield in iterator " .. c); if c < 2 then return c + 1 end end do
    coroutine.yield("body " .. i)
  end
  local meta = setmetatable({}, {__index = function (_, key) return coroutine.yield("yield in __index " .. key) end})
  coroutine.yield("got " .. meta.field)
  return "done"
end)
for _ = 1, 9 do
  local out = co("resumed")
  print(out)
  if out == "done" then break end
end
print(pcall(function ()
  for _ in function () return coroutine.yield() end do end
end))
