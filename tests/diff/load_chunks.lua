-- load: strings, readers, environments, modes, syntax errors
local f = load("return 1 + 1")
print(f())
print(load("syntax error here"))
print(load("x = ", "=custom"))
print(load("x = ", "@file.lua"))
print(load("return ...", "chunk")(1, 2, 3))
local env = {y = 5}
local withEnv = load("y = y + 1; return y", "env chunk", "t", env)
print(withEnv(), env.y, y)
print(load("return 1", "c", "b"))
print(pcall(load, "return 1", "c", "x"))
local pieces = {"return ", "'from", " reader'"}
local i = 0
print(load(function () i = i + 1; return pieces[i] end)())
print(load(function () return nil end))
print(load(function () return {} end))
print(pcall(load, 42))
print(load(42))
local noEnvironment = load("return x", "noenv", "t", nil)
print(pcall(noEnvironment))
local counter = 0
local generated = {}
for k = 1, 200 do
  generated[k] = load("return " .. k .. " * 2")
end
for k = 1, 200 do counter = counter + generated[k]() end
print(counter)
for _ = 1, 500 do assert(load("local a = 1; return a"))() end
print(load("return function (a, b) return a + b end")()(3, 4))
print(load("local t = {...}; return #t", "=varargs")(1, 2, 3))
print(select('#', load("return")()))
print(pcall(load("error('inside loaded chunk')", "=loaded")))
print(pcall(load("local x = nil; return x.y", "@virtual.lua")))
print(load("return 'a' .. \n\n 'b' +", "=multiline"))
print(load("\27Lua junk"))
print(load("\27Lua junk", "binary chunk", "t"))
print(dofile == nil, loadfile("definitely/missing/file.lua"))
