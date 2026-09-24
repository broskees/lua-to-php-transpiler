-- debug.getmetatable/setmetatable (per-type metatables), getregistry, get/setuservalue
local debug = debug

-- tables: raw metatable, ignoring __metatable protection
local protected = setmetatable({}, {__metatable = "locked", tag = "mt"})
print(getmetatable(protected), debug.getmetatable(protected).tag)
local replacement = {tag = "new"}
print(debug.setmetatable(protected, replacement) == protected, getmetatable(protected).tag)
print(debug.setmetatable(protected, nil) == protected, getmetatable(protected), debug.getmetatable(protected))
print(debug.getmetatable({}), debug.getmetatable("x").__index == string)

-- numbers: arithmetic stays primitive, other events use the metatable
print(debug.setmetatable(10, {__index = function (n, k) return k .. n end,
                              __call = function (n, x) return n * x end,
                              __len = function (n) return n + 1 end,
                              __concat = function (a, b) return "cat" end}))
local n = 5
print(n.foo, (2.5).bar, n(3), #n, 1 + n, n .. "s", n .. {})
print(debug.getmetatable(1) == debug.getmetatable(2.5), getmetatable(7).__len ~= nil)
print(debug.setmetatable(1, nil), debug.getmetatable(1), pcall(function () return n.foo end))

-- booleans and nil
debug.setmetatable(true, {__index = function (b, k) return k .. tostring(b) end, __lt = function (a, b) return not a and b end})
print((true).x, (false).y, false < true, true < false, pcall(function () return true <= false end))
debug.setmetatable(false, nil)
print(pcall(function () return (true).x end))
print(debug.setmetatable(nil, {__index = function (_, k) return "nil." .. k end}))
local nothing
print(nothing.field, debug.getmetatable(nil) ~= nil)
debug.setmetatable(nil, nil)
print(pcall(function () return nothing.field end))

-- functions share one metatable
debug.setmetatable(print, {__index = {kind = "function"}, __concat = function (a, b) return "fc" end})
local function f() end
print(f.kind, print.kind, f .. 1, debug.getmetatable(f) == debug.getmetatable(print))
debug.setmetatable(f, nil)
print(pcall(function () return f.kind end))

-- __name is used for type names in messages
debug.setmetatable(10, {__name = "MyNumber"})
print(pcall(table.concat, 1))
print(pcall(setmetatable, 1, {}))
debug.setmetatable(10, nil)

-- argument errors
print(pcall(debug.setmetatable, 1))
print(pcall(debug.setmetatable, 1, 2))
print(pcall(debug.setmetatable))
print(pcall(debug.getmetatable))
print(debug.getmetatable(nil), debug.getmetatable(false))

-- the registry holds the loaded table and the globals
local registry = debug.getregistry()
print(type(registry), registry._LOADED == package.loaded, registry[2] == _G, registry._LOADED.string == string)

-- user values: only full userdata have them
print(debug.getuservalue(1), debug.getuservalue({}), debug.getuservalue(nil, 2), debug.getuservalue(print))
print(pcall(debug.getuservalue))
print(pcall(debug.getuservalue, 1, "x"))
local light = debug.upvalueid(f, 1) or debug.upvalueid(function () return print end, 1)
print(type(light), debug.getuservalue(light))
print(pcall(debug.setuservalue, 3, {}))
print(pcall(debug.setuservalue, nil, {}))
print(pcall(debug.setuservalue, light, {}))
print(pcall(debug.setuservalue, {}, 1))
print(pcall(debug.setuservalue, 1))

-- full userdata without user values (io files)
print(debug.setuservalue(io.stdin, 10), debug.getuservalue(io.stdin, 10))
print(select('#', debug.getuservalue(io.stdin)), debug.getuservalue(io.stdin, 1), debug.getuservalue(io.stdin, 0))
print(debug.getmetatable(io.stdin) == getmetatable(io.stdout), debug.getmetatable(io.stdin).__name)
local fileMetatable = debug.getmetatable(io.stdout)
print(debug.setmetatable(io.stdout, nil) == io.stdout, getmetatable(io.stdout), pcall(tostring, io.stdout) and "tostring ok")
debug.setmetatable(io.stdout, fileMetatable)
print(getmetatable(io.stdout) == fileMetatable)
