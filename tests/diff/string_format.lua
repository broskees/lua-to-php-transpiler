-- lstrlib.c: str_format: every conversion with flags, width and
-- precision as glibc prints them, '%q', '%s' with __tostring/__name, and
-- the validity checks

local function show (...)
  local results = table.pack(...)
  local parts = {}
  for i = 1, results.n do
    local value = results[i]
    parts[i] = type(value) == "string" and string.format("%q", value) or tostring(value)
  end
  print(table.concat(parts, " "))
end

local function try (f, ...)
  show(pcall(f, ...))
end

local maxi, mini = math.maxinteger, math.mininteger

-- integers
local integers = {0, 1, -1, 7, 42, -100, 255, 31501, -30927, 0xABCD, 0x7fffffff, -0x80000000, maxi, mini}
local integerFormats = {"%d", "%i", "%5d", "%-5d|", "%05d", "%+d", "% d", "%+05d", "%.3d", "%8.3d", "%-8.3d|",
                        "%.0d", "%+.0d", "%013i", "%2.5d", "%u", "%-12u|", "%012u", "%.u", "%o", "%#o", "%#.3o",
                        "%#12o", "%x", "%X", "%#x", "%#X", "%#10x", "%#-17X|", "%08X", "%010.4x", "%.0x", "%#.0x", "%-#8o|"}
