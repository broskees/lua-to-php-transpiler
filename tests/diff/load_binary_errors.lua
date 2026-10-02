-- malformed binary chunks: sizes and counts are unsigned (lundump.c:
-- loadUnsigned checks each 7-bit group against the limit before it shifts it
-- in), and a long string is allocated before it is read; error messages
-- name the chunk as a C string (up to an embedded '\0')

local dumped = string.dump(load("return 42"), true)
-- bytes 1..32: the header and the main function's upvalue count; 33: the
-- size of its source (stripped: 0, one byte); 34: linedefined (0)
assert(dumped:byte(33) == 0x80 and dumped:byte(34) == 0x80)

local function withSource(sizeBytes)  -- the source size replaced by these bytes
  return dumped:sub(1, 32) .. sizeBytes .. dumped:sub(34)
end
local function withLinedefined(intBytes)
  return dumped:sub(1, 33) .. intBytes .. dumped:sub(35)
end

-- sizes (lundump.c: loadSize, limit MAX_SIZET)
print("2^70", load(withSource("\1" .. ("\0"):rep(9) .. "\128"), "=overflow"))
print("2^64", load(withSource("\2" .. ("\0"):rep(8) .. "\128"), "=overflow"))
print("2^63 + 1", load(withSource("\1" .. ("\0"):rep(8) .. "\129"), "=huge"))
print("2^64 - 1", load(withSource("\1" .. ("\127"):rep(8) .. "\255"), "=overflow"))
print("2^64 - 129", load(withSource("\1" .. ("\127"):rep(7) .. "\126\255"), "=huge"))
print("2^62", load(withSource("\64" .. ("\0"):rep(7) .. "\128"), "=huge"))
print("2^56 + 1", load(withSource("\1" .. ("\0"):rep(7) .. "\129"), "=huge"))
print("1000", load(withSource("\7\232"), "=short"))
print("41", load(withSource("\169"), "=short"))
print("1", load(withSource("\129"), "=empty source"))
print("truncated size", load(dumped:sub(1, 32) .. "\1\0\0", "=truncated"))

-- ints (lundump.c: loadInt, limit INT_MAX)
print("int 2^35", load(withLinedefined("\1\0\0\0\0\128"), "=int"))
print("int 2^31 - 1", load(withLinedefined("\7\127\127\127\255"), "=int"))
print("int 2^31 - 129", load(withLinedefined("\7\127\127\126\255"), "=int"))
print("int 2^28", load(withLinedefined("\1\0\0\0\128"), "=int"))
local f = load(withLinedefined("\7\127\127\126\255"), "=int")
print("linedefined", f and debug.getinfo(f, "S").linedefined)
-- counts: the code of the main function (after linedefined, lastlinedefined,
-- numparams, is_vararg and maxstacksize)
local function withCodeCount(intBytes)
  return dumped:sub(1, 38) .. intBytes .. dumped:sub(40)
end
print("code count", dumped:byte(39) & 0x7f)
print("code 2^31", load(withCodeCount("\8\0\0\0\128"), "=code"))
print("code 2^24", load(withCodeCount("\8\0\0\128"), "=code"))

-- chunk names are C strings
for _, name in ipairs({"=x\0y", "@x\0y", "x\0y", "\0x", "=\0x", "\27\0x"}) do
  local _, message = load("\27Lua", name)
  print(string.format("%q", message))
  _, message = load("\27Lux", name)
  print(string.format("%q", message))
end
print(string.format("%q", select(2, load("\27Lua"))))
print(string.format("%q", select(2, load("\27Lua\0", nil))))
print(string.format("%q", select(2, load("x =", "=x\0y"))))
print(string.format("%q", select(2, load(function() return nil end, "=x\0y", "b"))))
