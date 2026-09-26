-- Chunks too heavy to compile inline (Emitter::INLINE_WEIGHT_MAXIMUM): the
-- code outside loops of their heaviest functions is compact, one Op call
-- per instruction (FunctionEmitter::emitCompact), which must do exactly
-- what the inline code does: results, metamethods, errors and their
-- positions and variable names, hooks, calls, to-be-closed variables.
-- Each chunk below is the body after 4200 lines of "_ = 0" (one
-- instruction each), so it starts at line 4201.

local filler = string.rep("_ = 0\n", 4200)

local function run(name, body, ...)
  local chunk = assert(load(filler .. body, "=" .. name))
  print(name, pcall(chunk, ...))
end

run("numbers", [[
local x, y, f, z = 7, -3, 2.5, 0.0
z = -z
local r = {x + 1, x - 1, 1 + x, x - 0, x * 2, 2 * x, x % 3, x % -3, y % 3, y // 2, x // 2, x / 2, x ^ 2,
  x & 3, x | 8, x ~ 1, x << 2, x >> 1, 1 << x, 256 >> x, x << -1, x >> 70,
  f + 1, f - 1, f * 2, f % 2, f // 1, f % -1.5, f ^ 0.5, 3 - f, 3 / f,
  math.maxinteger + 1, math.mininteger - 1, math.mininteger * -1, math.mininteger // -1, math.mininteger % -1,
  -x, -f, ~x, 2^53 + 1, "10" + 1, "3" * "4", 3.0 | 0}
local s = {}
for i = 1, #r do s[i] = string.format("%s:%s", math.type(r[i]), tostring(r[i])) end
return table.concat(s, " "), string.format("%g %g %g %g", z - 0, z + 0, z - 1, z * 1)
]])

run("comparisons", [[
local i, f, big, s = 1, 1.0, math.maxinteger, "b"
local long = string.rep("x", 50)
return i == f, i == 1, f == 1, f == 1.5, big + 0.0 == big, big == 2^63, i < 1.5, f <= 1, i > 0, f >= 2,
  i < -1, f > -1, s < "c", s <= "b", s == "b", long == string.rep("x", 50), nil == false, not nil, not 0
]])

run("tests", [[
local a = nil or 5
local b = false and 1
local c = 1 and nil
local d = (a > 3) or "no"
local e = not (b or c)
if a == 5 and not b then a = a + 1 else a = a - 1 end
local g = 0 < a and a < 10 and "in" or "out"
return a, b, c, d, e, g
]])

run("metamethods", [[
local mt = {}
local function name(v) return type(v) == "table" and "t" or tostring(v) end
for _, e in ipairs{"add", "sub", "mul", "div", "mod", "pow", "unm", "idiv", "band", "bor", "bxor",
    "shl", "shr", "bnot", "concat", "len", "lt", "le", "call"} do
  mt["__" .. e] = function(a, b) return e .. "(" .. name(a) .. "," .. name(b) .. ")" end
end
mt.__eq = function() return true end
mt.__index = function(_, k) return "index(" .. name(k) .. ")" end
mt.__newindex = function(_, k, v) rawset(_, "set", name(k) .. "=" .. name(v)) end
local t, u = setmetatable({}, mt), setmetatable({}, mt)
local r = {t + 1, 1 + t, t - 1, 1 - t, t - 0, t * 2.5, 2 * t, t / 2, t % 3, t ^ 2, 2 ^ t, t // 2,
  t & 1, 1 & t, t | 1, t ~ 1, t << 1, 1 << t, t >> 1, 1 >> t, t + t, t - "x", -t, ~t, t .. "x", "x" .. t,
  #t, t.foo, t[1], t[2.5], t(1)}
t.bar = 5
r[#r + 1] = t.set
r[#r + 1] = tostring(t == u) .. tostring(t ~= u) .. tostring(t < 1) .. tostring(1 < t) .. tostring(t <= 1)
  .. tostring(t > 1) .. tostring(t >= 1.0) .. tostring(t > 1.0) .. tostring(t < u)
return table.concat(r, " ")
]])

for i, body in ipairs{
  "local t; return t.x",
  "local t = {}; return t.x.y",
  "return undefined_global.x",
  "local s = {}; return s + 1",
  "return #undefined_global",
  "local a = {}; return a < 1",
  "return 1 < 'x'",
  "local u = {}; u.x = 1 % 0",
  "return 1 // 0",
  "local f; f()",
  "return ('x'):bad()",
  "local a = {} .. 'x'",
  "local t = setmetatable({}, {__index = function(t, k) error('deep ' .. k, 2) end}); return t.key",
  "local t = {}; t[nil] = 1",
  "local t = {}; t[0/0] = 1",
  "return -{}",
  "return ~1.5",
  "return 1 & 1.5",
  "return 2^53 | 0",
  "for i = 1, 'x' do end",
} do
  run("error" .. i, body)
end

run("hooks", [[
local lines, count = {}, 0
debug.sethook(function(event, line) lines[#lines + 1] = line end, "l")
local a = 1
local b = a + 1
if b > 1 then a = 2 else a = 3 end
local t = {a, b, c = a .. b}
debug.sethook()
debug.sethook(function() count = count + 1 end, "", 1)
local x = a + b * 2 - 1
if x == 4 or x > 100 then x = x // 2 end
local y = t.c .. x
debug.sethook()
return table.concat(lines, " "), count, x, y
]])

run("getinfo", [[
local function where() local info = debug.getinfo(2, "l"); return info.currentline end
local function locals()
  local names = {}
  local i = 1
  while true do
    local n, v = debug.getlocal(2, i)
    if not n then break end
    names[#names + 1] = n .. "=" .. tostring(v)
    i = i + 1
  end
  return table.concat(names, ",")
end
local p, q = 10, "q"
local here = where()
return here, locals(), where()
]])

run("calls", [[
local function f(...) return ... end
local t = {f(1, 2, 3)}
local u = {f(f(4, 5))}
local n = select("#", f(nil, nil))
local v = {...}
local w = {..., "last"}
local s = string.format("%d %s", f(7, "x"))
local r = ("ab"):rep(3, "-")
return #t, #u, n, #v, #w, s, r, table.unpack(t)
]], "a", "b", "c")

run("closing", [[
local log = {}
do
  local x <close> = setmetatable({}, {__close = function(_, e) log[#log + 1] = "closed " .. tostring(e) end})
  local y <const> = 5
  log[#log + 1] = "body " .. y
end
local ok, err = pcall(function()
  local z <close> = setmetatable({}, {__close = function(_, e) log[#log + 1] = "closed on " .. tostring(e) end})
  error("boom", 0)
end)
local counter = 0
local function inc() counter = counter + 1; return counter end
inc(); inc()
return table.concat(log, "; "), ok, err, counter
]])

run("gc", [[
local weak = setmetatable({}, {__mode = "k"})
local key = {}
weak[key] = true
local finalized = false
local obj = setmetatable({}, {__gc = function() finalized = true end})
obj = nil
key = nil
collectgarbage()
local n = 0
for _ in pairs(weak) do n = n + 1 end
return n, finalized, collectgarbage("count") > 0
]])
