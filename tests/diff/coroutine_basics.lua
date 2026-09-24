-- coroutine.create/resume/yield/status/running/isyieldable/wrap:
-- value transfer, status transitions, identity of threads

local main, ismain = coroutine.running()
print(type(main), ismain, coroutine.isyieldable(), coroutine.isyieldable(main))
print(coroutine.status(main))
print(coroutine.resume(main))

local co
co = coroutine.create(function (a, b, ...)
  print("body starts with", a, b, select("#", ...), ...)
  local running, isMain = coroutine.running()
  print("running is co:", running == co, isMain, coroutine.status(co), coroutine.isyieldable())
  print("resume itself:", coroutine.resume(co))
  local c, d = coroutine.yield(a + b)
  print("first resume sent", c, d)
  print("yield nothing got", select("#", coroutine.yield()))
  local t = table.pack(coroutine.yield(nil, nil, 3))
  print("got n =", t.n, t[1], t[2], t[3])
  return "done", nil, 42
end)
print(type(co), coroutine.status(co), coroutine.isyieldable(co))
print(coroutine.resume(co, 1, 2, "x", nil))
print(coroutine.status(co), coroutine.isyieldable(co))
print(coroutine.resume(co, "c", "d"))
print(coroutine.resume(co, 7, 8, 9))
print(select("#", coroutine.resume(co, nil, nil, nil)))
print(coroutine.status(co))
print(coroutine.resume(co))
print(coroutine.resume(co, 1))
print(coroutine.status(co), coroutine.isyieldable(co))

-- a coroutine that never yields
co = coroutine.create(function (...) return select("#", ...), ... end)
print(coroutine.resume(co, 1, nil, 3, nil))
print(coroutine.status(co))

-- native functions as bodies
co = coroutine.create(print)
print(coroutine.resume(co, "printed by a native body"))
print(coroutine.status(co))
co = coroutine.create(coroutine.yield)
print(coroutine.resume(co, "yielded", "by", "yield"))
print(coroutine.status(co))
print(coroutine.resume(co, "returned", "by", "yield"))
print(coroutine.status(co))
co = coroutine.wrap(select)
print(co("#", 1, 2, 3))

-- status seen from inside: normal and running
local outer
outer = coroutine.create(function ()
  local inner = coroutine.create(function ()
    print("outer from inner:", coroutine.status(outer))
    print("main from inner:", coroutine.status(main))
    print("resume normal:", coroutine.resume(outer))
    print("resume main:", coroutine.resume(main))
    coroutine.yield("inner yielded")
  end)
  print(coroutine.resume(inner))
  print("inner from outer:", coroutine.status(inner))
end)
coroutine.resume(outer)
print(coroutine.status(outer))

-- wrap
local gen = coroutine.wrap(function (n)
  for i = 1, n do coroutine.yield(i, i * i) end
  return "end"
end)
print(gen(3)); print(gen()); print(gen()); print(gen())
print(pcall(gen))

-- yields in tail calls and recursion
local function tail(i) return coroutine.yield(i) end
local f = coroutine.wrap(function ()
  local sum = 0
  for i = 1, 5 do sum = sum + tail(i) end
  return sum
end)
local v = f()
while v <= 5 do v = f(v * 10) end
print("sum", v)

local function permgen(a, n)
  n = n or #a
  if n <= 1 then coroutine.yield(a)
  else
    for i = 1, n do
      a[n], a[i] = a[i], a[n]
      permgen(a, n - 1)
      a[n], a[i] = a[i], a[n]
    end
  end
end
for p in coroutine.wrap(function () permgen({"a", "b", "c"}) end) do
  print(table.concat(p))
end

-- sieve of chained coroutines (from coroutine.lua)
local function gen2(n)
  return coroutine.wrap(function () for i = 2, n do coroutine.yield(i) end end)
end
local function filter(p, g)
  return coroutine.wrap(function ()
    while true do
      local n = g()
      if n == nil then return end
      if n % p ~= 0 then coroutine.yield(n) end
    end
  end)
end
local primes = {}
local x = gen2(60)
while true do
  local n = x()
  if n == nil then break end
  primes[#primes + 1] = n
  x = filter(n, x)
end
print(table.concat(primes, " "))

-- upvalues of a coroutine's frame outlive it
local counter = coroutine.wrap(function ()
  local a = 10
  local function inc() a = a + 1; return a end
  while true do coroutine.yield(inc) end
end)
local inc = counter()
print(inc(), inc(), counter() == inc)
counter = nil
collectgarbage()
print(inc())

-- locals of a coroutine that died with an error stay reachable
local dead = coroutine.create(function ()
  local a = 10
  _G.F = function () a = a + 1; return a end
  error("x")
end)
print(coroutine.resume(dead))
print(coroutine.resume(dead, 1, 1, 1, 1, 1, 1, 1))
print(_G.F(), _G.F())
_G.F = nil

-- tostring and type
print(tostring(co):match("^thread: ") ~= nil, type(co))
