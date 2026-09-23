-- every arithmetic, bitwise, unary, concat and length metamethod
local log = {}
local mt = {}
local names = {"add", "sub", "mul", "div", "mod", "pow", "unm", "idiv",
  "band", "bor", "bxor", "shl", "shr", "bnot", "concat", "len"}
for _, name in ipairs(names) do
  mt["__" .. name] = function (a, b)
    local left = type(a) == "table" and a.name or tostring(a)
    local right = type(b) == "table" and b.name or tostring(b)
    return name .. "(" .. left .. "," .. right .. ")"
  end
end
local A = setmetatable({name = "A"}, mt)
local B = setmetatable({name = "B"}, mt)
print(A + B, A - 1, 2 * A, A / B, A % 3, 2 ^ A, -A, A // B)
print(A & 1, 1 | A, A ~ B, A << 1, 1 >> A, ~A, A .. "x", "x" .. A, 1 .. A, A .. B, #A)
print(A + 1.5, 1.5 + A, A - 100000, A * -3, 7 // A, A % 2.5)
-- the metamethod of the first operand wins; else the second one's
local C = setmetatable({name = "C"}, {__add = function () return "C.add" end})
print(A + C, C + A, C + 1, 1 + C)
-- concat chains call __concat right to left
print("a" .. "b" .. A .. "c" .. "d")
print(A .. "b" .. "c")
print(1 .. 2 .. A)
-- __len result can be anything
local L = setmetatable({}, {__len = function () return 42, 43 end})
print(#L)
-- __unm/__len receive the operand twice
local seen = setmetatable({}, {__unm = function (a, b) return rawequal(a, b) end, __len = function (a, b) return rawequal(a, b) end})
print(-seen, #seen)
-- errors without metamethods
print(pcall(function () return {} + 1 end))
print(pcall(function () local t = {}; return t.x + 1 end))
print(pcall(function () local t = {}; return 1 & t end))
print(pcall(function () return 1.5 & 1 end))
print(pcall(function () local a = 2^63; return a | 1 end))
print(pcall(function () return "a" | 1 end))
print(pcall(function () return -{} end))
print(pcall(function () return ~{} end))
print(pcall(function () return #5 end))
print(pcall(function () return {} .. "x" end))
print(pcall(function () local u; return "x" .. u end))
print(pcall(function () return 1 .. {} end))
print(pcall(function () return nil .. 1 end))
print(pcall(function () return true + 1 end))
