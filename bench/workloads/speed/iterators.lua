-- ipairs and pairs loops (6e5 iterator calls)
local list, record = {}, {}
for i = 1, 1000 do list[i] = i; record["k" .. i] = i end
local sum = 0
for round = 1, 300 do
  for _, v in ipairs(list) do sum = sum + v end
  for _, v in pairs(record) do sum = sum + v end
end
print(sum)
