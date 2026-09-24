-- the thread variants of getinfo, getlocal, setlocal, sethook, gethook, traceback
local function f (n)
  local depth = n
  if n > 0 then f(n - 1)
  else coroutine.yield(depth) end
end

local co = coroutine.create(f)
print(coroutine.resume(co, 2))
print(debug.traceback(co))
print(debug.traceback(co, "msg"))
print(debug.traceback(co, "msg", 1))
print(debug.traceback(co, nil, 3))
print(debug.traceback(co, nil, 40))
print(debug.traceback(co, {}) ~= nil, type(debug.traceback(co, {})))
for level = 0, 4 do
  local info = debug.getinfo(co, level, "Slnt")
  if info then
    print(level, info.short_src, info.currentline, info.what, info.name, info.namewhat, info.istailcall)
  else
    print(level, info)
  end
end
print(debug.getlocal(co, 1, 1), debug.getlocal(co, 1, 2), debug.getlocal(co, 1, 3))
print(debug.getlocal(co, 0, 1))
print(debug.setlocal(co, 1, 1, "changed"), debug.getlocal(co, 1, 1))
print(pcall(debug.getlocal, co, 10, 1))
print(pcall(debug.setlocal, co, 10, 1, 1))
print(debug.getlocal(co, f, 1), debug.getlocal(co, print, 1))
print(debug.getinfo(co, f, "S").linedefined, debug.getinfo(co, 10))
print(coroutine.resume(co))
print(coroutine.status(co), debug.traceback(co), debug.getinfo(co, 0))

-- a coroutine that has not started, and one dead by an error
local fresh = coroutine.create(function () end)
print(debug.traceback(fresh), debug.getinfo(fresh, 0), debug.getinfo(fresh, 1))
local function failing (i)
  if i == 0 then error("failed") end
  failing(i - 1)
end
local dead = coroutine.create(failing)
print(coroutine.resume(dead, 2))
print(debug.traceback(dead))
print(debug.traceback(dead, "again", 1))

-- hooks are per thread
local log = {}
local worker = coroutine.create(function (x)
  local a = 1
  coroutine.yield(debug.getinfo(1, "l").currentline)
  return a
end)
debug.sethook(worker, function (e, l) log[#log + 1] = e .. ":" .. tostring(l) end, "lcr")
print(debug.gethook(worker) ~= nil, select(2, debug.gethook(worker)), debug.gethook())
local _, line = coroutine.resume(worker, 10)
log[#log + 1] = "main"
print(coroutine.resume(worker))
print(table.concat(log, " "))
debug.sethook(worker)
print(debug.gethook(worker))

-- a hook set in the main thread does not trace a coroutine created before it
local traced = {}
local early = coroutine.create(function () local x = 1; coroutine.yield(); return x end)
debug.sethook(function (e, l) traced[#traced + 1] = e .. ":" .. tostring(l) end, "l")
coroutine.resume(early)
debug.sethook()
print(#traced > 0, table.concat(traced, " "))

-- new coroutines inherit the creating thread's hook (lua_newthread)
local inherited = {}
debug.sethook(function (e, l)
  inherited[#inherited + 1] = coroutine.running() and "co" or "main"
end, "c")
local child = coroutine.create(function () return 1 end)
debug.sethook()
print(debug.gethook(child))
coroutine.resume(child)
print(#inherited)

-- line numbers of a coroutine resumed from inside a C function
local function g (x) coroutine.yield(x) end
local function h (i)
  debug.sethook(function () end, "l")
  for j = 1, 3 do g(i + j) end
end
local wrapped = coroutine.wrap(h)
print(wrapped(10), pcall(wrapped), pcall(wrapped))
debug.sethook()
