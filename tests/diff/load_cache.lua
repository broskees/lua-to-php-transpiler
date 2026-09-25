-- load() keeps compiled chunks in a cache keyed by their bytes and chunk name
-- (LoadCache, in memory and optionally on disk): loading the same bytes again
-- must look exactly like a separate compile.

local function p(s) return string.format("%p", s) end
local long = string.rep("x", 50)
-- the same long string in two functions of the chunk
local source = "local a = '" .. long .. "'\nlocal function g() return '" .. long .. "' end\nreturn a, g(), ..."

local f1 = load(source)
local f2 = load(source)  -- the same bytes again
local a1, b1 = f1()
local a2, b2 = f2()
print(f1 ~= f2, a1 == a2, a1 == long)
print(p(a1) == p(b1), p(a2) == p(b2))  -- one string per chunk...
print(p(a1) ~= p(a2))                   -- ...and a new one per load
print(select("#", f2(1, 2, 3)), f2(1, 2, 3))

-- the chunk name is part of the key
print(pcall(load("error('x')", "=first")))
print(pcall(load("error('x')", "=second")))
print(pcall(load("error('x')", "=first")))
print(pcall(load("error('x')")))  -- the chunk name is the source itself
print(pcall(load("error('x')")))
print(pcall(load("\n\nerror('y')", "@file.lua")))
print(pcall(load("\n\nerror('y')", "@other/file.lua")))

-- syntax errors are not cached; the mode is checked on every load
print(load("x = = 1", "=bad"))
print(load("x = = 1", "=bad"))
print(load(source, "=moded", "b"))
print(load(source, "=moded", "t") ~= nil)
print(load(source, "=moded", "b"))
print(load(source, "=moded", "bt") ~= nil)

-- binary chunks
local dumped = string.dump(load(source, "=dumped"))
local d1, d2 = load(dumped, nil, "b"), load(dumped, nil, "b")
print(d1 ~= d2, select(2, d1()) == long)
print(p((d1())) == p(select(2, d1())), p((d1())) == p((d2())))
print(load(dumped, "=x", "t"))
print(string.dump(d1) == dumped, string.dump(d2) == dumped)
print(load(dumped:sub(1, -2), "=truncated"))
print(load(dumped:sub(1, -2), "=truncated"))

-- debug information comes with the cached chunk
for _ = 1, 2 do
  local f = load("\n\nlocal x = 1\nreturn function () return x end", "@cached.lua")()
  local info = debug.getinfo(f, "S")
  print(info.source, info.short_src, info.linedefined, info.lastlinedefined, debug.getupvalue(f, 1))
end

-- the chunk name is never one of the chunk's strings, even when equal to one
local name = string.rep("X", 50)
for i = 1, 2 do
  local f = load("return '" .. name .. "'", name)
  print(i, p(debug.getinfo(f, "S").source) == p(f()))
end

-- environments and upvalues belong to each load
local e1 = load("x = (x or 0) + 1; return x", "=env", "t", {x = 10})
local e2 = load("x = (x or 0) + 1; return x", "=env", "t", {x = 20})
print(e1(), e2(), e1(), e2())
local counter1 = load("local n = 0; return function () n = n + 1; return n end")()
local counter2 = load("local n = 0; return function () n = n + 1; return n end")()
print(counter1(), counter1(), counter2())

-- reader functions: the pieces are joined before the cache sees them
local function reader(pieces)
  local i = 0
  return function () i = i + 1; return pieces[i] end
end
print(load(reader{"return ", "'", long, "'"})() == long)
print(p(load(reader{"return ", "'", long, "'"})()) ~= p(load("return '" .. long .. "'")()))

-- many distinct chunks
local sum = 0
for i = 1, 300 do sum = sum + load("return " .. i .. " * 2")() end
print(sum)
