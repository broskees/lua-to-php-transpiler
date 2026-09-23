-- an uncaught error: message and traceback on stderr, exit status 1
print("before")
local function fails(x) return x.field end
local function calls() fails(nil) end
calls()
print("after")
