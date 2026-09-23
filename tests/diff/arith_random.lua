-- pseudo-random 64-bit values (a wrapping LCG) through every integer and
-- float operation, compared digit for digit with lua5.4
local state = 0x2545F4914F6CDD1D
local function nextValue()
  state = state * 6364136223846793005 + 1442695040888963407
  return state
end
local checksum = 0
local lines = 0
for i = 1, 3000 do
  local a, b = nextValue(), nextValue()
  local small = (b >> 58) - 32
  local results = {
    a + b, a - b, a * b, a // (b | 1), a % (b | 1), a & b, a | b, a ~ b, ~a,
    a << (small & 63), a >> (small & 63), a << small, a >> small, -a,
    a // 7, a % 7, a // -7, a % -7, a * 3, a * -1, a + 0x7fffffffffffffff,
    math.ult(a, b) and 1 or 0, (a < b) and 1 or 0, (a <= b) and 1 or 0,
  }
  local fa, fb = a / 2^(i % 70), b * 1.0
  local floats = {fa + fb, fa - fb, fa * 3.5, fa / (fb ~= 0 and fb or 1), fa // 1.5, fa % 7.25, fa ^ 0.5 ~= fa ^ 0.5 and 0 or 1}
  for _, v in ipairs(results) do checksum = checksum ~ v; checksum = checksum * 31 + 7 end
  if i % 100 == 0 then
    print(i, a, b, table.concat(results, " "))
    print(i, fa, fb, table.concat(floats, " "))
    lines = lines + 2
  end
  -- mixed int/float comparisons and conversions around 2^53 and 2^63
  local asFloat = a + 0.0
  local back = math.tointeger(asFloat)
  if i % 250 == 0 then
    print(asFloat, back, asFloat == a, asFloat < a, asFloat > a, math.type(asFloat // 1), a // 1.0, (a + 0.5) == a)
  end
end
print(checksum, lines)
