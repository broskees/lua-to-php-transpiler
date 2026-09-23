-- goto: continue, nested breaks, backward jumps with fresh locals
for i = 1, 5 do
  if i % 2 == 0 then goto continue end
  print("odd", i)
  ::continue::
end
local closures = {}
do
  local i = 1
  ::top::
  local x = i * 10
  closures[#closures + 1] = function () return x end
  i = i + 1
  if i <= 3 then goto top end
end
print(closures[1](), closures[2](), closures[3]())
for i = 1, 3 do
  for j = 1, 3 do
    if j == 2 then goto next_i end
    print(i, j)
  end
  ::next_i::
end
do
  goto skip
  print("never")
  ::skip::
end
local n = 0
::again::
n = n + 1
if n < 5 then goto again end
print(n)
while true do
  n = n + 1
  if n > 7 then break end
end
print(n)
repeat
  local stop = n > 9
  n = n + 1
until stop
print(n)
