-- os.exit(status, true) closes the state (pending to-be-closed variables
-- run) and flushes buffered output; the status is truncated like C's exit
local t <close> = setmetatable({}, {__close = function (_, err) print("closed by os.exit", err) end})
io.write("pending io.write output; ")
io.stderr:write("to stderr\n")
local function nested ()
  local inner <close> = setmetatable({}, {__close = function () io.write("inner closed; ") end})
  os.exit(256 + 7, true)
end
nested()
print("not reached")
