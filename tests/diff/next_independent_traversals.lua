-- Every traversal continues from its own key (ltable.c luaH_next: the key's
-- slot, dead keys included), whatever other next() calls ran in between, and
-- also after its key was deleted (clearing existing fields is legal during a
-- traversal). Output does not depend on traversal order.

local function newObjectKey(name)
  return setmetatable({}, {__tostring = function () return name end})
end

-- each part runs protected, so one failure does not hide the others
local function section(f)
  local ok, message = pcall(f)
  if not ok then print("error", message) end
end

local function sortedKeyTypes(t)
  local types = {}
  for k in pairs(t) do types[#types + 1] = type(k) end
  table.sort(types)
  return table.concat(types, " ")
end

local function build()
  local t = {10, 20, 30, 40, x = 1, y = 2, z = 3, w = 4, [2.5] = "f", [true] = "b", [false] = "c"}
  t[newObjectKey("obj1")] = 1
  t[newObjectKey("obj2")] = 2
  return t
end

-- the reported case: another next(t) between deleting the key and continuing from it
section(function ()
  local t = {10, 20, 30}
  local k = next(t)
  t[k] = nil
  next(t)
  print("reported", next(t, k))
end)

-- a traversal that deletes each key it gets, with an emptiness check before each step
section(function ()
  local t = build()
  local seen, count = {}, 0
  local k = next(t)
  while k ~= nil do
    assert(not seen[k], "key seen twice")
    seen[k] = true
    count = count + 1
    t[k] = nil
    local first = next(t)
    assert(first == nil or not seen[first])
    k = next(t, k)
  end
  print("delete, check, continue", count, next(t))
end)

-- nested pairs: the inner loop deletes the outer loop's current key (and others)
section(function ()
  local t = build()
  local outer = {}
  for k in pairs(t) do
    outer[#outer + 1] = tostring(k)
    for k2 in pairs(t) do
      if k2 == k then t[k2] = nil end
    end
  end
  table.sort(outer)
  print("nested, inner deletes outer key", #outer, table.concat(outer, " "), next(t))
end)

-- nested pairs: the inner loop deletes a key the outer loop has not reached yet
section(function ()
  local t = {}
  for i = 1, 20 do t["k" .. i] = i end
  for i = 1, 20 do t[i] = i end
  t[newObjectKey("obj")] = 0
  local visited, count, deletedAhead = {}, 0, 0
  for k in pairs(t) do
    assert(not visited[k], "outer key twice")
    visited[k] = true
    count = count + 1
    for k2 in pairs(t) do
      if not visited[k2] then t[k2] = nil; deletedAhead = deletedAhead + 1; break end
    end
  end
  print("nested, inner deletes ahead", count + deletedAhead, count >= 20)
end)

-- two traversals stepped in turns, each deleting the key it just got
section(function ()
  local t = build()
  local a, b = next(t), nil
  local seenA, seenB, countA, countB = {}, {}, 0, 0
  while a ~= nil or b ~= false do
    if a ~= nil then
      assert(not seenA[a]); seenA[a] = true; countA = countA + 1
      local nextA = next(t, a)
      t[a] = nil
      a = nextA
    end
    if b ~= false then
      if b == nil then b = next(t) else b = next(t, b) end
      if b == nil then b = false else assert(not seenB[b]); seenB[b] = true; countB = countB + 1 end
    end
  end
  print("two traversals in turns", countA + countB >= 13, next(t))
end)

-- three interleaved traversals with no deletion: each sees every key once
section(function ()
  local t = build()
  local keys, done = {}, {}
  local counts = {0, 0, 0}
  local seen = {{}, {}, {}}
  for round = 1, 20 do
    for i = 1, 3 do
      if round >= i and not done[i] then  -- each starts one round later
        local k = next(t, keys[i])
        if k == nil then done[i] = true
        else
          assert(not seen[i][k], "key seen twice")
          seen[i][k] = true
          counts[i] = counts[i] + 1
          keys[i] = k
        end
      end
    end
  end
  print("three interleaved", counts[1], counts[2], counts[3])
end)

-- an abandoned traversal whose key is deleted later, then resumed
section(function ()
  local t = build()
  local stop
  for k, v in pairs(t) do if v == 3 then stop = k break end end
  for k in pairs(t) do local _ = k end  -- a full traversal in between
  local rest = {}
  local before = next(t, stop)
  t[stop] = nil
  for k in pairs(t) do local _ = k end
  local k = next(t, stop)
  print("resume deleted key", k == before or (before == nil and k == nil))
end)

-- after abandoned traversals, a drain loop that starts with next(t) empties the table
section(function ()
  local t = build()
  for _ = 1, 3 do for k, v in pairs(t) do if v == 2 then break end end end
  local count = 0
  local k = next(t)
  while k ~= nil do
    t[k] = nil
    count = count + 1
    k = next(t, k)
  end
  print("drain after abandoned traversals", count, next(t))
end)

-- a peek at the following key inside a pairs loop (as serializers do)
section(function ()
  local t = build()
  local count, lasts = 0, 0
  for k in pairs(t) do
    count = count + 1
    if next(t, k) == nil then lasts = lasts + 1 end
  end
  print("peek next key", count, lasts)
end)

-- removing duplicates: an inner traversal from the outer key ('next, t, k'),
-- then the outer key is deleted; the inner loop breaks or runs to the end
for _, breaks in ipairs({true, false}) do
  section(function ()
    local t = {"10", "20", "10"}
    for i = 1, 30 do t["k" .. i] = i % 7 end
    for i = 4, 12 do t[i] = i % 3 end
    t["10"] = 6
    t[2.5] = 5
    local outerSteps = 0
    for k, v in pairs(t) do
      outerSteps = outerSteps + 1
      local duplicate = false
      for _, v2 in next, t, k do
        if v2 == v then
          duplicate = true
          if breaks then break end
        end
      end
      if duplicate then t[k] = nil end
    end
    local count, values = 0, {}
    for _, v in pairs(t) do
      count = count + 1
      assert(not values[v], "duplicate left")
      values[v] = true
    end
    print("dedupe with next, t, k", breaks, outerSteps, count)
  end)
end

-- three nested traversals of one table, the innermost deleting what it sees
section(function ()
  local t = build()
  local outerCount, middleCount, innerCount = 0, 0, 0
  local outerSeen = {}
  for k in pairs(t) do
    assert(not outerSeen[k], "outer key twice")
    outerSeen[k] = true
    outerCount = outerCount + 1
    for k2 in pairs(t) do
      middleCount = middleCount + 1
      for k3 in pairs(t) do
        innerCount = innerCount + 1
        if k3 ~= k and k3 ~= k2 then t[k3] = nil; break end
      end
    end
  end
  print("three nested", outerCount >= 1, middleCount >= outerCount, innerCount >= middleCount, next(t) ~= nil)
end)

-- a coroutine traversing the table, resumed between the steps of the main thread's traversal
section(function ()
  local t = build()
  local coroutineSeen, coroutineCount = {}, 0
  local co = coroutine.wrap(function ()
    for k in pairs(t) do
      assert(not coroutineSeen[k], "coroutine key twice")
      coroutineSeen[k] = true
      coroutineCount = coroutineCount + 1
      coroutine.yield()
    end
    return "done"
  end)
  local mainCount, result = 0, nil
  for k in pairs(t) do
    mainCount = mainCount + 1
    if result == nil then result = co() end
    if type(k) == "string" then t[k] = nil end
  end
  while result == nil do result = co() end
  print("coroutine and main traversals", mainCount, coroutineCount <= 13, result, sortedKeyTypes(t))
end)

-- next called through pcall, from one call site, deleting each key
section(function ()
  local t = build()
  local count = 0
  local ok, k = pcall(next, t)
  while ok and k ~= nil do
    count = count + 1
    t[k] = nil
    ok, k = pcall(next, t, k)
  end
  print("pcall(next)", ok, count, next(t))
end)

-- string keys that PHP would store as integers, next to integer keys
section(function ()
  local t = {[10] = "int", ["10"] = "string", ["-1"] = "s", [-1] = "i", ["010"] = "s", ["0"] = "s", [0] = "i"}
  local seen, count = {}, 0
  for k, v in pairs(t) do
    local name = type(k) .. ":" .. k
    assert(not seen[name])
    seen[name] = true
    count = count + 1
    t[k] = nil
    assert(next(t) ~= k)
  end
  print("numeric strings", count, next(t))
end)

-- one traversal calling next from two places ('k = next(t, k)' in two
-- branches), deleting every other key; it stays linear
section(function ()
  local t = {}
  for i = 1, 3000 do t["k" .. i] = i end
  local count = 0
  local k = next(t)
  while k ~= nil do
    count = count + 1
    if count % 2 == 0 then
      t[k] = nil
      k = next(t, k)
    else
      k = next(t, k)
    end
  end
  local left = 0
  for _ in pairs(t) do left = left + 1 end
  print("two call sites", count, left)
end)

-- a recursive function traversing one table at every level, deleting keys
section(function ()
  local t = build()
  local visits = 0
  local function walk(depth)
    for k in pairs(t) do
      visits = visits + 1
      if depth < 2 then walk(depth + 1) end
      t[k] = nil
    end
  end
  walk(1)
  print("recursive traversals", visits >= 13, next(t))
end)

-- the same function traversing one table in two coroutines, in lockstep
section(function ()
  local t = build()
  local function traverser(name, seen)
    return coroutine.wrap(function ()
      for k in pairs(t) do
        assert(not seen[k], name .. " key twice")
        seen[k] = true
        coroutine.yield(k)
      end
      return nil
    end)
  end
  local seenA, seenB = {}, {}
  local a, b = traverser("a", seenA), traverser("b", seenB)
  local countA, countB = 0, 0
  local ka, kb = a(), b()
  while ka ~= nil or kb ~= nil do
    if ka ~= nil then countA = countA + 1; ka = a() end
    if kb ~= nil then
      countB = countB + 1
      if type(kb) == "number" then t[kb] = nil end
      kb = b()
    end
  end
  print("coroutines in lockstep", countA >= 9, countB, sortedKeyTypes(t))
end)

-- draining a table from two call sites
section(function ()
  local t = build()
  local count = 0
  while next(t) ~= nil do
    local k = next(t)
    t[k] = nil
    count = count + 1
  end
  print("drain from two call sites", count, next(t))
end)

-- many abandoned searches, then traversals that see every key once
section(function ()
  local t = {}
  for i = 1, 200 do t["k" .. i] = i end
  for i = 1, 300 do
    for k, v in pairs(t) do if v == (i % 50) + 1 then break end end
  end
  local seen, count = {}, 0
  for k in pairs(t) do assert(not seen[k]); seen[k] = true; count = count + 1 end
  local k, steps = next(t), 0
  while k ~= nil do steps = steps + 1; k = next(t, k) end
  print("after abandoned searches", count, steps)
end)

-- a peek at the following key after deleting the current one
section(function ()
  local t = build()
  local count, lasts = 0, 0
  for k in pairs(t) do
    count = count + 1
    t[k] = nil
    if next(t, k) == nil then lasts = lasts + 1 end
  end
  print("delete then peek", count, lasts, next(t))
end)

-- keys that were never in the table are still errors
section(function ()
  local t = build()
  for k in pairs(t) do if k == "y" then break end end
  print(pcall(next, t, "nope"))
  print(pcall(next, t, 99))
  print(pcall(next, t, 1.0))
  print(pcall(next, t, newObjectKey("other")))
  t.y = nil
  print(pcall(next, {10, 20}, 3))
end)

-- an emptiness check inside a traversal costs O(1): the traversal stays linear
section(function ()
  local list = {}
  for i = 1, 4000 do list[i] = i end
  local function fastest(f)
    local best = math.huge
    for _ = 1, 5 do
      local start = os.clock()
      f()
      best = math.min(best, os.clock() - start)
    end
    return math.max(best, 1e-6)
  end
  local plain = fastest(function () for _ in pairs(list) do end end)
  local checked = fastest(function () for _ in pairs(list) do assert(next(list) ~= nil) end end)
  print("emptiness check inside pairs is cheap", checked / plain < 20)
end)
