-- Common shapes of code where a traversal is suspended while another runs
-- and its key is deleted before it goes on (clearing fields during a
-- traversal is legal): each must go on from its own key, as in C, where the
-- deleted key keeps its slot.

local function section(name, f)
  local ok, message = pcall(f)
  print(name, ok and "" or message)
end

-- next(t) while an abandoned search still holds the table's pointer, then a drain loop
section("drain after an abandoned search", function ()
  local t = {a = 1, b = 2, c = 3}
  for _, v in pairs(t) do if v == 2 then break end end
  local n, k = 0, next(t)
  while k ~= nil do t[k] = nil; n = n + 1; k = next(t, k) end
  print("drained", n, next(t))
end)

-- an inner loop runs to its end, then the outer loop clears its current field
section("nested, then delete", function ()
  local t = {a = 1, b = 2, c = 3}
  local n = 0
  for k in pairs(t) do
    for _ in pairs(t) do end
    t[k] = nil
    n = n + 1
  end
  print("visited", n, next(t))
end)

-- every later key from the current one ('next, t, k'), then delete it
section("pairs from the key, then delete", function ()
  local t = {a = 1, b = 2, c = 3}
  local n = 0
  for k in pairs(t) do
    for _ in next, t, k do end
    t[k] = nil
    n = n + 1
  end
  print("visited", n, next(t))
end)

-- delete the current field, then ask whether it was the last one
section("delete, then peek", function ()
  local t = {a = 1, b = 2, c = 3}
  local n, lasts = 0, 0
  for k in pairs(t) do
    t[k] = nil
    if next(t, k) == nil then lasts = lasts + 1 end
    n = n + 1
  end
  print("visited", n, lasts)
end)

-- the same loop at two recursion levels, each clearing its current field
section("recursion", function ()
  local t = {a = 1, b = 2, c = 3}
  local n = 0
  local function walk(depth)
    for k in pairs(t) do
      n = n + 1
      if depth < 2 then walk(depth + 1) end
      t[k] = nil
    end
  end
  walk(1)
  print("recursive", n > 0, next(t))
end)
