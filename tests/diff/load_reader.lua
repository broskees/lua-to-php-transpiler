-- load(reader): lua_load reads the chunk through a ZIO (lzio.c), which calls
-- the reader only when the lexer (or undump, for a binary chunk) needs more
-- bytes. So the reader is called as often and in the same order as in C, a
-- syntax error stops it at once, and a binary chunk is read only up to its end.

-- a reader of the pieces in a list (a function in it is called for its piece),
-- counting its calls
local function reader(pieces)
  local state = {calls = 0}
  state.read = function()
    state.calls = state.calls + 1
    local piece = pieces[state.calls]
    if type(piece) == "function" then return piece() end
    return piece
  end
  return state
end

local function show(...)
  local values = table.pack(...)
  for i = 1, values.n do values[i] = tostring(values[i]):gsub("0x%x+", "ADDRESS") end
  return table.concat(values, " ", 1, values.n)
end

-- load the pieces (with an environment only if given); prints the reader's
-- calls, then what the chunk returns when called with 20 and 1, or the error
local function try(name, pieces, mode, env)
  local r = reader(pieces)
  local f, err
  if env == nil then
    f, err = load(r.read, "=" .. name, mode)
  else
    f, err = load(r.read, "=" .. name, mode, env)
  end
  if f then
    print(name, r.calls, "returns", show(pcall(f, 20, 1)))
  else
    print(name, r.calls, "error", err)
  end
end

-- syntax errors stop the reading where the lexer finds them
try("first piece", {"! ", "never read"})
try("second piece", {"x = 1 ", "! ", "never read"})
try("third piece", {"local a ", "= 1 ", "+ * 2", "never read"})
try("after a lookahead", {"x", " = 1 ", "y = { a ", "= 1, b = 2 ", ") ", "never read"})
try("unfinished string", {"return 'abc", "def", "\nghi'"})
try("unfinished long string", {"return [[abc", "def", nil})
try("unfinished long comment", {"--[==[ comment ", "]] still ", "]=] comment"})
try("at the end", {"return 1 +"})
try("bad escape", {'return "a\\', 'q"', "never read"})
try("malformed number", {"return 0x", "1p", "+", "z"})

-- a reader that keeps returning text with an error ends at the error
local endless = 0
print("endless", load(function() endless = endless + 1 return "! " end, "=endless"))
print("endless calls", endless)
local spaces = 0
print("endless spaces", load(function() spaces = spaces + 1 if spaces > 1000 then return "!" end return "  " end))
print("endless spaces calls", spaces)

-- errors of the reader itself
try("reader error", {"x = ", function() error("reader failed") end, "never read"})
try("reader error value", {"x = ", function() error({}) end})
try("error after a syntax error", {"x = = ", function() error("never called") end})
try("false", {"return 1", false, "never read"})
try("table", {{}})
try("a number is a string", {"return ", 42, " + 1"})
try("integer and float", {"return ", 1, 2.5, nil})

-- empty pieces end the chunk (lzio.c: luaZ_fill), as nil does
try("nil at once", {})
try("empty at once", {""})
try("empty piece", {"return 1", "", " + 1"})
try("nil piece", {"return 2", nil, " + 1"})

-- tokens across pieces
try("tokens across pieces", {"ret", "urn", " 'a", "b' .", ". [", "[c", "d]] .. 0", "x1", "F", nil})
try("one byte at a time", {"r", "e", "t", "u", "r", "n", " ", "4", "2"})
try("long string pieces", {"return [==[", "]]", "]=]", "]==", "]"})
try("first line", {"\n\n", "return debug.getinfo(1, 'l').currentline"})

-- the environment argument
try("env", {"return x"}, "t", {x = "from env"})

-- mode: the first byte decides, as soon as the first piece is read
try("text in mode b", {"return 1", "never read"}, "b")
try("empty in mode b", {}, "b")
local dumped = string.dump(function(a, b) return a * 2 + (b or 0), "dumped" end)
try("binary in mode t", {dumped, "never read"}, "t")

-- binary chunks: read up to their last byte, never further
local bytes = {}
for i = 1, #dumped do bytes[i] = dumped:sub(i, i) end
local r = reader(bytes)
local f = load(r.read, "=bytes", "b")
print("binary in bytes", r.calls == #dumped, f(20, 2))
local withJunk = {dumped, "junk"}
r = reader(withJunk)
f = load(r.read)
print("binary and junk", r.calls, f(1))
bytes[#bytes + 1] = "junk"
r = reader(bytes)
f = load(r.read)
print("binary in bytes and junk", r.calls == #dumped, f(2))
local halves = {dumped:sub(1, 20), dumped:sub(21)}
r = reader(halves)
f = load(r.read)
print("binary halves", r.calls, f(3))
try("truncated binary", {dumped:sub(1, 10), dumped:sub(11, 30)})
try("truncated binary bytes", {"\27", "L", "u", "a", "T"})
try("not a binary chunk", {"\27", "Lux"})
local empties = {dumped:sub(1, 5), "", dumped:sub(6)}
try("binary and an empty piece", empties)

-- the reader runs inside the parser: at its C-call depth (each nested
-- expression counts), not yieldable
local function probe(n)  -- can n more C levels (pcalls) run?
  if n == 0 then return true end
  local ok, deeper = pcall(probe, n - 1)
  return ok and deeper
end
local nested = "return " .. ("("):rep(180) .. "1" .. (")"):rep(180)
local position = 0
local depths = {}
f = load(function()
  position = position + 1
  depths[#depths + 1] = probe(20) and "+" or "-"
  return nested:sub(position, position)
end, "=nested")
print("nested", f and f(), table.concat(depths))
print("yield", coroutine.resume(coroutine.create(function()
  return load(function() coroutine.yield() end)
end)))
print("yield across", coroutine.wrap(function()
  return pcall(load, function() coroutine.yield() end)
end)())

-- a reader may load other chunks, with readers of their own
local inner
try("load in a reader", {"return ", function()
  inner = load(reader({"return 'inner'"}).read)
  return "'outer', "
end, function() return "'" .. inner() .. "'" end})

-- the same pieces again: the loads that succeed may come from the cache
for round = 1, 3 do
  try("again " .. round, {"local a, b = ...\n", "return a + (b or 1), ", "'piece", "s'"})
  try("again binary " .. round, bytes, "b")
end
