-- 1e6 short-lived small tables
local sum = 0
for i = 1, 1000000 do
  local point = {x = i, y = -i}
  sum = sum + point.x + point.y
end
print(sum)
