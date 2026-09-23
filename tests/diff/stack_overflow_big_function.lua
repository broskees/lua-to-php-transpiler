-- deep recursion of a large function (large PHP frames) still ends in a
-- catchable "stack overflow", never in PHP running out of memory
local parts = {"local function big(n)\n  local a, b, c = n, n + 1, n + 2\n"}
for i = 1, 300 do parts[#parts + 1] = "  if a > " .. (1e9 + i) .. " then a = b * c + " .. i .. " end\n" end
parts[#parts + 1] = "  if n > 0 then return 1 + big(n - 1) end\n  return 0\nend\nreturn big"
local big = load(table.concat(parts), "=big")()
print(big(500))
print(pcall(big, 1e7))
print(pcall(big, 1e7))
print(big(10))
