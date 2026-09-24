-- lstrlib.c: len, sub, reverse, lower, upper, rep, byte, char and their
-- argument errors

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

-- len
show(string.len(""), string.len("\0\0\0"), string.len(12.5), ("abc"):len(), #"xyz")
try(string.len)
try(string.len, {})

-- sub: positions are clipped; negative means from the end
local s = "123456789"
for _, range in ipairs{{2, 4}, {7}, {7, 6}, {0, 0}, {-10, 10}, {-1}, {-4}, {-6, -4},
                       {mini, -4}, {mini, maxi}, {mini, mini}, {3, maxi}, {10}, {0}, {2.0, 3}} do
  show(range[1], range[2], s:sub(range[1], range[2]))
end
try(string.sub, s)
try(string.sub, s, 1.5)
try(string.sub, s, "x")
try(string.sub, s, 1, {})
show(string.sub("\0a\0b", 2, 3), s:sub("2", "3"))

-- reverse, lower, upper (C locale: only ASCII letters change)
show(string.reverse(""), string.reverse("\0\1\2\3"), ("abc"):reverse())
show(string.lower("\0ABCc%$\xC0\xE9"), string.upper("ab\0c\xE9z{"))
show(string.upper(123), string.lower(1.5))
try(string.upper)
try(string.lower, true)

-- rep
show(string.rep("teste", 0), string.rep("ab", 3), string.rep("", 10), string.rep("x", -5))
show(string.rep("teste", 0, "xuxu"), string.rep("teste", 1, "xuxu"), string.rep("\1\0\1", 2, "\0\0"))
show(string.rep("", 10, "."), string.rep("a", 3, 12), ("ab"):rep(2, ""))
try(string.rep, "aa", 1 << 30)
try(string.rep, "a", 1 << 30, ",")
try(string.rep, "aa", maxi // 2 + 10)
try(string.rep, "", maxi // 2 + 10, "aa")
show(#string.rep("", 100000), #string.rep("a", 100000), #string.rep("", 1000, ""))
try(string.rep)
try(string.rep, "x")
try(string.rep, "x", 2.5)
try(string.rep, "x", 2, {})

-- byte
show(string.byte("a"), string.byte("\xe4"), string.byte(""), string.byte("hi", -3))
show(string.byte("hi", 3), string.byte("hi", 9, 10), string.byte("hi", 2, 1))
show(string.byte("\0\0alo\0x", -1), string.byte("ba", 2), string.byte("\n\n", 2, -1))
show(string.byte("hello", 1, -1))
show(string.byte("hello", -100, 100))
show(string.byte("hello", 0), string.byte("hello", mini, maxi))
try(string.byte, "x", 1.5)
try(string.byte, "abc", 1, string.rep("x", 1))

-- char
show(string.char(), string.char(0, 255, 0), string.char(72, 105, "33"))
try(string.char, 256)
try(string.char, -1)
try(string.char, maxi)
try(string.char, mini)
try(string.char, 65, "x")
try(string.char, 65, nil)
show(string.char(string.byte("\xe4l\0u", 1, -1)))

-- method calls report 'self' errors
try(function () return ("x"):rep("a") end)
try(function () return ("x").rep() end)
try(function () local t = {rep = string.rep}; return t:rep(2) end)

-- string metatable
show(getmetatable("").__index == string, ("x"):upper(), #getmetatable("").__index.format("%d", 3))
