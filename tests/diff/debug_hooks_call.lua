-- call, return, tail call and count hooks; debug.gethook; transfer info;
-- hooks are off inside hooks; errors inside hooks
local function name (level)
  local info = debug.getinfo(level + 1, "nS")
  if not info then return "?" end
  return (info.name or info.what) .. "/" .. info.namewhat
end

-- event sequence with names
local events = {}
local function g1 (x) return x end
local function h (x) local f = g1; return f(x) end
local function outer (x) local y = h(x); return y end
debug.sethook(function (e, l) events[#events + 1] = e .. ":" .. tostring(l) .. ":" .. name(2) end, "cr")
outer(1)
print(math.max(1, 2))
debug.sethook()
print(table.concat(events, " "))

-- 'tail call' events and istailcall
events = {}
debug.sethook(function (e)
  events[#events + 1] = e .. ":" .. tostring(debug.getinfo(2, "t").istailcall)
end, "c")
h(false)
debug.sethook()
print(table.concat(events, " "))

-- a tail call to a native function: its return, then the caller's
events = {}
local function tailNative (x) return math.abs(x) end
debug.sethook(function (e) events[#events + 1] = e .. ":" .. name(2) end, "cr")
tailNative(-3)
debug.sethook()
print(table.concat(events, " "))

-- gethook
print(debug.gethook())
local function hookfn () end
debug.sethook(hookfn, "crl", 3)
local f, m, c = debug.gethook()
print(f == hookfn, m, c)
debug.sethook(hookfn, "", 0)
print(debug.gethook())
debug.sethook(hookfn, "x\0crl")
print(select(2, debug.gethook()))
debug.sethook(hookfn, "lrc", -5)
print(select(2, debug.gethook()))
debug.sethook(hookfn, "", 2^24 - 1)
print(select(3, debug.gethook()))
debug.sethook(nil)
print(debug.gethook())
print(getmetatable(debug.getregistry()._HOOKKEY).__mode)
print(pcall(debug.sethook, hookfn))
print(pcall(debug.sethook, 1, "c"))
print(pcall(debug.sethook, hookfn, "c", "x"))

-- count hooks
local a = 0
debug.sethook(function () a = a + 1 end, "", 1)
a = 0; for i = 1, 1000 do end
local c1 = a
debug.sethook(function () a = a + 1 end, "", 4)
a = 0; for i = 1, 1000 do end
local c4 = a
debug.sethook(function () a = a + 1 end, "", 4000)
a = 0; for i = 1, 1000 do end
debug.sethook()
print(c1, c4, a)

-- count and line hooks together
events = {}
debug.sethook(function (e, l) events[#events + 1] = e .. ":" .. tostring(l) end, "l", 3)
local s = 0
for i = 1, 3 do
  s = s + i
end
debug.sethook()
print(table.concat(events, " "))

-- arithmetic metamethods: the OP_MMBIN* is traced only when the metamethod runs
local mt = {__add = function (x, y) return 7 end}
local obj = setmetatable({}, mt)
a = 0
debug.sethook(function () a = a + 1 end, "", 1)
local r1 = 1 + 2
local r2 = obj + 2
debug.sethook()
print(a, r1, r2)

-- inspection of parameters and returned values (transfer information)
do
  local on = false
  local inp, out
  local function hook (event)
    if not on then return end
    local ar = debug.getinfo(2, "ruS")
    local t = {}
    for i = ar.ftransfer, ar.ftransfer + ar.ntransfer - 1 do
      local _, v = debug.getlocal(2, i)
      t[#t + 1] = tostring(v)
    end
    if event == "return" then out = table.concat(t, ",") else inp = table.concat(t, ",") end
  end
  debug.sethook(hook, "cr")
  on = true; math.sin(3); on = false
  print(inp, out)
  on = true; select(2, 10, 20, 30, 40); on = false
  print(inp, out)
  local function foo (a, ...) return ... end
  local function foo1 () on = not on; return foo(20, 10, 0) end
  foo1(); on = false
  print(inp, out)
  local function many (...) return ... end
  on = true; many(1, 2, 3, 4, 5, 6, 7, 8, 9, 10); on = false
  print(inp, out)
  local function none () end
  on = true; none(); on = false
  print(inp, out)
  debug.sethook()
end

-- a return hook can change the returned values
do
  local function two () local a, b = 1, 2; return a, b end
  debug.sethook(function ()
    local info = debug.getinfo(2, "nr")
    if info.name == "two" then
      debug.setlocal(2, info.ftransfer, "changed")
    end
  end, "r")
  local x, y = two()
  debug.sethook()
  print(x, y)
end

-- local variables seen from call, line and return hooks
do
  local function collect (level)
    local tab = {}
    for i = 1, math.huge do
      local n, v = debug.getlocal(level + 1, i)
      if not (n and string.find(n, "^[a-zA-Z0-9_]+$")) then break end
      tab[#tab + 1] = n .. "=" .. tostring(v)
    end
    return table.concat(tab, " ")
  end
  local function foo (a, b, ...)
    do local x, y, z end
    local c, d = 10, 20
    return
  end
  local seen = {}
  debug.sethook(function (e, l)
    if debug.getinfo(2, "n").name == "foo" then
      seen[#seen + 1] = e .. ":" .. collect(2)
    end
  end, "crl")
  foo(100, 200, 300)
  debug.sethook()
  print(table.concat(seen, " | "))
end

-- hooks are disabled while a hook runs; the hook's frame shows as "hook"
do
  local log = {}
  local function inner () return 1 end
  debug.sethook(function (e)
    local info = debug.getinfo(1, "n")
    log[#log + 1] = e .. ":" .. info.namewhat .. ":" .. tostring(info.name)
    inner()
    local tb = debug.traceback()
    log[#log + 1] = tostring(string.find(tb, "in hook '?'", 1, true) ~= nil)
  end, "c")
  inner()
  debug.sethook()
  print(table.concat(log, " "))
end

-- a call hook that sets a line hook, runs chunks and catches errors
do
  local X
  local obj = {}
  function obj:f (a, b, ...) local arg = {...}; local c = 13 end
  debug.sethook(function (e)
    assert(e == "call")
    load("XX = 12")()
    print(pcall(load("a='joao'+1")))
    debug.sethook(function (e, l)
      local f, m, c = debug.gethook()
      print(e, l == debug.getinfo(2, "l").currentline, m, c)
      debug.sethook(nil)
      local names = {}
      for i = 1, 10 do
        local n, v = debug.getlocal(2, i)
        if not (n and string.find(n, "^[a-zA-Z0-9_]+$")) then break end  -- temporaries hold stale values in C
        names[#names + 1] = n .. "=" .. type(v)
      end
      X = table.concat(names, " ")
    end, "l")
  end, "c")
  obj:f(1, 2, 3, 4, 5)
  print(X, XX, debug.gethook())
  XX = nil
end

-- an error raised by a hook propagates to the caller's protected call
do
  local function victim () return 1 end
  debug.sethook(function (e)
    if debug.getinfo(2, "f").func == victim then error("from hook") end
  end, "c")
  local ok, err = pcall(victim)
  debug.sethook()
  print(ok, err)
  -- hooks work again afterwards
  local count = 0
  debug.sethook(function () count = count + 1 end, "c")
  victim()
  debug.sethook()
  print(count)
end

-- a hook in a function called through a metamethod
do
  local log = {}
  local t = setmetatable({}, {__index = function (t, k) return k end})
  debug.sethook(function (e) log[#log + 1] = e .. ":" .. name(2) end, "cr")
  local v = t.key
  debug.sethook()
  print(table.concat(log, " "), v)
end

-- setting a hook inside a hook is visible after the outer hook returns
do
  local log = {}
  debug.sethook(function (e)
    debug.sethook(function (e2, l) log[#log + 1] = e2 .. ":" .. tostring(l) end, "l")
  end, "c")
  local function target ()
    local q = 1
    return q
  end
  target()
  debug.sethook()
  print(table.concat(log, " "))
end
