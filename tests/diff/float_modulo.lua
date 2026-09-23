-- float modulus for every sign combination (lvm.c: luai_nummod)
local values = {5.5, -5.5, 5.0, -5.0, 10, -10, 3, -3, 0.0, -0.0, 1/0, -1/0}
for _, a in ipairs(values) do
  local row = {}
  for _, b in ipairs(values) do
    local ok, r = pcall(function () return a % b end)
    local fa, fb = a + 0.0, b + 0.0
    row[#row + 1] = tostring(r) .. "/" .. tostring(fa % fb)
  end
  print(a, table.concat(row, " "))
end
print(-5.0 % -10, -5 % -10.0, 5.0 % -10, -5.0 % 10, 5.5 % -2, -5.5 % -2)
