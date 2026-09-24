-- os.exit without 'close' ends the process without finalizers
setmetatable({}, {__gc = function () print("not printed") end})
print("exiting")
os.exit(true)
