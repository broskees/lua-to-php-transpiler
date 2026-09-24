-- The sign bit of NaN results, as C computes them on x86-64: 0/0 is the
-- default NaN (sign set, printed "-nan"), unary minus flips the sign
-- (lvm.c: OP_UNM, luai_numunm), fabs clears it, other operations pass a
-- NaN operand through. printf shows the sign ("%f", "%g", "%a", tostring).

local nan = 0/0
local negated = -nan
local function bits (x) return string.format("%016x", string.unpack("<i8", string.pack("<d", x))) end

print("0/0", nan, bits(nan))
print("-(0/0)", negated, bits(negated), -(0/0), -(-(0/0)), -negated)
print("tostring", tostring(nan), tostring(negated), nan .. "", negated .. "|")

-- OP_UNM through a variable, a table field, an upvalue and a constant expression
local t = {nan}
local function negateUpvalue () return -nan end
print("unm", -t[1], negateUpvalue(), -(0.0/0.0), -(-0.0/0.0))

-- constant expressions: lcode.c: constfolding never folds a NaN result
-- (1e308 * 10 folds to inf, the rest runs)
print("folding", -(1e308 * 10 - 1e308 * 10), -(-(1e308 * 10) + 1e308 * 10), (1e308 * 10) % 2,
      -((1e308 * 10) // (1e308 * 10)), -(0/0) * 1)

-- arithmetic producing NaN
local inf = math.huge
print("inf-inf", inf - inf, -(inf - inf), inf * 0, -(inf * 0))
print("mod", 1 % 0.0, -(1 % 0.0), inf % 1, -(inf % 1), nan % 1, negated % 1, 5.5 % nan, 5.5 % negated)
print("idiv", 0.0 // 0.0, -(0.0 // 0.0), inf // inf, nan // 1, negated // 1)
print("div pow", inf / inf, nan * -1, negated * -1, nan ^ 1, negated ^ 1, (-8) ^ 0.5)

-- math library
print("abs", math.abs(nan), math.abs(negated))
print("floor ceil", math.floor(nan), math.floor(negated), math.ceil(nan), math.ceil(negated))
print("fmod", math.fmod(1, 0.0), math.fmod(nan, 1), math.fmod(negated, 1), math.fmod(1.5, nan))
print("sqrt log", math.sqrt(-1), -math.sqrt(-1), math.log(-1), math.log(-1, 2), math.log(-1, 10), math.log(-1, 3))
print("trig", math.acos(2), math.asin(2), math.sin(inf), math.cos(inf))
print("max min", math.max(nan, 1), math.max(negated, 1), math.min(nan, 1), math.min(negated, 1))
print("exp", math.exp(nan), math.exp(negated), math.fmod(inf, 2))

-- string.format of NaNs
for _, form in ipairs{"%f", "%.3f", "%10.2f", "%-10f|", "%+f", "% f", "%e", "%E", "%g", "%G", "%a", "%A", "%5.1g", "%q"} do
  print(form, string.format(form, nan), string.format(form, negated))
end

-- binary round trips keep the sign
print("pack", string.unpack("d", string.pack("d", nan)), string.unpack("d", string.pack("d", negated)),
      string.unpack("f", string.pack("f", nan)), string.unpack("f", string.pack("f", negated)))
print("dump", load(string.dump(function () return -(0/0), 0/0 end))())
