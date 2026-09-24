-- math.random / math.randomseed: exact xoshiro256** sequences for fixed seeds
local mininteger, maxinteger = math.mininteger, math.maxinteger

-- the official low-level check: first value after seed 1007
print(math.randomseed(1007))
print(math.random(0), math.random(0) == 0x7a7040a5a323c9d6)
print(math.randomseed(1007, 0))
local r = math.random()
print(math.tointeger(r * 2^53), r, math.type(r))

-- every kind of call, interleaved (the rejection loop of 'project' consumes extra values)
print(math.randomseed(42, -7))
for _ = 1, 25 do
  print(math.random(0), math.tointeger(math.random() * 2^53), math.random(6), math.random(-10, 10),
        math.random(1, 2^40), math.random(3.0), math.random("100"))
end

-- wide and extreme intervals
math.randomseed(-1, maxinteger)
for _ = 1, 10 do
  print(math.random(mininteger, maxinteger), math.random(0, maxinteger), math.random(mininteger, -1),
        math.random(mininteger // 2, maxinteger // 2), math.random(maxinteger - 3, maxinteger),
        math.random(mininteger, mininteger + 9), math.random(1, 1 << 59))
end
print(math.random(7, 7), math.random(mininteger, mininteger), math.random(maxinteger, maxinteger))

-- seeds are integers (floats with integral values are accepted)
print(math.randomseed(2^52, -3.0))
print(math.random(0))
print(math.randomseed(maxinteger, mininteger))
print(math.random(0), math.random(10))

-- errors
print(pcall(math.random, 1, 2, 3))
print(pcall(math.random, 2, 1))
print(pcall(math.random, maxinteger, mininteger))
print(pcall(math.random, 1.5))
print(pcall(math.random, "x"))
print(pcall(math.random, 1, {}))
print(pcall(math.random, -5))
print(pcall(math.randomseed, 1.5))
print(pcall(math.randomseed, {}))
print(pcall(math.randomseed, 1, "a"))

-- randomseed() without arguments returns its seeds; reseeding with them repeats the sequence
local seed1, seed2 = math.randomseed()
print(math.type(seed1), math.type(seed2))
local first = math.random(0)
print(math.randomseed(seed1, seed2) == seed1)
print(math.random(0) == first)
for _ = 1, 1000 do
  local x = math.random()
  assert(0 <= x and x < 1)
  local y = math.random(-3, 3)
  assert(-3 <= y and y <= 3 and math.type(y) == "integer")
end
print("ok")
