-- lua_close (lstate.c: close_state, lgc.c: luaC_freeallobjects): when the
-- script ends, every object still marked for finalization is finalized,
-- in reverse order of marking; objects marked while closing are not
collectgarbage("stop")  -- no automatic collections before the end
warn("@on")
local mt = {}
mt.__gc = function (o)
  print("closing", o.name)
  if o.name == "second" then
    setmetatable({name = "created while closing"}, mt)  -- not finalized
    _G.resurrected = o
  end
  if o.name == "third" then error("error while closing") end
end
setmetatable({name = "first"}, mt)
_G.kept = setmetatable({name = "second"}, mt)
local third = setmetatable({name = "third"}, mt)
setmetatable({name = "fourth"}, {__gc = function (o)
  print("fourth sees", collectgarbage("count"), collectgarbage())
end})
print("end of script")
