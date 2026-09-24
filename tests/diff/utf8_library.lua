-- lutf8lib.c: char, charpattern, codes, codepoint, len, offset, the lax
-- flags and every error

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

show(utf8.charpattern, require("utf8") == utf8)

-- char
show(utf8.char(), utf8.char(0, 97, 98, 99, 1), utf8.char(72, 228, 8364, 128512))
show(utf8.char(0x7F, 0x80, 0x7FF, 0x800, 0xFFFF, 0x10000, 0x10FFFF, 0x110000, 0x1FFFFF, 0x200000, 0x3FFFFFF, 0x4000000, 0x7FFFFFFF))
show(utf8.char("65", 66.0))
try(utf8.char, 0x7FFFFFFF + 1)
try(utf8.char, -1)
try(utf8.char, 65, -1)
try(utf8.char, 1.5)
try(utf8.char, "x")
try(utf8.char, nil)

-- len, with positions and lax
local samples = {"", "hello World", "汉字/漢字", "日本語a-4\0éó", "𣲷𠜎𠱓𡁻𠵼ab𠺢", "\u{D800}", "\u{7FFFFFFF}",
                 "abc\xE3def", "\xF4\x9F\xBF", "\xF4\x9F\xBF\xBF", "汉字\x80", "\x80hello", "hel\xBFlo", "\xC0\x80",
                 "\xC1\xBF", "\xE0\x9F\xBF", "\xF0\x8F\xBF\xBF", "\xFE", "\xFF", "\u{4000000}\u{7FFFFFFF}", "áéí\128"}
for _, s in ipairs(samples) do
  print(string.format("%q", s), utf8.len(s), utf8.len(s, 1, -1, true), pcall(utf8.len, s, 2))
  print(pcall(utf8.len, s, -1))
  print(pcall(utf8.len, s, 1, 2))
end
try(utf8.len, "abc", 0, 2)
try(utf8.len, "abc", 1, 4)
try(utf8.len, "abc", 5)
try(utf8.len, "abc", -5)
show(utf8.len("abc", 4), utf8.len("abc", 4, 3), utf8.len("abc", 1, -4), utf8.len(123))
try(utf8.len)

-- codepoint
for _, s in ipairs(samples) do
  print(string.format("%q", s), pcall(utf8.codepoint, s, 1, -1), pcall(utf8.codepoint, s, 1, -1, true))
end
show(utf8.codepoint("héllo"), utf8.codepoint("héllo", 2), utf8.codepoint("héllo", -1), utf8.codepoint("héllo", 4, 3))
show(utf8.codepoint("\u{D7FF}"), utf8.codepoint("\u{E000}"), utf8.codepoint("\u{D800}", 1, 1, true))
try(utf8.codepoint, "abc", 0)
try(utf8.codepoint, "abc", 4)
try(utf8.codepoint, "abc", 1, 4)
try(utf8.codepoint, "abc", -4, 1)
try(utf8.codepoint, "héllo", 3)
try(utf8.codepoint, "\u{D800}")
try(utf8.codepoint, "\xF4\x9F\xBF\xBF")

-- offset
local s = "日本語a-4\0éó"
for n = -12, 12 do
  local line = {}
  for i = 1, #s + 1 do
    local ok, result = pcall(utf8.offset, s, n, i)
    line[#line + 1] = ok and tostring(result) or "E"
  end
  print(n, table.concat(line, " "))
end
show(utf8.offset("alo", 5), utf8.offset("alo", -4), utf8.offset("", 1), utf8.offset("", -1), utf8.offset("", 0))
try(utf8.offset, "abc", 1, 5)
try(utf8.offset, "abc", 1, -4)
try(utf8.offset, "", 1, 2)
try(utf8.offset, "", 1, -1)
try(utf8.offset, "𦧺", 1, 2)
try(utf8.offset, "\x80", 1)
try(utf8.offset, "abc")

-- codes
for _, s in ipairs{"", "hello", "汉字/漢字", "日本語a-4\0éó", "𣲷ab"} do
  local positions = {}
  for p, c in utf8.codes(s) do positions[#positions + 1] = p .. ":" .. c end
  print(string.format("%q", s), table.concat(positions, " "))
end
for _, s in ipairs{"ab\xff", "\u{110000}", "in\x80valid", "\xbfinvalid", "αλφ\xBFα", "\u{D800}", "a\xC3"} do
  print(string.format("%q", s), pcall(function ()
    local codes = {}
    for p, c in utf8.codes(s) do codes[#codes + 1] = c end
    return table.concat(codes, ",")
  end))
  print(pcall(function ()
    local codes = {}
    for p, c in utf8.codes(s, true) do codes[#codes + 1] = c end
    return table.concat(codes, ",")
  end))
end
local iterator = utf8.codes("")
show(iterator("", 2), iterator("", -1), iterator("", math.mininteger), iterator("abc", 0), iterator("abc", "1"), iterator("abc", 1.5))
show(iterator("héllo", 2), iterator("héllo", 3))
try(utf8.codes)
try(utf8.codes, {})
try(iterator)

-- charpattern
local count = 0
for c in string.gmatch("日本語a-4\0éó", utf8.charpattern) do count = count + 1 end
show(count, string.match("\xC3\xA9x", "^" .. utf8.charpattern .. "$"), string.match("\xC3\xA9", "^" .. utf8.charpattern .. "$"))
