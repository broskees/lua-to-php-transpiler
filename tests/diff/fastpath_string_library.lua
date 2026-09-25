-- string.byte/sub/format, tostring and string->number conversion: the fast
-- paths and every case that takes the C port

local function show(...) print(select("#", ...), ...) end
local function try(f, ...) print(pcall(f, ...)) end
local s = "hello"

print("-- byte")
show(s:byte())
show(s:byte(1), s:byte(5), s:byte(nil))
show(s:byte(0))
show(s:byte(6))
show(s:byte(-1))
show(s:byte(-5), s:byte(-6))
show(s:byte(1, 3))
show(s:byte(2, nil))
show(s:byte(1.0), s:byte("2"), s:byte(" 3 "))
show(string.byte(123, 2), string.byte(1.5))
show(("").byte(""), ("").byte("", 1))
show(s:byte(math.maxinteger), s:byte(math.mininteger))
show(("\0\255"):byte(1, 2))
try(string.byte, s, 1.5)
try(string.byte, s, "x")
try(string.byte, s, {})
try(string.byte)
try(string.byte, nil)
try(string.byte, {}, 1)
print(pcall(function () return s:byte(2.5) end))

print("-- sub")
print(s:sub(2, 4), s:sub(2), s:sub(2, nil), s:sub(1, 5), s:sub(5, 5), s:sub(1, 1))
print(s:sub(0), s:sub(0, 2), s:sub(-3), s:sub(-3, -2), s:sub(2, -2), s:sub(-100, 100))
print("[" .. s:sub(3, 2) .. "]", "[" .. s:sub(6) .. "]", "[" .. s:sub(1, 0) .. "]", s:sub(1, 100))
print(s:sub(math.mininteger, math.maxinteger), "[" .. s:sub(math.maxinteger) .. "]", s:sub(2, math.maxinteger))
print(s:sub(1.0, 2.0), s:sub("2", "3"), string.sub(12345, 2, 3), string.sub(1.25, 2))
print(s:sub(2, 3, "extra"), "[" .. (""):sub(1) .. "]", "[" .. (""):sub(1, 0) .. "]", "[" .. (""):sub(0, -1) .. "]")
try(string.sub, s)
try(string.sub, s, nil)
try(string.sub, s, 1.5)
try(string.sub, s, 1, 2.5)
try(string.sub, s, 1, "x")
try(string.sub, nil, 1)
try(string.sub, true, 1)
print(pcall(function () return s:sub() end))

print("-- format")
print(string.format("%d", 42), string.format("%d", -7), string.format("%d", 0))
print(string.format("%d|%d", math.maxinteger, math.mininteger))
print(string.format("%d", 3.0), string.format("%d", "10"), string.format("%d", " 0x10 "))
print(string.format("%5d|%-5d|%05d|%+d|% d|%.3d", 42, 42, 42, 42, 42, 42))
print(string.format("%i|%u|%x|%X|%o", 42, 42, 255, 255, 8))
print(string.format("%s|%s|%s", "str", "", "a\0b" == "a\0b"))
print(string.format("%s %s %s %s %s", 1, 2.5, nil, true, -0.0))
print(string.format("%10s|%-10s|%.2s|%5.1s|", "abc", "abc", "abc", "abc"))
print(string.format("%s", setmetatable({}, {__tostring = function () return "custom" end})))
print(string.format("%s", setmetatable({}, {__name = "MyType"})):gsub("0x%x+", "ADDR"))
print(#string.format("%s", "a\0b"), string.format("%s=%d", "x", 1))
print(string.format("%%d %d %%", 5))
try(string.format, "%d", 3.5)
try(string.format, "%d", "x")
try(string.format, "%d", nil)
try(string.format, "%d")
try(string.format, "%s")
try(string.format, "%d %s", 1)
try(string.format, "%10.3q", "x")
try(string.format, "%s", setmetatable({}, {__tostring = function () return 1 end}))
try(string.format, "%s", setmetatable({}, {__tostring = function () return {} end}))
try(string.format, "%5s", "a\0b")
-- a '__tostring' in the string metatable is used by %s and tostring
local stringMeta = getmetatable("")
stringMeta.__tostring = function (v) return "<" .. v .. ">" end
print(string.format("%s", "x"), tostring("y"), string.format("%d", 1))
stringMeta.__tostring = nil
print(string.format("%s", "x"), tostring("y"))

print("-- tostring")
print(tostring(1), tostring(-1), tostring(0), tostring(math.maxinteger), tostring(math.mininteger))
print(tostring(1.0), tostring(-0.0), tostring(1e100), tostring(2^63), tostring(0/0) == tostring(0/0))
print(tostring(nil), tostring(true), tostring("s"), tostring(1, 2))
print(tostring(setmetatable({}, {__tostring = function () return "T" end})))
print(tostring(setmetatable({}, {__name = "Named"})):gsub("0x%x+", "ADDR"))
print(tostring(print):gsub("0x%x+", "ADDR"))
try(tostring)
try(tostring, setmetatable({}, {__tostring = function () return nil end}))
print(pcall(tostring, setmetatable({}, {__tostring = function () return 42 end})))
-- a metatable for numbers: its '__tostring' and '__name' are used
debug.setmetatable(0, {__tostring = function (n) return "N(" .. n .. ")" end})
print(tostring(5), tostring(2.5), string.format("%s", 7), string.format("%d", 7))
debug.setmetatable(0, {__name = "Number"})
print(tostring(5), tostring(2.5))
debug.setmetatable(0, nil)
print(tostring(5), tostring(2.5))

print("-- tonumber and coercions")
local numerals = {"123", "007", "0", "000000000000000000", "999999999999999999",
  "9999999999999999999", "9223372036854775807", "9223372036854775808", "18446744073709551616",
  " 12", "12 ", "\t12\n", "+12", "-12", "1e2", "0x10", "0X1p4", "1.", ".5", "", " ", "1_2", "12a",
  "a12", "1 2", "12\0", "\0" .. "12", "\u{661}\u{662}", "inf", "nan", "--1", "1e", "0x"}
for _, text in ipairs(numerals) do
  local n = tonumber(text)
  print(string.format("%q", text), n, math.type(n))
end
print(tonumber("10", 16), tonumber("zz", 36), tonumber("12", 10), tonumber(12), tonumber(1.5), tonumber(nil))
print("10" + 1, "007" * 2, "3" // 2, math.type("123" + 0), "999999999999999999" + 1)
print(#tostring(12345), ("5"):rep(3) + 0, 10 == tonumber("10"), string.rep("9", 18) + 0)
try(tonumber)
try(tonumber, "10", 99)
try(tonumber, 10, 16)
