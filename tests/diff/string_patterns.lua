-- lstrlib.c pattern matching: find, match, gmatch, gsub, and every
-- malformed-pattern and capture error

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

-- find: plain searches, init semantics
show(string.find("", ""), string.find("alo", ""), string.find("", "", 1), string.find("", "", 2))
show(string.find("a\0o a\0o a\0o", "a\0o", 2), string.find("a\0a\0a\0a\0\0ab", "\0ab", 2))
show(string.find("1234567890123456789", "345", 4), string.find("1234567890123456789", ".45", -9))
show(string.find("abcdefg", "\0", 5, 1), string.find("alo(.)alo", "(.)", 1, 1))
show(string.find("abc", "b", -1), string.find("abc", "b", -100), string.find("abc", "", 4), string.find("abc", "", 5))
show(string.find("abc", "c", math.maxinteger), string.find("abc", "a", math.mininteger))
show(string.find("a.b", ".", 1, true), string.find("a.b", "%.", 1, false), string.find("a+b", "+", 1, 1))
show(string.find(12345, 34), string.find("x^y", "^y"), string.find("x^y", "^x"))

-- match: classes, quantifiers, anchors
local subjects = {"aaab", "aaa", "b", "", " \n isto e assim", "0alo alo", "alo xyzK", "  alo alo  "}
local patterns = {".*b", ".+a", ".?b", "a-", "^.-$", "%S+", "%s*$", "[a-z]*$", "[^%sa-z]",
                  "%x*", "%C+", "(%w+)K", "(%d*)K", "^%s*(.-)%s*$", "b*", "a$", "$", "^$", "%a+%s", "[%a%d]+"}
