-- string methods (byte, sub), string.format, tostring and tonumber
local s = string.rep("lorem ipsum ", 100)
local count, total = 0, 0
for round = 1, 150 do
  for i = 1, #s do
    total = total + s:byte(i)
    if s:sub(i, i + 1) == "ip" then count = count + 1 end
  end
end
local length = 0
for i = 1, 100000 do length = length + #string.format("%d: %s", i, "item") end
for i = 1, 200000 do total = total + tonumber(tostring(i)) end
print(count, total, length)
