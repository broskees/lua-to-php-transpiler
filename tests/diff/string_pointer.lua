-- '%p' of strings (lapi.c: lua_topointer) tells string objects apart.
-- Short strings (up to LUAI_MAXSHORTLEN = 40 bytes) are interned: equal
-- ones are one object. A long string is a new object each time one is
-- made, except that the lexer makes a chunk's equal strings one object
-- (llex.c: luaX_newstring), while a binary chunk loads each constant as a
-- new string (lundump.c: loadStringN). Runs under both opcache settings
-- (tests/unit/StringIdentityTest.php).

local function address (s) return string.format("%p", s) end
local function same (a, b) return address(a) == address(b) end

-- short strings are interned
print("short", same(string.rep("a", 10), string.rep("aa", 5)), same("x" .. 1, "x1"))

-- equal long constants of one chunk are one object, however they are used
local long1 <const> = "01234567890123456789012345678901234567890123456789"
local long2 <const> = "01234567890123456789012345678901234567890123456789"
local long3 = "01234567890123456789012345678901234567890123456789"
local function returnsConstant () return long1 end
local function returnsUpvalue () return long3 end
local function returnsLiteral ()
  return "01234567890123456789012345678901234567890123456789"
end
local constructed = {"01234567890123456789012345678901234567890123456789",
                     key = "01234567890123456789012345678901234567890123456789"}
local assigned = {}
assigned.field = "01234567890123456789012345678901234567890123456789"   -- OP_SETFIELD with a constant
assigned[1] = "01234567890123456789012345678901234567890123456789"   -- OP_SETI with a constant
globalLong = "01234567890123456789012345678901234567890123456789"   -- OP_SETTABUP with a constant
print("constants", same(long1, long2), same(long1, long3), same(long1, returnsConstant()),
      same(long1, returnsUpvalue()), same(long1, returnsLiteral()))
print("stored constants", same(long1, constructed[1]), same(long1, constructed.key),
      same(long1, assigned.field), same(long1, assigned[1]), same(long1, globalLong))
local function nested ()
  return function () return "01234567890123456789012345678901234567890123456789" end
end
print("nested", same(long1, nested()()))

-- long strings made at run time are new objects
local concatenated = "0123456789" .. "0123456789012345678901234567890123456789"
print("run time", concatenated == long1, same(concatenated, long1),
      same(string.rep("a", 300), string.rep("a", 300)))

-- the same long string keeps its address
local kept = string.rep("b", 100)
print("kept", same(kept, kept), same(kept, ({kept})[1]), same(kept, (select(1, kept))))

-- string.gsub returns its very subject when nothing was substituted
local subject = string.rep("a", 100)
print("gsub", same(subject, (string.gsub(subject, "b", "c"))),
      same(subject, (string.gsub(subject, ".", {x = "y"}))),
      same(subject, (string.gsub(subject, ".", function () return nil end))),
      same(subject, (string.gsub(subject, ".", function (x) return x end))))

-- every compilation has its own long strings
local source = 'return "0123456789012345678901234567890123456789-long-constant"'
local firstChunk, secondChunk = load(source), load(source)
local fromFirst, fromSecond = firstChunk(), secondChunk()
print("two loads", fromFirst == fromSecond, same(fromFirst, fromSecond), same(fromFirst, firstChunk()))

-- a binary chunk loads each long constant as a new string
local sharingSource = [[
  local s = "0123456789012345678901234567890123456789-shared-constant"
  return function () return s end,
         function () return "0123456789012345678901234567890123456789-shared-constant" end
]]
local fromSource = {load(sharingSource)()}
print("source chunk", same(fromSource[1](), fromSource[2]()))
local fromBinary = {load(string.dump(load(sharingSource)))()}
print("binary chunk", fromBinary[1]() == fromBinary[2](), same(fromBinary[1](), fromBinary[2]()),
      same(fromBinary[1](), fromBinary[1]()))
