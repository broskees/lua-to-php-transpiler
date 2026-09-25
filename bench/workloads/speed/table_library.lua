-- table.remove as a stack pop, table.concat and table.unpack
local stack, sum = {}, 0
for round = 1, 10 do
  for i = 1, 20000 do stack[#stack + 1] = i end
  for i = 1, 20000 do sum = sum + table.remove(stack) end
end
local words = {}
for i = 1, 1000 do words[i] = "w" .. i end
local length = 0
for round = 1, 1000 do length = length + #table.concat(words, " ") end
local point = {1, 2, 3, 4}
for i = 1, 200000 do
  local a, b, c, d = table.unpack(point)
  sum = sum + a + d
end
print(sum, length)
