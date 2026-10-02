-- print on the command line writes C's stdout directly (BaseLib::print's
-- fast path); embedded states and states with a budget take the port
-- (writeOutput, see EmbedRuntimeTest). Both write each value as it is
-- converted (lbaselib.c: luaL_tolstring, then lua_writestring), so what a
-- __tostring writes comes between the values, and the line ends with a
-- newline and a flush.

print()
print(nil, false, true, 1, -0.0, 2^63, "text", "")
local loud = setmetatable({}, {__tostring = function ()
  io.write("[io.write in __tostring]")
  print("print in __tostring")
  return "loud"
end})
print("before", loud, "after")
io.write("pending ")
print("print flushes what io.write left")
io.stdout:setvbuf("line")
print("a", "line", "buffered")
io.stdout:setvbuf("no")
print("un", "buffered")
io.stdout:setvbuf("full")
-- values longer than stdout's buffer, and longer than the size checked first
local big = string.rep("x", 70000)
print(#big, big:sub(1, 10), big)
print(big .. "y")
print(pcall(print, setmetatable({}, {__tostring = function () return 1 end})))
print(pcall(print, "first", setmetatable({}, {__tostring = function () error("in __tostring") end})))
print("done")
