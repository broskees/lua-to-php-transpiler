-- lstrlib.c: str_dump; load() of the dump gives an equivalent function
-- whose first upvalue is _ENV (the globals) and the others nil

local function show (...)
  local results = table.pack(...)
  local parts = {}
  for i = 1, results.n do
    local value = results[i]
    parts[i] = type(value) == "string" and string.format("%q", value) or tostring(value)
  end
  print(table.concat(parts, " "))
end

local function add (a, b) return a + b end
local dumped = string.dump(add)
show(type(dumped), dumped:sub(1, 4) == "\27Lua", #dumped > 20)
local reloaded = load(dumped)
show(reloaded(2, 3), reloaded(1.5, 2), pcall(reloaded, {}, 1))

-- stripped dumps drop debug information (names and lines)
local stripped = load(string.dump(add, true))
show(stripped(10, 20), #string.dump(add, true) < #dumped)
show(select(2, pcall(stripped, nil, 1)))
show(select(2, pcall(reloaded, nil, 1)))
show(string.dump(add, false) == dumped, string.dump(add, nil) == dumped, string.dump(add, 1) == string.dump(add, true))

-- dumps are deterministic and reload to the same bytes
show(string.dump(load(dumped)) == dumped, string.dump(load(string.dump(add, true)), true) == string.dump(add, true))

-- upvalues: the first becomes the global table, the others are nil
x = "global x"
local a, b = 1, 2
local function useUpvalues ()
  return x, a, b
end
show(useUpvalues())
local reloadedUpvalues = load(string.dump(useUpvalues))
show(pcall(reloadedUpvalues))
show(debug.getupvalue(reloadedUpvalues, 1), debug.getupvalue(reloadedUpvalues, 2), debug.getupvalue(reloadedUpvalues, 3))
local function onlyGlobals () return x end
show(load(string.dump(onlyGlobals))())
local function firstIsNotEnv () return a + 1, x end
show(pcall(load(string.dump(firstIsNotEnv))))
local custom = load(string.dump(onlyGlobals), "chunk", "b", {x = "custom x"})
show(custom())

-- nested functions, varargs, constants of every type
local function complex (...)
  local t = {n = select("#", ...), 1.5, "str", true, false, nil, 2^63, math.mininteger}
  local function inner (y) return y * 2 end
  return t.n, inner(t[1]), t[2], t[3], t[4], t[6], t[7], ...
end
show(load(string.dump(complex))("a", "b"))
show(load(string.dump(complex, true))("c"))

-- chunks loaded from text dump too; binary chunks respect the load mode
local chunk = load("local a = ... return a * 3", "=mychunk")
show(load(string.dump(chunk))(14))
show(load(string.dump(chunk), "name", "t"))
show(load("return 1", "name", "b"))

-- errors
show(pcall(string.dump, print))
show(pcall(string.dump, string.dump))
show(pcall(string.dump))
show(pcall(string.dump, {}))
show(pcall(string.dump, 1))
show(load(string.sub(dumped, 1, 10)))
show(load(string.sub(dumped, 1, -2)))
