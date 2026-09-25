-- lapi.c lua_error: an error object that is exactly the string "not enough
-- memory" is a memory error (LUA_ERRMEM), so no message handler runs and
-- coroutine.wrap adds no position. C functions raise with lua_error: error
-- (when no position is added), assert, luaL_error, coroutine.wrap, and
-- dofile (whose load errors become regular errors).

local function h(m) return "handled: " .. tostring(m) end
print("error level 0", xpcall(error, h, "not enough memory", 0))
print("error called from C", xpcall(error, h, "not enough memory"))
print("error level 1", xpcall(function() error("not enough memory") end, h))
print("error level 2, a C caller", xpcall(function() error("not enough memory", 2) end, h))
print("another message", xpcall(error, h, "other", 0))
print("longer message", xpcall(error, h, "not enough memory!", 0))
print("assert called from C", xpcall(assert, h, false, "not enough memory"))
print("assert called from Lua", xpcall(function() assert(false, "not enough memory") end, h))
print("wrap, level 0", xpcall(coroutine.wrap(function() error("not enough memory", 0) end), h))
print("wrap, called from Lua", xpcall(function() return coroutine.wrap(function() error("not enough memory", 0) end)() end, h))
print("wrap, other message", xpcall(function() return coroutine.wrap(function() error("x", 0) end)() end, h))
print("resume", coroutine.resume(coroutine.create(function() error("not enough memory", 0) end)))
local t = setmetatable({}, {__index = function() error("not enough memory", 0) end})
print("in a metamethod", xpcall(function() return t.x end, h))
print("an object whose __tostring says so", xpcall(error, h, setmetatable({}, {__tostring = function() return "not enough memory" end}), 0))
print("dofile, syntax error", xpcall(dofile, h, "modules/syntax_error.lua"))
print("pcall", pcall(error, "not enough memory"))
-- the __close of a to-be-closed variable sees the error; the handler does not
print("closing", xpcall(function()
  local x <close> = setmetatable({}, {__close = function(_, e) print("  __close got", e) end})
  error("not enough memory", 0)
end, h))
