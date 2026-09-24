-- string building, concatenation and gsub
local parts = {}
for i = 1, 100000 do parts[#parts + 1] = "item" .. i end
local text = table.concat(parts, ",")
local doubled = text:gsub("%d+", function (digits) return tostring(tonumber(digits) * 2) end)
local upper = text:gsub("item", string.upper)
local s = ""
for i = 1, 20000 do s = s .. "x" end
print(#text, #doubled, #upper, #s)
