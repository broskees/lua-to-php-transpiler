-- uncaught non-string error objects
print("start")
error(setmetatable({}, {__tostring = function () return "custom error object" end}))
