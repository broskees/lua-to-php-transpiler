-- lstrlib.c: string.pack, string.packsize, string.unpack: every option,
-- endianness, alignment, integer overflow checks and error messages

local function hex (s)
  return (string.gsub(s, ".", function (c) return string.format("%02x", string.byte(c)) end))
end

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

local function roundtrip (format, ...)
  local packed = string.pack(format, ...)
  print(format, hex(packed), string.packsize(format:find("[sz]") and "b" or format), string.unpack(format, packed))
end

-- sizes
for _, option in ipairs{"b", "B", "h", "H", "i", "I", "l", "L", "j", "J", "T", "f", "d", "n", "i1", "i7", "I16", "c10", "x", "!xXi16"} do
  print(option, string.packsize(option))
end

-- integers of every size, both byte orders
for size = 1, 16 do
  local s = string.pack("i" .. size, -1)
  print(size, hex(s), string.unpack("i" .. size, s), hex(string.pack("<I" .. size, 0xAA)), hex(string.pack(">I" .. size, 0xAA)))
end
local lnum = 0x13121110090807060504030201
for size = 1, 8 do
  local n = lnum & ~(-1 << (size * 8))
  print(size, hex(string.pack("<i" .. size, n)), hex(string.pack(">i" .. size, n)),
        string.unpack(">i" .. size, string.pack(">i" .. size, n)), string.unpack("<i" .. size, "\xf0" .. ("\xff"):rep(size - 1)))
end
for size = 9, 16 do
  local s = string.pack("<j", -lnum)
  print(size, string.unpack("<i" .. size, s .. ("\xFF"):rep(size - 8)), string.unpack("<I" .. size, s .. ("\0"):rep(size - 8)),
        string.unpack(">i" .. size, ("\xFF"):rep(size - 8) .. s:reverse()), hex(string.pack("<i" .. size, -2)), hex(string.pack(">I" .. size, 3)))
  try(string.unpack, "<I" .. size, ("\x00"):rep(size - 1) .. "\1")
  try(string.unpack, ">i" .. size, "\1" .. ("\x00"):rep(size - 1))
end
roundtrip("<j", math.maxinteger)
roundtrip(">j", math.mininteger)
roundtrip("<J", -1)
roundtrip("=i4", 2001)
roundtrip(">i2 <i2", 10, 20)
roundtrip("bBhHlL", -128, 255, -32768, 65535, math.mininteger, -1)

-- overflow checks
for size = 1, 7 do
  local umax = (1 << (size * 8)) - 1
  local max = umax >> 1
  local min = ~max
  try(string.pack, "<I" .. size, -1)
  try(string.pack, ">I" .. size, umax + 1)
  try(string.pack, ">i" .. size, max + 1)
  try(string.pack, "<i" .. size, min - 1)
  show(string.unpack(">i" .. size, string.pack(">i" .. size, max)), string.unpack("<i" .. size, string.pack("<i" .. size, min)),
       string.unpack(">I" .. size, string.pack(">I" .. size, umax)))
end

-- floats
for _, n in ipairs{0, -0.0, -1.1, 1.9, 1/0, -1/0, 1e20, -1e20, 0.1, 2000.7, 1e300, 5e-324, 3.4028235677973366e38, 1e-46} do
  print(n, hex(string.pack("<f", n)), hex(string.pack(">d", n)), hex(string.pack("n", n)),
        string.unpack("<f", string.pack("<f", n)), string.unpack(">d", string.pack(">d", n)))
end
show(string.unpack("f", string.pack("f", 0/0)) ~= string.unpack("f", string.pack("f", 0/0)))
roundtrip("<b h b f d f n i", 1, 2, 3, 4, 5, 6, 7, 8)
roundtrip("d", "3.5")

