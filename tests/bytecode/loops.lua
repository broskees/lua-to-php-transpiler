-- Numeric for (integer and float), generic for, while, repeat, and nested
-- closures capturing loop variables.
local sum = 0
for i = 1, 10 do sum = sum + i end
for i = 10, 1, -1 do sum = sum - i end
for i = 1.0, 2.0, 0.25 do sum = sum + i end
for i = 1, 3.5 do sum = sum + i end
for i = sum, sum * 2, sum // 3 do end
for i = 0x7ffffffffffffff0, math.maxinteger do end
for i = -1e300, 1e300, 1e299 do end
local fs = {}
for i = 1, 3 do
  fs[i] = function() return i end
  for j = i, 3 do
    fs[#fs + 1] = function() return i + j end
  end
end
for k, v in pairs(fs) do
  fs[k] = function() return k, v end
end
for a, b, c, d, e in next, fs, nil do
  local captured = a
  fs[a] = function() return captured, b, c, d, e end
end
for _, x in ipairs({1, 2, 3}) do
  if x == 2 then break end
end
local i = 1
while i < 10 do
  local j = i
  fs[i] = function() return j end
  i = i + 1
  if i == 5 then break end
end
while false do end
while true do break end
while i do i = nil end
repeat
  local r = i
  fs[1] = function() return r end
until r
repeat i = (i or 0) + 1 until i > 3
local function iter(t)
  for k, v in next, t do
    for k2, v2 in next, v do
      return k, v, k2, v2
    end
  end
end
for i = 1, 2 do
  for j = 1, 2 do
    for k = 1, 2 do
      local function deep() return i + j + k end
      fs[#fs + 1] = deep
    end
  end
end
return sum, fs, iter
