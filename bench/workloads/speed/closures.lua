-- 1e6 short-lived closures capturing a loop variable
local sum = 0
for i = 1, 1000000 do
  local f = function () return i end
  sum = sum + f()
end
print(sum)
