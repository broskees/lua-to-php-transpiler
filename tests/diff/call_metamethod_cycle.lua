-- a '__call' cycle: each hop passes the called object as a new first
-- argument, until the stack overflows (lua5.4 moves the whole stack at each
-- hop, so the cycles start near the stack's limit to end quickly)
local t = setmetatable({}, {})
getmetatable(t).__call = t
print(pcall(t, table.unpack({}, 1, 999500)))
print(pcall(function () return t(table.unpack({}, 1, 999500)) end))
print(pcall(function () local x = t(table.unpack({}, 1, 999500)) return x end))
-- a chain that ends: the objects come first, in order
local last = setmetatable({}, {__call = function (...) return select("#", ...), ... end})
local middle = setmetatable({}, {__call = last})
local first = setmetatable({}, {__call = middle})
local n, a, b, c, d = first("x")
print(n, a == last, b == middle, c == first, d)
print(select("#", first(table.unpack({}, 1, 100000))))
local function tail() return first("y") end
print(select("#", tail()), select(5, tail()))
