-- Calls, method calls and varargs in every position.
local function v(...)
  local a, b = ...
  local c = ...
  local t1 = {...}
  local t2 = {..., 1}
  local t3 = {1, ...}
  local t4 = {1, (...)}
  local n = select('#', ...)
  local s = {...}, ...
  print(...)
  print(..., 1)
  print(1, ...)
  print((...))
  print(v(...))
  local x = v(...) + 1
  return ...
end

local function w(a, b, ...)
  if a then return a, ... end
  if b then return (...) end
  return w(b, a, ...)
end

local function m(obj, ...)
  obj:method()
  obj:method(1, 2)
  obj.field:method "str"
  obj.field.sub:method {1, 2}
  obj["x"]:method(obj:other())
  local r = obj:m1():m2():m3()
  return obj:tail(...)
end

local function tails(f, g)
  return f()
end

local function nontail(f)
  return (f())
end

local function many(f)
  return f(), f(), f()
end

local function retlist(f)
  return 1, 2, f()
end

local function calls(f)
  f()
  f(1)
  f "string"
  f [[long]]
  f {1, 2, 3}
  f(f(f(1)))
  f(1, f())
  f(f(), 1)
  local a, b, c = f()
  local d, e = f(), f()
  a, b, c = f(), 1
  a, b = 1
  a = f(), f()
end

local t = {}
function t.a.b.c:method(x) return self, x end
function t.a.b.c.func(x) return x end
function t:colon(...) return self, ... end
function globalfunc() end
local function localfunc() return localfunc end
return v, w, m, tails, nontail, many, retlist, calls, localfunc
