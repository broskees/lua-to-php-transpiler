-- numeric for loops: integers, floats, edge values, errors
for i = 1, 3 do io = io; print(i) end
for i = 3, 1, -1 do print(i) end
for i = 1, 0 do print("never") end
for i = 1, 2, 0.5 do print(i) end
for i = 1.0, 3 do print(i) end
for i = 0.1, 0.35, 0.1 do print(i) end
for i = math.maxinteger - 2, math.maxinteger do print(i) end
for i = math.mininteger, math.mininteger + 2 do print(i) end
for i = math.mininteger + 2, math.mininteger, -1 do print(i) end
for i = 1, math.huge do if i > 3 then break end print(i) end
for i = -1, -math.huge, -1 do if i < -3 then break end print(i) end
for i = 1, 3.9 do print(i) end
for i = 3, 0.5, -1 do print(i) end
for i = "1", "3" do print(i) end
for i = 1, 5, 2 do print(i) end
for i = 5, 1, -3 do print(i) end
for i = math.maxinteger - 5, math.maxinteger, 4 do print(i) end
for i = 0, math.mininteger, math.mininteger do print(i) end
for i = 1, 0.5 do print("never") end
for i = 1, -math.huge do print("never") end
for i = 1, math.maxinteger, math.maxinteger do print(i) end
local n = 0
for i = 1, 1000000 do n = n + i end
print(n)
local f = 0
for i = 1, 100 do f = f + 0.1 end
print(f)
for i = 1, 3 do local i = i * 2; print(i) end
print(pcall(function () for i = 1, 10, 0 do end end))
print(pcall(function () for i = nil, 1 do end end))
print(pcall(function () for i = 1, {} do end end))
print(pcall(function () for i = 1, 2, "x" do end end))
