-- exact comparisons between integers and floats
local big = 2^53
print(big == 2^53, math.tointeger(big) == 9007199254740992)
print(9007199254740993 == 2^53, 9007199254740993 < 2^53 + 1.0, 9007199254740993 > 2^53)
print(9007199254740992 == 2^53, 9007199254740992 <= 2^53, 9007199254740992 >= 2^53)
print(math.maxinteger < 2^63, math.maxinteger >= 2^63, math.maxinteger + 0.0 == 2^63)
print(math.maxinteger == 2^63, math.mininteger == -2^63, math.mininteger < -2^63)
print(math.mininteger <= -2^63, math.maxinteger < math.huge, math.mininteger > -math.huge)
local nan = 0/0
print(nan == nan, nan ~= nan, nan < 1, nan > 1, nan <= nan, 1 < nan, 1 >= nan)
print(1 == 1.0, 1 < 1.5, 2 > 1.5, -1 < -0.5, 0 == -0.0, 1 <= 1.0, 3 >= 3.0)
print(1 < 2, 2 < 1, 1 <= 1, "a" < "b", "a" < "B", "abc" < "abd", "" < "a", "a\0b" < "a\0c", "a" < "a\0")
print("10" < "9", "10" == "10", "1" == 1, 1 == "1")
local t1, t2 = {}, {}
print(t1 == t1, t1 == t2, t1 ~= t2)
for _, v in ipairs({-1, 0, 1, 127, 128, -128}) do
  print(v, v < 0, v <= 0, v > 0, v >= 0, v == 0, v < 127, v > -128)
  local fv = v + 0.5
  print(fv, fv < 0, fv <= 0, fv > 0, fv >= 0, fv == 0.5)
end
print(pcall(function () return 1 < "2" end))
print(pcall(function () return {} < {} end))
print(pcall(function () return "a" <= 1 end))
print(pcall(function () return nil > 1 end))
print(pcall(function () local a, b = true, false; return a < b end))
