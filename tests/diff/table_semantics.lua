-- table semantics (ltable.c): key normalization, 'next' keys, borders, extreme keys
local maxI, minI = math.maxinteger, math.mininteger

-- float keys with integral values are integer keys; other floats are distinct
local t = {}
t[1.0] = "a"; t[2^53] = "b"; t[-0.0] = "zero"; t[0.5] = "half"
print(t[1], t[2^53 // 1], t[0], t[0.5], math.type((next({[3.0] = true}))))
print(rawget(t, 1.0), rawget(t, -0.0), rawequal(t[1], t[1.0]))

-- 'next' does not normalize its key: an integral float is never a valid key
print(pcall(next, {10}, 1.0))
print(pcall(next, {10, 20}, 2.0))
print(pcall(next, {[5] = 1}, 5.0))
print(pcall(next, {[0.5] = 1}, 0.5))
print(pcall(next, {10}, 2))
print(pcall(next, {a = 1}, "b"))
print(pcall(next, {}, {}))
print(pcall(next, {10}, true))
print(next({10}, 1))

-- nil and NaN keys
print(pcall(function () local u = {}; u[nil] = 1 end))
print(pcall(function () local u = {}; u[0/0] = 1 end))
print(pcall(rawset, {}, nil, 1))
print(pcall(rawset, {}, 0/0, 1))
print(({})[nil], ({})[0/0], rawget({}, nil), rawget({}, 0/0))
print(pcall(function () local u = {}; u[nil] = nil end))

-- extreme integer keys and floats outside the integer range
local e = {}
e[maxI] = "max"; e[minI] = "min"; e[2^63] = "2^63"; e[-2^63] = "-2^63"; e[math.huge] = "inf"
print(e[maxI], e[minI], e[2^63], e[-2^63], e[math.huge], e[maxI + 0.0], #e)
e[1] = 1; e[2] = 2
print(#e)
local count = 0
for k, v in pairs(e) do count = count + 1 end
print(count)

-- borders of constructors and appends
print(#{}, #{nil}, #{nil, nil, nil, nil}, #{1, 2, 3, nil, nil}, #{1, nil, 3}, #{nil, 2}, #{n = 1, 1, 2})
local function pack(...) return {...} end
print(#pack(), #pack(nil), #pack(1, nil), #pack(1, nil, 3), #pack(nil, nil, 3))
local grow = {}
for i = 1, 100 do grow[#grow + 1] = i end
print(#grow)
for i = 1, 100 do grow[#grow] = nil end
print(#grow, next(grow))
local sparse = {}
for i = 0, 50 do sparse[2^i] = true end
print(sparse[#sparse])  -- (which border # finds depends on C's array/hash layout)
local negatives = {[-1] = 1, [0] = 0}
print(#negatives)
local maxOnly = {[maxI] = 1}
print(#maxOnly)

-- clearing fields during traversal, in every part of the table
local mixedKeys = {1, 2, 3, x = 1, y = 2, [2.5] = 3, [true] = 4, [print] = 5}
local visited = 0
for k in pairs(mixedKeys) do mixedKeys[k] = nil; visited = visited + 1 end
print(visited, next(mixedKeys))
-- assigning to existing fields during traversal
local values = {1, 2, 3, a = 4, b = 5}
for k, v in pairs(values) do values[k] = v * 10 end
print(values[1], values[2], values[3], values.a, values.b)
-- next(t) during traversal always restarts from the first remaining key
local drained = {}
for i = 1, 20 do drained[i] = i; drained["k" .. i] = i end
local steps = 0
for k in pairs(drained) do
  assert(next(drained) == k)
  drained[k] = nil
  steps = steps + 1
end
print(steps, next(drained))
