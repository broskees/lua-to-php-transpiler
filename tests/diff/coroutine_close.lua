-- coroutine.close and to-be-closed variables in coroutines; coroutine.wrap
-- closes a coroutine that died with an error

local function closable(name, action)
  return setmetatable({}, {__close = function (_, err)
    print("closing " .. name, err)
    if action then return action(err) end
  end})
end

-- dead and not started coroutines
local co = coroutine.create(print)
coroutine.resume(co, "run to the end")
print(coroutine.close(co))
print(coroutine.close(co))
co = coroutine.create(function () print("never runs") end)
print(coroutine.status(co), coroutine.close(co))
print(coroutine.status(co), coroutine.resume(co))

-- running and normal coroutines cannot be closed
print(pcall(coroutine.close, coroutine.running()))
local main = coroutine.running()
coroutine.wrap(function ()
  print(pcall(coroutine.close, main))
  print(pcall(coroutine.close, (coroutine.running())))
end)()
print(select(2, pcall(coroutine.close)))
print(select(2, pcall(coroutine.close, {})))

-- closing a suspended coroutine runs its pending __close with nil
co = coroutine.create(function ()
  local a <close> = closable("a")
  do
    local b <close> = closable("b")
    local c <close> = closable("c")
    coroutine.yield(1)
  end
  coroutine.yield(2)
end)
print(coroutine.resume(co))
print(coroutine.close(co))
print(coroutine.status(co), coroutine.resume(co))

-- pcall inside the coroutine does not see the close
co = coroutine.create(function ()
  local ok, err = pcall(function ()
    local x <close> = closable("x inside pcall")
    coroutine.yield("in pcall")
  end)
  print("never here", ok, err)
end)
print(coroutine.resume(co))
print(coroutine.close(co))

-- errors in __close while closing: later ones see the new error
co = coroutine.create(function ()
  local y <close> = closable("y", function (err) error(200, 0) end)
  local x <close> = closable("x", function (err) error(111, 0) end)
  coroutine.yield()
end)
coroutine.resume(co)
print(coroutine.close(co))
print(coroutine.status(co), coroutine.close(co))

-- string errors from __close get position information
co = coroutine.create(function ()
  local x <close> = closable("x", function () error("bad close") end)
  coroutine.yield()
end)
coroutine.resume(co)
print(coroutine.close(co))

-- a coroutine that died with an error: variables are closed with it
co = coroutine.create(error)
print(coroutine.resume(co, 100))
print(coroutine.close(co))
print(coroutine.close(co))

co = coroutine.create(function ()
  local x <close> = closable("x")
  error({code = 7})
end)
local ok, err = coroutine.resume(co)
print(ok, type(err), err.code, coroutine.status(co))
local ok2, err2 = coroutine.close(co)
print(ok2, err2 == err)

-- __close cannot yield while closing, and runs in the closed coroutine
co = coroutine.create(function ()
  local x <close> = setmetatable({}, {__close = function ()
    print("running in co:", coroutine.running() == co, coroutine.isyieldable())
    print(pcall(coroutine.yield, 1))
    print(coroutine.status(co), pcall(coroutine.close, co))
    print(coroutine.resume(co))
  end})
  coroutine.yield()
end)
coroutine.resume(co)
print(coroutine.close(co))

-- the message handler of a pending xpcall is dropped by the close
co = coroutine.create(function ()
  local clo <close> = setmetatable({}, {__close = function () error(134, 0) end})
  xpcall(coroutine.yield, function () return "XXX" end)
end)
coroutine.resume(co)
print(coroutine.close(co))

-- wrap: an error closes the coroutine, then propagates with position
local w = coroutine.wrap(function ()
  local x <close> = closable("x in wrap")
  error("wrapped error")
end)
print(pcall(w))
print(pcall(w))

w = coroutine.wrap(function ()
  local x <close> = closable("x in wrap", function () error("error in close") end)
  error({})
end)
print(pcall(w))

w = coroutine.wrap(function ()
  local x <close> = closable("x", function () error(setmetatable({}, {__tostring = function () return "object" end})) end)
  error("string")
end)
local ok3, err3 = pcall(w)
print(ok3, tostring(err3))

-- a wrap calling itself from its __close (bug in 5.4.1)
local selfcall
selfcall = coroutine.wrap(function ()
  local x <close> = setmetatable({}, {__close = function () return print(pcall(selfcall)) end})
  error(111)
end)
print(pcall(selfcall))
print(pcall(selfcall))

-- __close yielding on normal block exit is fine inside a coroutine
w = coroutine.wrap(function ()
  do
    local x <close> = setmetatable({}, {__close = function ()
      coroutine.yield("yield from __close")
      print("__close resumed")
    end})
  end
  return "after block"
end)
print(w())
print(w())

-- <close> versus pcall in coroutines
local function foo()
  local x <close> = closable("foo's x")
  error(43)
end
co = coroutine.create(function () return pcall(foo) end)
print(coroutine.resume(co))

-- recovering from errors in __close metamethods across a yield
local track = {}
local function h(o)
  local hv <close> = o
  return 1
end
local function bar()
  local x <close> = closable("x", function (msg) track[#track + 1] = msg or false; error(20, 0) end)
  local y <close> = closable("y", function (msg) track[#track + 1] = msg or false; return 1000 end)
  local z <close> = closable("z", function (msg) track[#track + 1] = msg or false; error(10, 0) end)
  coroutine.yield(1)
  h(closable("h", function (msg) track[#track + 1] = msg or false; error(2, 0) end))
end
co = coroutine.create(pcall)
print(coroutine.resume(co, bar))
print(coroutine.resume(co))
print(coroutine.status(co), table.unpack(track))
