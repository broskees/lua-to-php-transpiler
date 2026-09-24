-- yields that must fail with "attempt to yield across a C-call boundary":
-- Lua code called by native functions that have no continuation in C
-- (lua_call, not lua_callk), and metamethods triggered by native code

local function try(label, f)
  local co = coroutine.wrap(function ()
    local results = table.pack(pcall(f))
    return table.unpack(results, 1, results.n)
  end)
  print(label, co())
end

local function yielder(...) coroutine.yield("should not yield"); return ... end

try("table.sort comparator", function () table.sort({3, 1, 2}, function (a, b) coroutine.yield() return a < b end) end)
try("table.sort yield itself", function () table.sort({1, 2, 3}, coroutine.yield) end)
try("table.sort __lt", function ()
  local mt = {__lt = function (a, b) coroutine.yield() return a.v < b.v end}
  table.sort({setmetatable({v = 2}, mt), setmetatable({v = 1}, mt)})
end)
try("string.gsub callback", function () return string.gsub("abc", "%w", yielder) end)
try("string.gsub __index", function ()
  return string.gsub("abc", "%w", setmetatable({}, {__index = function (_, k) coroutine.yield() return k end}))
end)
try("tostring __tostring", function ()
  return tostring(setmetatable({}, {__tostring = function () coroutine.yield() return "x" end}))
end)
try("print __tostring", function ()
  print(setmetatable({}, {__tostring = function () coroutine.yield() return "x" end}))
end)
try("__name via error", function ()
  return string.rep(setmetatable({}, {__tostring = function () coroutine.yield() end}))
end)
try("load reader", function ()
  return load(function () coroutine.yield() end)
end)
try("require loader", function ()
  package.preload["yielding.module"] = function () coroutine.yield() return true end
  return require("yielding.module")
end)
try("table.insert __newindex", function ()
  local proxy = setmetatable({}, {__newindex = function (t, k, v) coroutine.yield() rawset(t, k, v) end})
  table.insert(proxy, "v")
end)
try("table.concat __index", function ()
  local proxy = setmetatable({}, {__index = function (_, k) coroutine.yield() return "v" end, __len = function () return 2 end})
  return table.concat(proxy)
end)
try("table.unpack __index", function ()
  return table.unpack(setmetatable({}, {__index = function () coroutine.yield() end}), 1, 2)
end)
try("ipairs __index", function ()
  for i, v in ipairs(setmetatable({}, {__index = function (_, i) coroutine.yield() if i < 3 then return i end end})) do end
end)
try("string arithmetic metamethod", function ()
  return "10" + setmetatable({}, {__add = function () coroutine.yield() return 1 end})
end)
try("next via __pairs result", function ()
  -- the iterator returned by __pairs is called by the VM: fine
  local t = setmetatable({}, {__pairs = function () return function (_, k) if not k then coroutine.yield("ok") return 1 end end end})
  for k in pairs(t) do end
  return "no error"
end)
try("xpcall message handler", function ()
  return xpcall(error, function (m) coroutine.yield() return m end, "boom")
end)
try("__close while closing a coroutine", function ()
  local co = coroutine.create(function ()
    local x <close> = setmetatable({}, {__close = function () coroutine.yield() end})
    coroutine.yield()
  end)
  coroutine.resume(co)
  return coroutine.close(co)
end)
try("coroutine.wrap(pcall) inside sort", function ()
  table.sort({1, 2}, function (a, b) return coroutine.wrap(function () return pcall(coroutine.yield, a < b) end)() end)
  return "sorted"
end)

-- isyieldable inside natives without continuations
coroutine.wrap(function ()
  print("gsub isyieldable", string.gsub("a", ".", function (c) return tostring(coroutine.isyieldable()) end))
  print("sort isyieldable", pcall(table.sort, {1, 2}, function (a, b) print(coroutine.isyieldable()) return a < b end))
end)()

-- the error propagates out of wrap with position information
local w = coroutine.wrap(function () table.sort({1, 2, 3}, coroutine.yield) end)
print(pcall(w))
w = coroutine.wrap(function () string.gsub("a", ".", yielder) end)
print(pcall(w))

-- the main thread cannot yield
print(pcall(coroutine.yield))
print(select(2, pcall(function () return coroutine.yield(1) end)))
print(coroutine.isyieldable())