-- strings
local long = string.rep("abc", 1000)
show(string.pack("zB", long, 247) == long .. "\0\xF7", string.unpack("zB", long .. "\0\xF9") == long)
show(string.unpack("s", string.pack("s", long)) == long, #string.pack("s1", "abc"), hex(string.pack(">s2", "hi")))
for size = 1, 16 do
  local packed = string.pack("s" .. size, "xyz")
  print(size, #packed, string.unpack("s" .. size, packed))
end
roundtrip("c0", "")
roundtrip("c3", "123")
roundtrip("c8", "123456")
roundtrip("<! c3", "abc")
roundtrip("z", "hello")
roundtrip("s", "")
show(string.unpack("!4 z c3", "abcdefghi\0xyz"))
show(string.unpack("c0", "abc", 4), string.unpack("z", "a\0b\0", 3))

-- alignment
show(hex(string.pack(" < i1 i2 ", 2, 3)), hex(string.pack(">!8 b Xh i4 i8 c1 Xi8", -12, 100, 200, "\xEC")))
show(string.unpack(">!8 c1 Xh i4 i8 b Xi8 XI XH", string.pack(">!8 b Xh i4 i8 c1 Xi8", -12, 100, 200, "\xEC")))
show(hex(string.pack(">!4 c3 c4 c2 z i4 c5 c2 Xi4", "abc", "abcd", "xz", "hello", 5, "world", "xy")))
show(hex(string.pack(" b b Xd b Xb x", 1, 2, 3)), string.packsize(" b b Xd b Xb x"), string.unpack("bbXdb", "\1\2\3\0"))
show(string.packsize("!8 xXi8"), string.unpack("!8 xXi8", "0123456701234567"), string.packsize("!2 xXi8"))
show(string.packsize("!16 xXi16"), string.unpack("!16 xXi16", "0123456701234567"), string.packsize("!4 b d"), string.packsize("! b d"))
try(string.packsize, "!b i3")
show(string.packsize("!1 b d"), string.packsize("!b i2"), string.packsize("!8 b i16"), string.packsize("<!2 b j b"))

-- initial positions
local x = string.pack("i4i4i4i4", 1, 2, 3, 4)
for position = -17, 18 do
  local ok, value, nextPosition = pcall(string.unpack, "!4 i4", x, position)
  print(position, ok, value, nextPosition)
end
for position = 1, #x + 2 do show(pcall(string.unpack, "c0", x, position)) end

-- errors
try(string.pack, "i0", 0)
try(string.pack, "i17", 0)
try(string.pack, "!17", 0)
try(string.pack, "Xi17")
try(string.pack, "i3r", 0)
try(string.unpack, "i16", string.rep("\3", 16))
try(string.pack, "!4i3", 0)
try(string.pack, "c", "")
try(string.packsize, "s")
try(string.packsize, "z")
try(string.packsize, "c1" .. string.rep("0", 40))
try(string.packsize, string.rep("c268435456", 2^3))
show(string.packsize(string.rep("c268435456", 2^3 - 1) .. "c268435455"))
try(string.pack, "s1", long)
try(string.pack, "z", "alo\0")
try(string.unpack, "zc10000000", "alo")
try(string.unpack, "s", string.pack("s", "alo"):sub(1, -2))
try(string.unpack, "c5", "abcd")
try(string.pack, "s100", "alo")
try(string.pack, "c3", "1234")
try(string.pack, "X")
try(string.unpack, "XXi", "")
try(string.unpack, "X i", "")
try(string.pack, "Xc1")
try(string.unpack, "c0", x, #x + 2)
try(string.pack, "i4")
try(string.pack, "i4", "x")
try(string.pack, "i4", 1.5)
try(string.pack, "d", {})
try(string.pack, "z", nil)
try(string.pack)
try(string.unpack, "i4")
try(string.unpack, "i4", "abc")
try(string.unpack, "i4", "abcd", "x")
try(string.pack, "<i4\0>i4", 1, 2)
try(string.pack, "i4", 1, 2, 3)
try(string.unpack, "I8", string.rep("\xff", 8))
try(string.unpack, "s8", string.rep("\xff", 8))
-- a missing argument is the nil that str_pack pushes as a mark
try(string.pack, "i4i4", 1)
try(string.pack, "i4i4i4i4", 1, 2)
try(string.pack, "zzi4", "x")
try(string.pack, "c1000i4i4", "x")
