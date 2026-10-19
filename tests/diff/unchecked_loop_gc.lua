-- The unchecked copy of a loop (FunctionEmitter::findUncheckedRegions)
-- continues in the checked one when Lua code it ran set a hook, but only
-- after all of the instruction's own work. OP_CONCAT's metamethod may set
-- a hook, and the collection step after the concatenation (lvm.c:
-- OP_CONCAT's checkGC after luaV_concat) must still run then: a finalizer
-- it is due to call runs there. Each case stops the collector, makes a
-- table with a finalizer garbage, and restarts the collector so that the
-- next allocation's step collects it. The metamethod's result is joined
-- to a prefix, a new string after the restart in both lua5.4 and ours, so
-- the step is due at the concatenation whatever else allocates. Each case
-- is a function of its own: a loop gets an unchecked copy only in a
-- function of one segment.

local function countHookSetByConcat ()
  collectgarbage()
  collectgarbage("stop")
  local done = false
  do local _ = setmetatable({}, {__gc = function () done = true end}) end
  local function hook () end
  local v = setmetatable({}, {
    __concat = function (a, b)
      collectgarbage("restart")
      debug.sethook(hook, "", 1)
      return "abc"
    end,
  })
  local r
  for i = 1, 1 do r = "<" .. v .. "x" end
  debug.sethook()
  print(done, r)
end

local function lineHookSetByConcatOfLargeString ()
  collectgarbage()
  collectgarbage("stop")
  local done = false
  do local _ = setmetatable({}, {__gc = function () done = true end}) end
  local piece = string.rep("x", 1100000)
  local hook = function () end
  local t = setmetatable({}, {
    __concat = function ()
      debug.sethook(hook, "l")
      collectgarbage("restart")
      return piece
    end,
  })
  local result
  for i = 1, 1 do result = "<" .. t .. t end
  debug.sethook()
  print(done, #result)
end

print("-- a __concat sets a count hook and restarts the collector")
countHookSetByConcat()
print("-- a __concat sets a line hook and returns a large string")
lineHookSetByConcatOfLargeString()

-- The same transfer after a collection step (a finalizer that sets a
-- hook) where the step is the instruction's last work: the line events
-- of the rest of the loop must be lua5.4's.

local function lineHookFinalizer (lines)
  return setmetatable({}, {__gc = function ()
    debug.sethook(function (event, line) lines[#lines + 1] = line end, "l")
  end})
end

local function stringJoin ()
  collectgarbage()
  collectgarbage("stop")
  local lines = {}
  do local _ = lineHookFinalizer(lines) end
  collectgarbage("restart")
  local s = "q"
  for i = 1, 3 do
    s = s .. "z"
  end
  debug.sethook()
  print(s, table.concat(lines, " "))
end

local function newTable ()
  collectgarbage()
  collectgarbage("stop")
  local lines = {}
  do local _ = lineHookFinalizer(lines) end
  collectgarbage("restart")
  local last
  for i = 1, 3 do
    last = {}
  end
  debug.sethook()
  print(type(last), table.concat(lines, " "))
end

local function constructor ()
  collectgarbage()
  collectgarbage("stop")
  local lines = {}
  do local _ = lineHookFinalizer(lines) end
  collectgarbage("restart")
  local last
  for i = 1, 3 do
    last = {1, 2,
      3}
  end
  debug.sethook()
  print(#last, table.concat(lines, " "))
end

local function closure ()
  collectgarbage()
  collectgarbage("stop")
  local lines = {}
  do local _ = lineHookFinalizer(lines) end
  collectgarbage("restart")
  local last
  for i = 1, 3 do
    last = function () return i end
  end
  debug.sethook()
  print(last(), table.concat(lines, " "))
end

print("-- a finalizer run by the step of a string join sets a line hook")
stringJoin()
print("-- a finalizer run by the step of OP_NEWTABLE sets a line hook")
newTable()
print("-- a finalizer run by the step of a constructor sets a line hook")
constructor()
print("-- a finalizer run by the step of OP_CLOSURE sets a line hook")
closure()
