-- next()/pairs() edge cases: the traversal keeps its place per table part
-- (integer, string and other keys) even when fields are cleared, when a
-- traversal is abandoned and restarted, and when traversals nest.
-- Output does not depend on traversal order.

local function sortedKeys(t)
  local keys = {}
  for k in pairs(t) do keys[#keys + 1] = tostring(k) end
  table.sort(keys)
  return table.concat(keys, " ")
end

local function build()
  local t = {10, 20, 30, x = 1, y = 2, z = 3, [2.5] = "f", [true] = "b"}
  t[{}] = "table key"
  return t
end

-- clearing every field while traversing: each key is seen exactly once
local t = build()
local seen, count = {}, 0
for k in pairs(t) do
  assert(not seen[k], "key seen twice")
  seen[k] = true
  count = count + 1
  t[k] = nil
end
print("cleared while traversing", count, next(t))

-- clearing only the current field of one part at a time
for _, kind in ipairs({"number", "string", "boolean", "table"}) do
  local t = build()
  local n = 0
  for k in pairs(t) do
    n = n + 1
    if type(k) == kind then t[k] = nil end
  end
  print("clearing " .. kind .. " keys", n, sortedKeys(t):gsub("table: 0x%x+", "table"))
end

-- an abandoned traversal does not disturb the next one
local t = build()
local first = next(t)
assert(first ~= nil)
for k, v in pairs(t) do if k == "y" then break end end
print("after abandoned traversals", sortedKeys(t):gsub("table: 0x%x+", "table"))
local n = 0
for k in pairs(t) do n = n + 1 end
print("full traversal", n)

-- nested traversals of the same table
local t = {a = 1, b = 2, c = 3, 4, 5}
local pairsSeen = 0
for k1 in pairs(t) do
  for k2 in pairs(t) do pairsSeen = pairsSeen + 1 end
end
print("nested", pairsSeen)

-- next() from any key, not only the last one returned
local t = {a = 1, b = 2, c = 3}
local order = {}
for k in pairs(t) do order[#order + 1] = k end
print("restart from second key", next(t, order[2]) == order[3], next(t, order[3]))

-- keys that are not in the table
print(pcall(next, {x = 1}, "y"))
print(pcall(next, {10, 20}, 3))
print(pcall(next, {10, 20}, 1.0))
print(pcall(next, {x = 1}, 1.5))
print(pcall(next, {}, true))
print(next({}), next({}, nil))

-- an emptiness check on many tables, then full traversals
local sets = {}
for i = 1, 100 do sets[i] = {["k" .. i] = true, [i] = i} end
local nonEmpty = 0
for i = 1, 100 do if next(sets[i]) ~= nil then nonEmpty = nonEmpty + 1 end end
local total = 0
for i = 1, 100 do for k in pairs(sets[i]) do total = total + 1 end end
print("sets", nonEmpty, total)
