-- collectgarbage options and results (lbaselib.c: luaB_collectgarbage,
-- lapi.c: lua_gc). lua.c starts the collector in generational mode.
print(math.type(collectgarbage("count")), collectgarbage("count") > 0)
print(collectgarbage(), collectgarbage("collect"))
print(collectgarbage("isrunning"))
print(collectgarbage("stop"), collectgarbage("isrunning"))
print(collectgarbage("step"), collectgarbage("isrunning"))  -- a step does not restart it
print(collectgarbage("restart"), collectgarbage("isrunning"))

-- modes: each call returns the previous one
print(collectgarbage("incremental"), collectgarbage("incremental"))
print(collectgarbage("generational"), collectgarbage("generational", 10, 50))
print(collectgarbage("incremental", 100, 200, 10))

-- steps: in incremental mode a big step completes a cycle
print(collectgarbage("step", 0) ~= nil, collectgarbage("step", 100000))
local steps = 0
repeat steps = steps + 1 until collectgarbage("step") or steps > 1e6
print("cycle completed", steps <= 1e6)
collectgarbage("generational")
print("generational step", collectgarbage("step"), collectgarbage("step", 100000))
collectgarbage("incremental")

-- compat options: the previous value, stored like C (value / 4 in a byte)
print(collectgarbage("setpause", 100), collectgarbage("setpause", 0x7ffffffe), collectgarbage("setpause", -5),
      collectgarbage("setpause"), collectgarbage("setpause", 200))
print(collectgarbage("setstepmul", 3), collectgarbage("setstepmul", 400), collectgarbage("setstepmul", 100))

-- bad options
print(pcall(collectgarbage, "bogus"))
print(pcall(collectgarbage, 1))
print(pcall(collectgarbage, "step", "x"))
print(pcall(collectgarbage, "setpause", {}))

-- "count" goes down when big garbage is collected, up while the
-- collector is stopped
collectgarbage(); collectgarbage()
local before = collectgarbage("count")
local big = {}
for i = 1, 10000 do big[i] = {i} end
local grown = collectgarbage("count")
big = nil
collectgarbage()
local after = collectgarbage("count")
print("count", grown > before + 100, after < grown - 100)
local s = string.rep("x", 1 << 20)
print("big string", collectgarbage("count") > after + 1000)
s = nil
collectgarbage()
print("big string collected", collectgarbage("count") < after + 100)
collectgarbage("stop")
local x = collectgarbage("count")
repeat local t = {} until collectgarbage("count") > x + 1000
print("stopped", collectgarbage("isrunning"))
collectgarbage("restart")
