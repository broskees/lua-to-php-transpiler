-- 1e6 appends, then a sum over the array
local t = {}
for i = 1, 1000000 do table.insert(t, i) end
local sum = 0
for i = 1, #t do sum = sum + t[i] end
print(sum)
