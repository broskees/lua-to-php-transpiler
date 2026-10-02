-- While an error unwinds frames and their pending to-be-closed variables
-- are closed, C keeps the stack below the variable being closed (lfunc.c:
-- prepcallclosemth puts the error object right above it, ldo.c:
-- luaD_closeprotected): a collection inside one '__close' must not
-- finalize, or clear from a weak table, the variables still to be closed
-- nor the locals below them, while what was above (the frames that raised)
-- is garbage. The same in every unwind: pcall, xpcall, a pcall inside a
-- coroutine, an error in a '__close', coroutine.close, a wrapped coroutine
-- dying, and closing the state (os.exit(code, true)).
collectgarbage("stop")  -- only the collections asked for below

local function tracked(name)  -- a to-be-closed value that says when it is finalized
  return setmetatable({name = name}, {
    __gc = function (o) o.dead = true; print("gc " .. o.name) end,
    __close = function (o, err) print("close " .. o.name, o.dead, err) end,
  })
end

local function collecting(name)  -- a to-be-closed value whose '__close' collects garbage
  return setmetatable({}, {__close = function (_, err)
    collectgarbage()
    print("close " .. name .. " after a collection", err)
  end})
end

local weak = setmetatable({}, {__mode = "v"})

local function raise(message)
  local temp = tracked("temporary of the frame that raised")  -- garbage once its frame is unwound
  error(message, 0)
end

local function closesTwo(tag)
  local below = tracked("local below " .. tag)
  local x <close> = tracked("x " .. tag)
  weak[tag] = x
  local y <close> = collecting("y " .. tag)
  raise("boom " .. tag)
  print(below)
end

print("-- pcall")
print(pcall(closesTwo, "pcall"))
print("weak entry kept", weak.pcall ~= nil)
collectgarbage()

print("-- xpcall")
print(xpcall(closesTwo, function (message)
  collectgarbage()
  print("handler", message)
  return "handled " .. message
end, "xpcall"))
collectgarbage()

print("-- pcall inside a coroutine")
coroutine.wrap(function ()
  print(pcall(closesTwo, "coroutine"))
  print("weak entry kept", weak.coroutine ~= nil)
end)()
collectgarbage()

print("-- error in a __close")
local function closeFails()
  local x <close> = tracked("x before a failing __close")
  local y <close> = setmetatable({}, {__close = function ()
    local inner <close> = collecting("variable of the failing __close")
    error("error in __close", 0)
  end})
  local z <close> = collecting("z")
  error("first error", 0)
end
print(pcall(closeFails))
collectgarbage()
print(xpcall(closeFails, function (message)
  collectgarbage()
  return "handled " .. message
end))
collectgarbage()

print("-- coroutine.close of a suspended coroutine")
local co = coroutine.create(function ()
  local x <close> = tracked("x suspended")
  local y <close> = collecting("y suspended")
  coroutine.yield()
end)
coroutine.resume(co)
print(coroutine.close(co))
collectgarbage()

print("-- coroutine.close of a coroutine dead by an error")
co = coroutine.create(function ()
  local x <close> = tracked("x dead")
  local y <close> = collecting("y dead")
  error("died", 0)
end)
print(coroutine.resume(co))
print(coroutine.close(co))
collectgarbage()

print("-- a wrapped coroutine dying")
print(pcall(coroutine.wrap(function ()
  local x <close> = tracked("x wrapped")
  local y <close> = collecting("y wrapped")
  error("wrapped coroutine died", 0)
end)))
collectgarbage()

print("-- os.exit closes the state")
local function exiting()
  local traceback <close> = setmetatable({}, {__close = function ()
    print(debug.traceback("closing the state left only the base frame below:"))
  end})
  local x <close> = tracked("x at exit")
  local y <close> = collecting("y at exit")
  os.exit(0, true)
end
exiting()
