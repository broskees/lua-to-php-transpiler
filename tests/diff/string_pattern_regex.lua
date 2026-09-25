-- Lua patterns that bin/lua translates to PCRE (src/Lib/String/PatternRegex.php):
-- one case per translation rule, where PCRE's defaults differ from Lua's.
-- Every call runs twice: the first use of a pattern on a short subject runs
-- the port of lstrlib.c's matcher, the next one its PCRE translation.

local function show (...)
  local results = table.pack(...)
  local parts = {}
  for i = 1, results.n do
    local value = results[i]
    parts[i] = type(value) == "string" and string.format("%q", value) or tostring(value)
  end
  return table.concat(parts, " ")
end

local function check (f, ...)
  local first = show(pcall(f, ...))
  local second = show(pcall(f, ...))
  print(first)
  if second ~= first then print("second use differs: " .. second) end
end

local function collect (s, pattern, init)
  local results = {}
  for a, b in string.gmatch(s, pattern, init) do
    results[#results + 1] = tostring(a) .. (b ~= nil and ":" .. tostring(b) or "")
  end
  return table.concat(results, ",")
end

local all = {}
for i = 0, 255 do all[#all + 1] = string.char(i) end
all = table.concat(all)

-- bytes, not UTF-8; '.' matches every byte, "\n" and "\0" included
check(string.gsub, all, ".", "")
check(string.find, "a\nb\0c", "a.b.c")
check(string.match, "\195\169t\195\169", "^(.)(.)t")
check(string.gsub, "caf\195\169 na\195\175ve", "[\128-\255]+", "?")

-- C-locale classes over all bytes: %w has no '_', %s has "\v", %p is ASCII punctuation
for _, class in ipairs{"a", "c", "d", "g", "l", "p", "s", "u", "w", "x", "z",
                       "A", "C", "D", "G", "L", "P", "S", "U", "W", "X", "Z"} do
  local _, count = string.gsub(all, "%" .. class, "")
  local matched = {}
  for c in string.gmatch(all, "%" .. class) do matched[#matched + 1] = c end
  print(class, count, #matched < 100 and string.format("%q", table.concat(matched)) or "")
end
check(string.match, "a_b", "%w+"); check(string.find, "x\vy", "%s"); check(string.gsub, "a\128_!~", "%p", "P")
-- '%' before a non-alphanumeric byte is that byte; before another letter, the letter itself
check(string.find, "a.b", "%."); check(string.find, "q%Q", "%q%%%Q"); check(string.find, "yey", "%e")
check(string.gsub, "a+*?b", "%+%*%?", "-"); check(string.match, "\0x\0", "%z(.)%Z*")

-- anchors: '^' only first (not in gmatch), '$' only last, and '$' never before a final "\n"
check(string.find, "a^b", "^b"); check(string.find, "a^b", "a^b"); check(string.gsub, "^a^a", "^^a", "x")
check(collect, "^a^a", "^a"); check(string.gsub, "a$b$", "$b", "."); check(string.match, "a$b", "a$*b")
check(string.find, "abc\n", "c$"); check(string.find, "abc\n", "\n$"); check(string.match, "abc\n", "(.-)$")
check(string.gsub, "line\n", "$", "!"); check(string.gsub, "", "^$", "e"); check(string.find, "$", "$$")
check(string.find, "ab", "^b", 2); check(string.find, "ab", "^a", 2); check(string.gsub, "aaa", "^a", "b", 5)

-- sets: ']' first, '^' only first, escapes, '-' ranges only between two items, inverted ranges
check(string.gsub, all, "[]]", "#"); check(string.gsub, "a]^-b", "[^]]", "#"); check(string.gsub, "a^b", "[b^]", "#")
check(string.gsub, "-a-z]", "[a-]", "#"); check(string.gsub, "-a-z", "[-a]", "#"); check(string.gsub, "a-%d7", "[a-%d]", "#")
check(string.gsub, all, "[z-a]", "#"); check(string.gsub, "za", "[z-a]*", "#"); check(string.gsub, "a-b", "[a--]", "#")
check(string.gsub, "[%]", "[%[%]]", "#"); check(string.gsub, "abc-", "[%a-c]", "#"); check(string.gsub, "\0\1\2\3", "[\0-\2]", "#")
check(string.gsub, all, "[^%w%s]", ""); check(string.gsub, all, "[%W_]", ""); check(string.gsub, all, "[\200-\210%d]", "")

-- quantifiers: greedy '*', '+', '?' back off one byte at a time; '-' is lazy
check(string.match, "aaab", "(a*)(a*)(a-)b"); check(string.match, "aaab", "(a-)(a*)b"); check(string.match, "aaa", "(a?)(a?)(a-)$")
check(string.match, "<a><b>", "<(.-)>"); check(string.match, "<a><b>", "<(.*)>"); check(string.match, "xaaay", "x(a+)(a*)y")
-- after a suffix, a '*', '+', '?' or '-' is a literal byte (never PCRE's lazy or possessive)
check(string.find, "a*", "a**"); check(string.find, "aa?", "a+?"); check(string.find, "a-", "a--"); check(string.find, "b+", "a*+")
check(string.find, "*a", "*a"); check(string.find, "(*)", "(*)"); check(string.find, "x-", "(-)"); check(string.find, "a??", "a??")

-- captures: positions, nesting, back references (to a position capture: never matches)
check(string.match, "hello world", "((h)(e)(l+)o) (w)"); check(string.find, "abc", "()a()b()c()")
check(string.match, "xyzxyz", "(x(y)z)%1%2?"); check(string.match, "abab", "()(ab)%1"); check(string.find, "aa", "()%1")
check(string.gsub, "abcabc", "(b)(c)", "%2%1%0"); check(string.gsub, "abc", "()b()", "%1-%2"); check(collect, "abcd", "()(.)")
check(string.match, string.rep("a", 32), string.rep("(a)", 32)); check(string.match, string.rep("a", 33), string.rep("(a)", 33))
check(string.gsub, "abc", "(b", "x"); check(string.find, "abc", "(b"); check(string.gsub, "abc", "(b", "%1")

-- errors are raised only when the matcher reaches them
check(string.find, "abc", "x%"); check(string.find, "ab", "b%"); check(string.find, "abc", "x[a"); check(string.find, "xa", "x[a")
check(string.find, "abc", "x(%1)"); check(string.find, "xa", "x(%1)"); check(string.find, "abc", "x)"); check(string.find, "x", "x)")
check(string.find, "abc", "x%b"); check(string.find, "x", "x%b("); check(string.find, "abc", "x%f"); check(string.find, "x", "x%fa")
check(string.find, "abc", "x%0"); check(string.find, "x", "x%0"); check(string.find, "x", "x%3"); check(string.find, "x", "(x)%2")

-- frontiers: the byte before the subject and the one after it are '\0'
check(string.gsub, "THE (quick) fox", "%f[%a]%a+", "W"); check(string.gsub, "aaa", "%f[%z]", "."); check(string.gsub, "aaa", "%f[^%z]", ".")
check(string.find, "a", "%f[a%z]"); check(string.find, "a\0a", "%f[%z]", 2); check(string.gsub, "a\0b\0", "%f[%Z]", "|")
check(string.find, "ab", "%f[%a]", 2); check(string.find, " ab", "%f[%a]", 2); check(string.find, "ab", "%f[b]", 2)
check(string.gsub, "01abc45de3", "%f[%d]", "."); check(string.gsub, "function", "%f[^\1-\255]", ".")
check(string.find, "", "%f[%z]"); check(string.find, "", "%f[^%z]"); check(string.gsub, all, "%f[\0-\255]", "!")

-- balanced strings: first balance point, never backtracked into; '%bxx'
check(string.gsub, "(9 ((8))(\0) 7) \0\0 a b ()(c)() a", "%b()", ""); check(string.match, "((a)", "%b()")
check(string.match, "f(a(b)c)d(e", "(%b())(.*)"); check(string.match, "(a)(b)", "%b()$"); check(string.match, "(a)*", "%b()*")
check(string.gsub, "''a'b''", "%b''", "<%0>"); check(string.match, "x(a)(b)", "(%b())%b()"); check(string.find, "(a)b", "%b()%1")
check(string.gsub, ")(()(", "%b)(", "#"); check(string.match, "abc\0efg\0\1e\1g", "%b\0\1"); check(string.find, "(", "%b()")
-- PCRE gives up on deep nesting (JIT stack or recursion limit): the port takes over, even mid-call
local deep = string.rep("(", 200000) .. string.rep(")", 200000)
check(function () return #string.match(deep .. deep, "%b()%b()") end)
check(function () return string.find("x" .. deep .. "x", "%b()x") end)
check(function () local r, n = string.gsub("(a)" .. deep .. "(b)", "%b()", "x"); return r, n end)
check(function () return collect("(a)" .. deep .. "(b)()", "()%b()()") end)

-- empty matches: gsub and gmatch never match empty where the last match ended
check(string.gsub, "abc", "%w*", "-"); check(string.gsub, "a b", " *", "-"); check(string.gsub, "abc", "", "-")
check(collect, "abc", "()"); check(collect, "a,b,,c", "([^,]*)"); check(collect, "ab  cd", "%a*")
check(string.gsub, "hello world", "o*", "0", 3); check(collect, "10 20 30", "%d*", 3); check(collect, "xyz", "", 4)

-- lstrlib.c's recursion limit (MAXCCALLS 200): each matched 'a?' nests a match()
for _, n in ipairs{150, 189, 190, 191, 198, 199, 200, 201} do
  local subject = string.rep("a", n)
  check(function () return #string.match(subject, string.rep("a?", n)) end)
  check(function () return string.find(subject, string.rep("(a?)", n // 3)) end)
end
-- patterns over 512 bytes are left to the port
check(string.find, string.rep("ab", 300) .. "x", string.rep("ab", 300) .. "x")
check(string.gsub, string.rep("ab", 400), string.rep("[ab]", 300), "#")

-- the same pattern on long subjects (translated at once) and short ones
local text = string.rep("key=value; ", 20)
check(string.gsub, text, "(%w+)=(%w+)", "%2=%1"); check(string.gsub, "k=v", "(%w+)=(%w+)", "%2=%1")
check(collect, text, "(%w+)=()"); check(string.find, text, "value;%s*$"); check(string.find, text, "(%w+)=", 100)
check(string.gsub, text, "%f[%w]%w+", function (w) return #w end)
check(string.gsub, text, "%w+", {key = "K", value = false}, 3)