for _, subject in ipairs(subjects) do
  local line = {}
  for _, pattern in ipairs(patterns) do
    local results = table.pack(string.match(subject, pattern))
    for i = 1, results.n do results[i] = tostring(results[i]) end
    line[#line + 1] = table.concat(results, ",", 1, results.n)
  end
  print(string.format("%q", subject), table.concat(line, "|"))
end

-- every class letter, upper and lower case, over all bytes
local all = {}
for i = 0, 255 do all[#all + 1] = string.char(i) end
all = table.concat(all)
for _, class in ipairs{"a", "c", "d", "g", "l", "p", "s", "u", "w", "x", "z"} do
  local lower = string.gsub(all, "[^%" .. class .. "]", "")
  local upper = string.gsub(all, "%" .. class:upper(), "")
  print(class, #lower, #upper, string.format("%q", lower == upper and "" or (#lower < 100 and lower or "")))
end

-- sets
for _, pattern in ipairs{"[a-f]", "[a-]", "[]%%]", "[a%-z]", "[%^%[%-a%]%-b]", "[^%W]",
                          "[\200-\210]", "[%d-z]", "[z-a]", "[-]", "[^]]", "[%]]", "[a-c-e]"} do
  local matched = {}
  for c in string.gmatch(all, pattern) do matched[#matched + 1] = c end
  print(pattern, #matched, string.format("%q", table.concat(matched)))
end

-- captures: nested, position, back references
show(string.match("hello world", "((h)(e)(l+)o) (w)"))
show(string.match("0123456789", "(.+(.?)())"))
show(string.match("abc", "()a()b()c()"))
show(string.match("alo xo alo", "(%w+) (%w+) %1"))
show(string.match("==========", "^([=]*)=%1$"), string.match("=======", "^(=*)=%1$"))
show(string.find("hello", "(l)(l)"), string.find("hello", "()ll()"))
show(string.match("  x", "()"), string.match("abc", "()", 4), string.match("abc", "()", 5))

-- balance and frontier
show(string.gsub("(9 ((8))(\0) 7) \0\0 a b ()(c)() a", "%b()", ""))
show(string.gsub("alo 'oi' alo", "%b''", '"'), string.match("[[x]]", "%b[]"), string.match("((", "%b()"))
show(string.gsub("aaa aa a aaa a", "%f[%w]a", "x"), string.gsub("[[]] [][] [[[[", "%f[[].", "x"))
show(string.gsub("01abc45de3", "%f[%d]", "."), string.gsub("function", "%f[^\1-\255]", "."))
show(string.find("a", "%f[a]"), string.find("a", "%f[^%z]"), string.find("aba", "%f[%z]"), string.find("aba", "%f[a%z]"))
show(string.match("abc\0efg\0\1e\1g", "%b\0\1"), string.find("b$a", "$\0?"), string.match("ab\0\1\2c", "[\0-\2]+"))

-- gsub: string, table and function replacements, limits, anchors
show(string.gsub("hello world", "o", "0"), string.gsub("hello world", "o", "0", 1), string.gsub("abc", "", "-"))
show(string.gsub("abc", "%w", "%0%0"), string.gsub("abc", "%w", "%1%%"), string.gsub("alo alo", "()[al]", "%1"))
show(string.gsub("abc=xyz", "(%w*)(%p)(%w+)", "%3%2%1-%0"), string.gsub("a b cd", " *", "-"))
show(string.gsub("", "^", "r"), string.gsub("", "$", "r"), string.gsub("abc", "^", "r"), string.gsub("aaa", "^a", "b"))
show(string.gsub("abc", "b", 42), string.gsub("abc", "b", 1.5), string.gsub(123, 2, 9), string.gsub(123, "x", "y"))
show(string.gsub("alo alo", "(.)", {a = "AA", l = ""}), string.gsub("alo alo", "((.)(.?))", {al = "AA", o = false}))
show(string.gsub("alo alo", "().", {"x", "yy", "zzz"}), string.gsub("abc", "%w", {a = 1, b = 2.5}))
show(string.gsub("um (dois) tres (quatro)", "(%(%w+%))", string.upper))
show(string.gsub("abc", "%w", function (c) if c == "b" then return nil end return c .. c end))
show(string.gsub("abc", "(a)(b)(c)", function (...) return select("#", ...) .. table.concat({...}) end))
show(string.gsub("abc", "", function () return false end), string.gsub("x", "x", "%%"))
show(string.gsub("hello", "l", "L", 0), string.gsub("hello", "l", "L", -1), string.gsub("hello", "l", "L", 1.0))
local indexed = setmetatable({}, {__index = function (t, k) return k:upper() end})
show(string.gsub("a alo b hi", "%w%w+", indexed))

-- gmatch: iteration, init, empty matches
local function collect (...)
  local results = {}
  for a, b in string.gmatch(...) do results[#results + 1] = tostring(a) .. (b and ":" .. tostring(b) or "") end
  return table.concat(results, ",")
end
show(collect("first second word", "%w+"), collect("abcde", "()"), collect("xuxx uu ppar r", "()(.)%2"))
show(collect("13 14 10 = 11, 15= 16, 22=23", "(%d+)%s*=%s*(%d+)"), collect("10 20 30", "%d+", 3))
show(collect("11 21 31", "%d+", -4), collect("11 21 31", "%w*", 9), collect("11 21 31", "%w*", 10))
show(collect("a  \nbc\t\td", "()%s*()"), collect("abc", "^a"), collect("^a^a", "^a"), collect("abc", "", 100))
local iterator = string.gmatch("1 2 3", "%d")
show(iterator(), iterator(), iterator(), iterator(), iterator())
show(debug.getupvalue(string.gmatch("x", "y"), 1), debug.getupvalue(string.gmatch("x", "y"), 2))

-- errors
local function malform (pattern)
  local ok, message = pcall(string.find, "a", pattern)
  show(pattern, ok, message)
end
for _, pattern in ipairs{"(.", ".)", "[a", "[]", "[^]", "[a%]", "[a%", "%b", "%ba", "%", "%f", "%fx",
                          "(()", "%1", "(%1)", "(a)%2", "%0", "a%", "[%", "%f[a"} do
  malform(pattern)
end
try(string.gsub, "alo", ".", {a = {}})
try(string.gsub, "alo", ".", function () return {} end)
try(string.gsub, "alo", ".", "%2")
try(string.gsub, "alo", "(%0)", "a")
try(string.gsub, "alo", "(%1)", "a")
try(string.gsub, "alo", ".", "%x")
try(string.gsub, "alo", ".", "x%")
try(string.gsub, "alo", ".", true)
try(string.gsub, "alo", ".")
try(string.gsub, "alo", ".", "x", "y")
try(string.gsub, "alo", "(.", "x")
try(string.match, string.rep("a", 40), string.rep("(a)", 33))
try(string.find, string.rep("a", 300), string.rep("a?", 300) .. string.rep("a", 300))
try(string.gsub, string.rep("a", 10000) .. string.rep("b", 10000), ".-b")
try(string.find)
try(string.find, "a")
try(string.find, "a", {})
try(string.match, "a", "a", "x")
try(string.gmatch, nil, "x")
try(function () for _ in string.gmatch("abc", "(") do end end)

-- recursion through gsub
local function rev (s)
  return (string.gsub(s, "(.)(.+)", function (c, s1) return rev(s1) .. c end))
end
show(rev("abcdef"), rev(rev("abcdef")))
show(string.find(string.rep("a", 300000), "^a*.?$"), string.find(string.rep("a", 300000), "^a-.?$"))

-- '%p' of strings: equal short strings are one object; gsub without
-- changes returns its very subject
local short1, short2 = string.rep("a", 10), string.rep("aa", 5)
show(string.format("%p", short1) == string.format("%p", short2))
local long1, long2 = string.rep("a", 300), string.rep("a", 300)
show(string.format("%p", long1) == string.format("%p", long2), string.format("%p", long1) == string.format("%p", long1))
local same = string.gsub(long1, "b", "c")
show(string.format("%p", long1) == string.format("%p", same))
local copy = string.gsub(long1, ".", function (x) return x end)
show(copy == long1, string.format("%p", long1) == string.format("%p", copy))
