-- number formatting and string <-> number conversion
local values = {1e15, 1e16, 1e14, 123456789012345, 0.1, 1/3, -1/3, 100.0, -0.0, 1e100,
  2^63, -2^63, 2^53, 1e-5, 123.456, 3.14159265358979, 1e300 * 10, -1e300 * 10, 5e-324, 2^-1074}
for i, v in ipairs(values) do print(i, v, tostring(v)) end
print(0.1 + 0.2, 1 - 0.9, 100 / 3, 1e15 + 0.5, 7.0, -7.0, 1e15 * 10)
print(tonumber("10"), tonumber("  10  "), tonumber("0x10"), tonumber("0X1F"), tonumber("-0x10"))
print(tonumber("1e1"), tonumber("1E-1"), tonumber(".5"), tonumber("5."), tonumber("0x.8"), tonumber("0x1p4"))
print(tonumber(""), tonumber(" "), tonumber("0x"), tonumber("1e"), tonumber("abc"), tonumber("1 2"), tonumber("1\0"))
print(tonumber("9223372036854775807"), tonumber("9223372036854775808"), tonumber("-9223372036854775808"))
print(tonumber("0xffffffffffffffff"), tonumber("0x10000000000000000"))
print(tonumber(10), tonumber(1.5), tonumber(nil), tonumber(true), tonumber({}))
print(tonumber("ff", 16), tonumber("FF", 16), tonumber("zz", 36), tonumber("777", 8), tonumber("102", 2))
print(tonumber(" 11 ", 2), tonumber("-11", 2), tonumber("+11", 2), tonumber("", 10), tonumber("1.0", 10))
print(tonumber("7fffffffffffffff", 16), tonumber("8000000000000000", 16), tonumber("10000000000000000", 16))
print(pcall(tonumber))
print(pcall(tonumber, "10", 99))
print(pcall(tonumber, 10, 16))
print(pcall(tonumber, "10", "x"))
print(tostring(12), tostring(-12), tostring(1.25), tostring(true), tostring(nil), tostring("s"))
print(math.tointeger(3.0), math.tointeger(3.5), math.tointeger("8"), math.tointeger("x"), math.tointeger(2^63))
print(3 | 0, 2^31 | 0, pcall(function () return 2.5 | 0 end))
print(pcall(function () return 2^64 | 0 end))
print(1e15, 1e+15, 2^24, -2^24, 2^31, 2^32)
