-- Fast paths of a method lookup on a string (s:byte(...)): the string
-- metatable's '__index' table answers it (the fast path) or the lookup
-- goes through the whole '__index' chain (the slow path). Both must
-- compute what lua5.4 computes.
local function show(...)
  local parts = {}
  for i = 1, select("#", ...) do
    parts[#parts + 1] = tostring((select(i, ...)))
  end
  print(table.concat(parts, " "))
end

-- the fast path: the default string metatable's string library table
show(("abc"):byte(2))
show(("abc"):sub(2))
show(("Hello"):upper())

-- the slow path: a method the '__index' table does not have
show(pcall(function() return ("abc"):nosuchmethod() end))

-- a value that is false is present (nil is the only absent value)
local defaultMetatable = debug.getmetatable("")
debug.setmetatable("", { __index = { byte = false, greet = function() return "hi" end } })
local s = "x"
show(s:greet())
show(pcall(function() return s:byte() end))

-- '__index' as a function: the fast path does not apply
debug.setmetatable("", { __index = function(t, k) return "from-function:" .. k end })
show(s.anything)

-- a chain: the key is only in the table behind '__index'
debug.setmetatable("", {
  __index = setmetatable({}, { __index = { deeper = function() return "deep" end } }),
})
show(s:deeper())
show(s.missing)

-- a non-string receiver keeps its own lookup rules
local obj = setmetatable({}, { __index = { hi = function() return "table-hi" end } })
show(obj:hi())

-- restore the string metatable
debug.setmetatable("", defaultMetatable)
show(("z"):byte())
show(("%d"):format(7))
