-- __gc finalizers (lgc.c: luaC_checkfinalizer, separatetobefnz, GCTM):
-- marking at setmetatable time, order, resurrection, interaction with weak
-- tables, errors as warnings. Automatic collections are stopped so only the
-- explicit collectgarbage() calls run finalizers.
collectgarbage("stop")

local log = {}
local function note (s) log[#log + 1] = s end
local function flush (title)
  print(title, table.concat(log, " "))
  log = {}
end

-- run once, in reverse order of marking
for i = 1, 5 do
  setmetatable({}, {__gc = function () note(i) end})
end
collectgarbage()
flush("reverse order")
collectgarbage()
flush("only once")

-- only a metatable that has __gc when setmetatable is called marks the object
local mt = {}
local late = setmetatable({}, mt)
mt.__gc = function () note("late") end
late = nil
collectgarbage()
flush("__gc added later")

local marked = setmetatable({}, {__gc = function () note("marked") end})
getmetatable(marked).__gc = function () note("replaced") end
marked = nil
collectgarbage()
flush("__gc replaced")

local removed = setmetatable({}, {__gc = function () note("removed") end})
getmetatable(removed).__gc = nil
removed = nil
collectgarbage()
flush("__gc removed")

-- __gc = true (or any value) marks; a non-callable one is an error
local notFunction = setmetatable({}, {__gc = true})
notFunction = nil
collectgarbage()
flush("__gc = true")

-- resurrection: the object is usable after its finalizer, and not
-- finalized again unless marked again
local saved
do
  local o = setmetatable({name = "phoenix"}, {__gc = function (o) saved = o; note("first") end})
end
collectgarbage()
flush("resurrected " .. saved.name)
saved = nil
collectgarbage()
flush("second collection")

local again = 0
do
  local gcmt = {}
  gcmt.__gc = function (o)
    again = again + 1
    note("again" .. again)
    if again < 3 then setmetatable(o, gcmt) end
  end
  setmetatable({}, gcmt)
end
collectgarbage(); collectgarbage(); collectgarbage(); collectgarbage()
flush("re-marked")

-- objects being finalized are removed from weak values before the
-- finalizer runs and from weak keys only after it
local weakValues = setmetatable({}, {__mode = "v"})
local weakKeys = setmetatable({}, {__mode = "k"})
do
  local o = setmetatable({}, {__gc = function (o)
    note("value " .. tostring(weakValues[1]))
    note("key " .. tostring(weakKeys[o]))
  end})
  weakValues[1] = o
  weakKeys[o] = "still there"
end
collectgarbage()
flush("weak tables during __gc")
collectgarbage()
print("weak tables after", next(weakValues), next(weakKeys))

-- an object referenced by a finalized object survives this cycle
local weakChild = setmetatable({}, {__mode = "v"})
do
  local child = {}
  weakChild[1] = child
  setmetatable({child = child}, {__gc = function (o) note(tostring(o.child == weakChild[1])) end})
end
collectgarbage()
flush("child during __gc")

-- errors in finalizers become warnings; the collection goes on
warn("@on")
setmetatable({}, {__gc = function () note("after error") end})
setmetatable({}, {__gc = function () error("boom") end})
setmetatable({}, {__gc = function () error({}) end})
setmetatable({}, {__gc = function () error(42) end})
setmetatable({}, {__gc = function () note("before error") end})
collectgarbage()
flush("errors")
warn("@off")

-- inside a finalizer the collector cannot run
local results
setmetatable({}, {__gc = function ()
  results = table.pack(collectgarbage(), collectgarbage("count"), collectgarbage("step"),
                       collectgarbage("isrunning"), collectgarbage("incremental"))
end})
collectgarbage()
print("collectgarbage inside __gc", results.n, results[1], results[2], results[3], results[4], results[5])

-- finalizers of objects only reachable from an unreachable coroutine
do
  local co = coroutine.create(function ()
    local o = setmetatable({}, {__gc = function () note("from coroutine") end})
    coroutine.yield()
  end)
  coroutine.resume(co)
end
collectgarbage()
flush("suspended coroutine")

do
  local co = coroutine.create(function ()
    local o = setmetatable({}, {__gc = function () note("from dead coroutine") end})
  end)
  coroutine.resume(co)
end
collectgarbage()
flush("dead coroutine")

-- a finalizer sees its object's fields and metatable
local seen
setmetatable({x = 10}, {__gc = function (o) seen = o.x + #getmetatable(o).list end, list = {1, 2}})
collectgarbage()
print("fields", seen)

-- debug.setmetatable marks for finalization too (lapi.c: lua_setmetatable)
debug.setmetatable({}, {__gc = function () note("debug.setmetatable") end})
collectgarbage()
flush("debug.setmetatable")

-- file handles have finalizers: an unreachable open file is flushed and
-- closed by the collector (liolib.c: f_gc)
local name = os.tmpname()
do
  local f = io.open(name, "w")
  f:write("written through a dropped handle")
end
collectgarbage()
local reader = io.open(name)
print("dropped file", reader:read("a"))
reader:close()
os.remove(name)

-- finalizers run when the collector runs by itself
collectgarbage("restart")
local finished = false
setmetatable({}, {__gc = function () finished = true end})
local i = 0
repeat i = i + 1; local t = {} until finished
print("automatic tables", finished)
finished = false
setmetatable({}, {__gc = function () finished = true end})
repeat i = i + 1; local s = tostring(i) .. tostring(i) until finished
print("automatic strings", finished)
finished = false
setmetatable({}, {__gc = function () finished = true end})
repeat local f = function () return i end until finished
print("automatic closures", finished)
