-- 3e5 objects made with setmetatable, and a method call on each
local Point = {}
Point.__index = Point
function Point.new(x, y) return setmetatable({x = x, y = y}, Point) end
function Point:sum() return self.x + self.y end
local total = 0
for i = 1, 300000 do total = total + Point.new(i, -i):sum() + i end
print(total)