for _, format in ipairs(integerFormats) do
  local line = {}
  for _, n in ipairs(integers) do line[#line + 1] = string.format(format, n) end
  print(format, table.concat(line, " "))
end
show(string.format("%d", 3.0), string.format("%x", 0.0), string.format("%d", "10"), string.format("%x", -1))
show(string.format("%d", 2^53), string.format("%i", -2^53), string.format("%u", ~(-1 << 64)))

-- floats
local floats = {0.0, -0.0, 1.0, -1.0, 0.1, -0.1, 1/3, 2/3, 0.5, 1.5, 2.5, 100.0, 1e15, 1e16, 123456789.0,
                1e-5, 1e-4, 0.00012345, 3.14159265358979, 2^53, 2^63, 1e300, -1e-300, 5e-324, 2.2250738585072014e-308,
                1.7976931348623157e308, 12, 0.125, 1/0, -1/0}
local floatFormats = {"%f", "%.0f", "%.1f", "%.3f", "%10.2f", "%-10.2f|", "%010.2f", "%+.2f", "% .2f", "%#.0f",
                      "%e", "%.0e", "%.2e", "%E", "%12.3e", "%-12.3E|", "%+#.0e", "%g", "%.0g", "%.1g", "%.3g", "%.10g",
                      "%G", "%#g", "%#.3g", "%10g", "%-10g|", "%+g", "% g", "%a", "%A", "%.0a", "%.1a", "%.3a", "%.13a",
                      "%.20a", "%#a", "%#.0a", "%015a", "%-15a|", "%+a", "% A", "%015.3a", "%99.99f"}
for _, format in ipairs(floatFormats) do
  local line = {}
  for _, x in ipairs(floats) do line[#line + 1] = string.format(format, x) end
  print(format, table.concat(line, " "))
end
show(string.format("%.99f", -(10^308)), string.format("%.99f", -(10^38)))
show(string.format("%.99e", 1/3), string.format("%.99g", 0.1), string.format("%.99a", 0.1))
show(string.format("%5.1f", "3.14159"), string.format("%g", 10), string.format("%a", 1))

-- rounding ties at every precision
local ties = {0.5, 1.5, 2.5, 0.125, 0.375, 1.0625, 1.03125, 1.09375, 1.65625, 1.71875, 1 + 0xe8/256,
              1 + 0xf8/256, 0x1.fffp0, 0x1.08p0, 0x1.18p0, 2.675, 1.005, 1e23, 0.3, 9.5, 99.5, 0.05}
for _, x in ipairs(ties) do
  local line = {}
  for precision = 0, 4 do
    for _, conversion in ipairs{"f", "e", "g", "a"} do
      line[#line + 1] = string.format("%." .. precision .. conversion, x)
    end
  end
  print(table.concat(line, " "))
end

-- a spread of doubles
local x = 1.0
for _ = 1, 60 do
  print(string.format("%.17g %a %.3e %g %.20f", x, x, x, x, x))
  x = x * -3.7 / 1.3 + 0.1
end
x = 1.0
for _ = 1, 40 do
  print(string.format("%.17g %a %.5a %g", x, x, x, x))
  x = x / 1234.5
end
print(string.format("%a %a %a %.2a %.30a", 0x0.00000000000018p-1022, 0x0.8p-1022, 0x1p-1074, 0x1.fffp-1022, 0x1p-1060))

-- characters and strings
show(string.format("%c%c%c", 76, 117, 97), string.format("%5c|%-5c|", 65, 66), string.format("%c", 256 + 65))
show(string.format("\0%c\0%c%x\0", 228, 98, 140), string.format("%c", 0))
show(string.format("%s", "x\0y"), string.format("%10s|%-10s|", "ab", "cd"), string.format("%.2s|%5.1s|", "abc", "xyz"))
show(string.format("%.s|%.0s|%.20s", "abc", "abc", "abc"), string.format("%s %s %s", nil, true, 12.5))
show(string.format("-%.20s.20s", string.rep("%", 2000)), #string.format("%99s", "x"))
show(string.format("%-99s|", string.rep("x", 120)) == string.rep("x", 120) .. "|")
local object = setmetatable({}, {__tostring = function () return "hello" end, __name = "hi"})
show(string.format("%s %.10s %8s", object, object, object))
getmetatable(object).__tostring = nil
show(string.format("%.4s", object))
getmetatable(object).__tostring = function () return 42 end
show(string.format("%s", object))
getmetatable(object).__tostring = function () return {} end
try(string.format, "%s", object)
try(string.format, "%10s", "a\0b")

-- %q
show(string.format("%q", 'a "quoted"\n\\ string\0\1\0012\r\t\127\255'))
show(string.format("%q", "\0\0\1\255\u{234}"), string.format("%q", "\n9\r0"), string.format("%q", ""))
for _, value in ipairs{0, maxi, mini, 1.0, -0.0, 0.1, math.pi, 1e100, -1e-100, 5e-324, 1/0, -1/0} do
  local quoted = string.format("%q", value)
  local reloaded = load("return " .. quoted)()
  print(quoted, reloaded == value, math.type(reloaded) == math.type(value))
end
show(string.format("%q", 0/0), string.format("%q", true), string.format("%q", false), string.format("%q", nil))
try(string.format, "%q", {})
try(string.format, "%q", print)
try(string.format, "%10q", "x")
try(string.format, "%-q", "x")

-- %p: NULL for values that are not objects
show(string.format("%p", 4), string.format("%p", true), string.format("%p", nil), string.format("%10p|", false))
show(string.format("%-12p|", 1.5), #string.format("%90p", {}), #string.format("%-60p", {}))
show(string.format("%p", {}) ~= "(null)", string.format("%p", print) == string.format("%p", print))
show(string.format("%p", print) ~= string.format("%p", assert))
local t1, t2 = {}, {}
show(string.format("%p", t1) ~= string.format("%p", t2), string.format("%p", t1) == string.format("%p", t1))

-- %%, literal text, embedded zeros
show(string.format("%%%d %010d", 10, 23), string.format("a\0b%%c\0"), string.format(""), string.format(12))

-- errors
local function check (format)
  local ok, message = pcall(string.format, format, 10)
  show(format, ok, message)
end
local zeros = string.rep("0", 600)
for _, format in ipairs{"%100.3d", "%1" .. zeros .. ".3d", "%1.100d", "%10.1" .. zeros .. "004d", "%t", "%" .. zeros .. "d",
                        "%d %d", "%010c", "%.10c", "%0.34s", "%#i", "%3.1p", "%0.s", "%10q", "%F", "%", "abc%", "%5",
                        "%#u", "%+x", "% o", "%#c", "%+s", "% p", "%-", "%lld", "%hd", "%n", "%i\0", "%\0d", "%123d",
                        "%1.123f", "%.123f", "%-+ #0-+ #0-+ #0d", "%-+ #0-+ #0-+ #0-d", "%00d", "%00x", "%-0d",
                        "%.-2d", "%5.5.5d", "%01.1e"} do
  check(format)
end
try(string.format)
try(string.format, {})
try(string.format, "%d")
try(string.format, "%d", "x")
try(string.format, "%d", 1.5)
try(string.format, "%f", "x")
try(string.format, "%c", {})
try(string.format, "%x", nil)
try(string.format, "%a", "x")
