-- math library (lmathlib.c): integer/float results, conversions and errors
local maxi, mini = math.maxinteger, math.mininteger
local function show(...)
  local t = table.pack(...)
  for i = 1, t.n do
    local v = t[i]
    t[i] = tostring(v) .. (math.type(v) and ":" .. math.type(v) or "")
  end
  print(table.concat(t, " "))
end

print(math.pi, math.huge, -math.huge, maxi, mini, math.type(math.pi))

-- floor / ceil: integers when the result fits
show(math.floor(3), math.floor(3.7), math.floor(-3.7), math.floor(-0.0), math.floor(2^62), math.floor(2^63))
show(math.floor(-2^63), math.floor(1e100), math.floor(-math.huge), math.floor("3.5"), math.floor("7"), math.floor(" 0x10 "))
show(math.ceil(3), math.ceil(3.2), math.ceil(-3.2), math.ceil(-0.5), math.ceil(2^63 - 1024), math.ceil(-2^63 - 2048))
show(math.ceil(math.huge), math.ceil("2.1"), math.floor(maxi), math.ceil(mini))
print(pcall(math.floor), pcall(math.floor, {}), pcall(math.ceil, "x"))

-- fmod: integer path, zero error, -1 and mininteger
show(math.fmod(7, 3), math.fmod(-7, 3), math.fmod(7, -3), math.fmod(-7, -3), math.fmod(mini, -1), math.fmod(mini, 1))
show(math.fmod(maxi, 2), math.fmod(mini, maxi), math.fmod(5, mini), math.fmod(0, 5))
show(math.fmod(7.5, 2), math.fmod(-7.5, 2), math.fmod(7, 2.5), math.fmod(1, math.huge), math.fmod(-6, 2.0))
show(math.fmod("7", 3), math.fmod(7, "3"), math.fmod(mini, -1.0))
print(pcall(math.fmod, 1, 0))
print(pcall(math.fmod, 1))
print(pcall(math.fmod, nil, 1))
print(math.fmod(1, 0.0) ~= math.fmod(1, 0.0), math.fmod(math.huge, 1) ~= math.fmod(math.huge, 1))

-- modf
show(math.modf(3.7), math.modf(-3.7))
show(math.modf(5), math.modf(mini))
show(math.modf(math.huge))
show(math.modf(-math.huge))
show(math.modf(2^63), math.modf(-0.0))
show(math.modf("2.5"))

-- tointeger
show(math.tointeger(3), math.tointeger(3.0), math.tointeger(3.5), math.tointeger("8"), math.tointeger("8.0"), math.tointeger("x"))
show(math.tointeger(2^63), math.tointeger(-2^63), math.tointeger({}), math.tointeger(nil), math.tointeger(0/0))
print(pcall(math.tointeger))

-- ult, abs, type
print(math.ult(1, 2), math.ult(-1, 2), math.ult(2, -1), math.ult(mini, maxi), math.ult(3, 3))
print(pcall(math.ult, 1.5, 2))
print(pcall(math.ult, 1))
show(math.abs(mini), math.abs(-3), math.abs(3), math.abs(-0.0), math.abs(-2.5), math.abs("-3"), math.abs(-math.huge))
print(math.type(1), math.type(1.0), math.type("1"), math.type(nil), math.type({}))
print(pcall(math.type))

-- min / max
show(math.max(1, 2.5, -3), math.min(1, 2.5, -3), math.max(3, 3.0), math.max(3.0, 3), math.min(3, 3.0))
show(math.max(maxi, 2^63), math.min(mini, -2^63), math.max(mini), math.min(0.0, -0.0), math.max(-0.0, 0.0))
print(math.max("a", "b"), math.min("a", "b"))
print(pcall(math.max))
print(pcall(math.min))
print(pcall(math.max, 1, "a"))
print(pcall(math.min, {}, 1))
local mt = {__lt = function (a, b) return a.v < b.v end}
local small, big = setmetatable({v = 1}, mt), setmetatable({v = 2}, mt)
print(math.max(small, big) == big, math.min(big, small) == small)

-- logarithms, including exact bases 2 and 10
show(math.log(8, 2), math.log(1024, 2), math.log(2^-1074, 2), math.log(100, 10), math.log(1000, 10), math.log(1e-300, 10))
show(math.log(1), math.log(0), math.log(math.exp(2)), math.log(27, 3), math.log(10, 2), math.log(3, 2))
show(math.log(8, 2.0), math.log(8, "2"), math.log(1, nil), math.log("100", 10))
print(math.log(-1) ~= math.log(-1), math.log(-1, 2) ~= math.log(-1, 2))
print(math.log(0, 2), math.log(math.huge, 2), math.log(0, 10))
print(pcall(math.log))
print(pcall(math.log, 2, {}))

-- trigonometry, exp, sqrt, deg/rad
show(math.sin(0), math.cos(0), math.tan(0), math.asin(1), math.acos(-1), math.atan(1))
show(math.atan(1, 2), math.atan(-1, -1), math.atan(0, -1), math.atan(1, 0), math.atan2(1, 1))
show(math.sqrt(16), math.sqrt(2), math.exp(0), math.exp(1), math.exp(1000), math.exp(-1000))
show(math.deg(math.pi), math.rad(180), math.deg(1), math.rad(1), math.deg(3))
print(math.sqrt(-1) ~= math.sqrt(-1))

-- LUA_COMPAT_MATHLIB functions of the reference build
show(math.pow(2, 10), math.pow(2, 0.5), math.pow(-8, 1/3) ~= math.pow(-8, 1/3), math.cosh(0), math.sinh(0), math.tanh(0))
show(math.cosh(1), math.sinh(1), math.tanh(1), math.log10(1000), math.log10(2))
show(math.frexp(8))
show(math.frexp(-3))
show(math.frexp(0))
show(math.frexp(2^-1074))
show(math.frexp(math.huge))
show(math.ldexp(1, 3), math.ldexp(0.5, 1024), math.ldexp(1, -1074), math.ldexp(1, -1075), math.ldexp(3, 2.0))
show(math.ldexp(1, 2^32 + 3), math.ldexp(1, 1 << 40))
print(pcall(math.ldexp, 1, 1.5))
print(pcall(math.frexp))

-- logarithms with degenerate bases (C divides log(x) by log(base))
print(math.log(2, 1), math.log(0.5, 1), math.log(2, 0), math.log(8, math.huge), math.log(1, 1) ~= math.log(1, 1))
print(math.log(2, -1) ~= math.log(2, -1), math.log(0, 0) ~= math.log(0, 0), math.log(1, 0))
-- log2 and log10 are C's own functions: check the bits of results that are not exact
local function bits(x) return string.format("%a", x) end
print(bits(math.log(3, 2)), bits(math.log(12345.678, 2)), bits(math.log(1e-10, 2)), bits(math.log(0.1, 2)))
print(bits(math.log(7, 10)), bits(math.log(0.3, 10)), bits(math.log(3, 7)), bits(math.log(1e300)))
local mismatches = 0
for i = 1, 3000 do
  local x = i * 1.37
  if math.log(x, 2) ~= math.log(x) / math.log(2) then mismatches = mismatches + 1 end
end
print(mismatches > 0)
