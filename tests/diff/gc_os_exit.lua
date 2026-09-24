-- os.exit(code, true) closes the state: pending finalizers run; plain
-- os.exit does not (see gc_os_exit_noclose.lua)
setmetatable({}, {__gc = function () print("finalized at exit") end})
local t <close> = setmetatable({}, {__close = function () print("closed first") end})
io.write("before exit; ")
os.exit(3, true)
