-- os.exit(false) exits with EXIT_FAILURE without closing the state
local t <close> = setmetatable({}, {__close = function () print("must not run") end})
io.write("buffered output survives os.exit")
os.exit(false)
