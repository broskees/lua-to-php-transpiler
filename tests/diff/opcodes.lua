-- one case per opcode family, including the rare encodings
-- LOADKX: more constants than fit in Bx (2^17)
local pieces = {"local t = {"}
for i = 1, 131100 do pieces[#pieces + 1] = "'k" .. i .. "'," end
pieces[#pieces + 1] = "}; local last = 'final constant'; return #t, t[1], t[131100], last"
print(load(table.concat(pieces))())
-- NEWTABLE/SETLIST with EXTRAARG: big constructors
local items = {}
for i = 1, 600 do items[#items + 1] = tostring(i) end
local big = load("return {" .. table.concat(items, ",") .. "}")()
print(#big, big[1], big[256], big[600])
local hashy = {}
for i = 1, 300 do hashy[#hashy + 1] = "k" .. i .. "=" .. i end
local bigHash = load("return {" .. table.concat(hashy, ",") .. "}")()
print(bigHash.k1, bigHash.k300)
-- LFALSESKIP, TESTSET, NOT
local a, b = 3, 5
local less = a < b
local notLess = not (a < b)
local either = nil or false or "third"
local both = 1 and 2 and nil
print(less, notLess, either, both, not nil, not 0, a > b or "no")
-- constants in comparisons (EQK, EQI, LTI, LEI, GTI, GEI)
local values = {-2, -1, 0, 1, 2, 1.0, 1.5, "1", nil, true}
for i = 1, 10 do
  local v = values[i]
  local numeric = type(v) == "number"
  print(i, v == 1, v == "1", v == 1.5, v == true, v == nil,
    numeric and v < 1, numeric and v <= 1, numeric and v > 1, numeric and v >= 1, numeric and 1 < v)
end
-- arithmetic with constants and immediates on ints and floats
local i, f = 7, 7.5
print(i + 1, i + 200, i - 1, i * 3, i % 4, i ^ 2, i / 2, i // 2, i & 3, i | 8, i ~ 1, i >> 1, i << 1)
print(f + 1, f - 1, f * 3, f % 4, f ^ 2, f / 2, f // 2, 1 - i, 2 ^ i, 10 // i, 10 % i, 1 << i, 256 >> i)
print(i + 1.5, i * 0.5, i % 2.5, i // 0.5, -i, ~i, #"abc", i == 7, f == 7.5)
-- SELF, GETI/SETI, GETFIELD/SETFIELD, GETTABLE/SETTABLE, GETTABUP/SETTABUP
local obj = {n = 1}
function obj:inc(by) self.n = self.n + by; return self end
obj:inc(2):inc(3)
local arr = {}
arr[1] = "one"; arr[255] = "big index"; arr[256] = "bigger"; arr.name = "field"
local key = "dynamic"
arr[key] = "via register"
GLOBAL_VALUE = 99
print(obj.n, arr[1], arr[255], arr[256], arr.name, arr[key], GLOBAL_VALUE, _ENV.GLOBAL_VALUE)
-- CONCAT of many values, LEN
local c1, c2, c3, c4 = "a", "b", 1, 2.5
print(c1 .. c2 .. c3 .. c4 .. c1 .. c2 .. c3 .. c4, #(c1 .. c2))
-- VARARG, TFORCALL
local function sum(...)
  local total = 0
  for _, v in ipairs({...}) do total = total + v end
  return total
end
print(sum(1, 2, 3, 4, 5))
-- upvalue get/set
local counter = 0
local function bump() counter = counter + 1 end
bump(); bump()
print(counter)
