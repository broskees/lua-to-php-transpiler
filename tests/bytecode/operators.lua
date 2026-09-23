-- Every operator on locals, upvalues, globals and constants, plus the
-- constant-folding edge cases of lcode.c constfolding/validop.
local a, b, c = ...
local up = 10
G = a

-- arithmetic on registers
local r1 = a + b; local r2 = a - b; local r3 = a * b; local r4 = a / b
local r5 = a % b; local r6 = a ^ b; local r7 = a // b
-- bitwise on registers
local r8 = a & b; local r9 = a | b; local r10 = a ~ b
local r11 = a << b; local r12 = a >> b
-- unary
local r13 = -a; local r14 = ~a; local r15 = not a; local r16 = #a
-- comparison
local r17 = a == b; local r18 = a ~= b; local r19 = a < b; local r20 = a <= b
local r21 = a > b; local r22 = a >= b
-- logical and concat
local r23 = a and b; local r24 = a or b; local r25 = a .. b .. c .. "x" .. 1
local r26 = (a .. b) .. c

-- immediate and K operands
local i1 = a + 1; local i2 = 1 + a; local i3 = a - 1; local i4 = a - -127
local i5 = a + 127; local i6 = a + 128; local i7 = a - 128; local i8 = a + -128
local i9 = a * 2; local i10 = 2 * a; local i11 = a / 2; local i12 = a % 3
local i13 = a ^ 2; local i14 = a // 3; local i15 = a + 1.5; local i16 = 1.5 * a
local i17 = a & 0xff; local i18 = 0xff | a; local i19 = a ~ 3; local i20 = a & 1.0
local i21 = a << 3; local i22 = 3 << a; local i23 = a >> 3; local i24 = a << -3
local i25 = a >> -3; local i26 = a << 200; local i27 = 200 >> a; local i28 = a - 100000
local i29 = a + "10"; local i30 = "10" + a; local i31 = a .. 1.5
-- comparisons with immediates (LTI/LEI/GTI/GEI/EQI) and K (EQK)
if a < 1 then G = 1 end
if a <= 1.0 then G = 2 end
if 1 < a then G = 3 end
if 1.0 >= a then G = 4 end
if a > 127 then G = 5 end
if a >= -127 then G = 6 end
if a < 128 then G = 7 end
if a < 1000.0 then G = 8 end
if 1000.0 < a then G = 9 end
if a == 1 then G = 10 end
if a == 1.0 then G = 11 end
if a == 1000.0 then G = 12 end
if a ~= "s" then G = 13 end
if a == nil then G = 14 end
if a == true then G = 15 end
if a ~= false then G = 16 end
if 1 == a then G = 17 end
if "s" == a then G = 18 end
if a == b then G = 19 end
if a == 2.5 then G = 20 end
if up == a then G = 21 end

-- integer folding with wrap-around
F = {
  9223372036854775807 + 1, -9223372036854775807 - 2, 9223372036854775807 * 2,
  -(-9223372036854775807 - 1), 0x7fffffffffffffff + 1, 0xffffffffffffffff,
  4611686018427387904 * 2, -4611686018427387904 * 2, 3037000500 * 3037000500,
  5 // 2, -5 // 2, 5 // -2, 5 % 3, -5 % 3, 5 % -3, -5 % -3,
  (-9223372036854775807 - 1) // -1, (-9223372036854775807 - 1) % -1,
  7 // 1, 7 % -1, 7 // -1,
}
-- bitwise folding: shifts, 2^63 boundaries, float operands
F = {
  1 << 62, 1 << 63, 1 << 64, 1 << -1, -1 >> 1, -1 >> 63, -1 >> 64, -1 << 70,
  1 >> -63, 1 >> -64, ~0, ~5, ~-1, 3 & 5, 3 | 5, 3 ~ 5, 0xf0 & 0x3c,
  3.0 | 1, 2^53 | 0, 1.0 << 2, ~5.0, 3 & 1.0, (1 << 63) >> 63,
}
-- bitwise not folded: non-integral floats, floats out of range
F = { ~5.5, 1.5 | 1, 2^63 | 0, 1e100 & 1, -2^63 | 0, 1 << 2.5 }
-- float folding
F = {
  1 / 2, 2 ^ 2, 2 ^ 0.5, 2 ^ -1, 3.5 % 2, -3.5 % 2, 3.5 % -2, -3.5 % -2,
  7 // 2.0, -7 // 2.0, 7.5 // 2, 1.5 + 1.5, 0.1 + 0.2, 1e308 * 10, -1e308 * 10,
  2^53 + 1, 2^63, -2^63, 2^64, 1 / 3, 10 / 2, -(1.5), -(-1.5), 3 - 0.5,
  5.0 % 3, 5 % 3.0, 2^1023 * 2, 1e-320 / 2, 0.5 ^ 1074, 2 ^ 1024,
  1e15 + 0.3, 123456789012 * 1000.0, 2^53 * 2^53,
}
-- not folded: division/modulo by zero, NaN and zero results
F = {
  1 // 0, 1 % 0, 1 / 0, -1 / 0, 0 / 0, 1 // 0.0, 1 % 0.0, 1.5 // 0, 1.5 % 0,
  1 / 0.0, 1 / -0.0, 0.0 / 0.0, -0.0, 0.0 * -1, 1 - 1.0, 0.5 - 0.5, -(0.0),
  1e-320 * 1e-10, 2 ^ -1080, 0 * -1.5, (2^53) % 1, 1 - 1, 0 * 5,
}
-- unary minus and constants of every kind
F = { -1, -1.5, - -1, -9223372036854775807, -9223372036854775808, -(2^63),
  -0x7fffffffffffffff, 9223372036854775808, -"2", -{} , not nil, not 1, not "x",
  not not a, -up, ~up, #"abc", #{1, 2}, -true }
-- string arithmetic is never folded
F = { "10" + 1, "0x10" * 2, "3" .. 4, 3 .. 4, 1.5 .. "", 2^63 .. "" }
-- operators on upvalues and globals
local function f()
  return up + 1, up * up, up .. up, G + up, -G, not up, up < G, G == up
end
return f
