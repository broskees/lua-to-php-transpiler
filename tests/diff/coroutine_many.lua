-- many coroutines: short-lived ones, suspended ones that become garbage
-- (freed without running any of their Lua code: no __close, no pcall
-- handler), and one left pending when the program ends

local finished = 0
for i = 1, 20000 do
  local co = coroutine.create(function (x) coroutine.yield(x); return x + 1 end)
  local _, a = coroutine.resume(co, i)
  local _, b = coroutine.resume(co)
  if a == i and b == i + 1 and coroutine.status(co) == "dead" then finished = finished + 1 end
end
print("finished", finished)

local sum = 0
for i = 1, 20000 do
  sum = sum + coroutine.wrap(function (x) return x * 2 end)(i)
end
print("wrap sum", sum)

-- suspended coroutines dropped as garbage: their pending __close
-- variables are never closed and their pcalls never see an error
local suspended = 0
for i = 1, 20000 do
  local co = coroutine.create(function ()
    local x <close> = setmetatable({}, {__close = function () print("closed garbage coroutine!") end})
    pcall(function ()
      local y <close> = setmetatable({}, {__close = function () print("closed in pcall!") end})
      coroutine.yield(i)
      print("resumed garbage coroutine!")
    end)
    print("pcall returned in garbage coroutine!")
  end)
  local _, v = coroutine.resume(co)
  if v == i then suspended = suspended + 1 end
end
collectgarbage()
print("suspended and dropped", suspended)

-- suspended coroutines that refer to themselves (cycles)
for i = 1, 5000 do
  local co
  co = coroutine.create(function () local me = co; coroutine.yield(me) end)
  coroutine.resume(co)
end
collectgarbage()
print("cycles dropped")

-- keeping many suspended coroutines alive, then finishing them
local alive = {}
for i = 1, 5000 do
  alive[i] = coroutine.wrap(function () local v = coroutine.yield(i); return v * 2 end)
  alive[i]()
end
local total = 0
for i = 1, 5000 do total = total + alive[i](i) end
print("kept alive", total)

-- a coroutine left pending at exit: nothing runs when the state goes away
_G.TO_SURVIVE = coroutine.wrap(function ()
  local x <close> = setmetatable({}, {__close = function () print("closed at exit!") end})
  coroutine.yield()
end)
_G.TO_SURVIVE()
print("end")
