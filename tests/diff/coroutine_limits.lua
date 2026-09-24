-- nested resumes count as C calls (LUAI_MAXCCALLS): "C stack overflow"
-- in deeply nested coroutines, as in cstack.lua

-- infinite recursion of coroutines
local count = 0
local function recurse(f) count = count + 1; coroutine.wrap(f)(f) end
local ok, msg = pcall(recurse, recurse)
print(ok, count, msg:match("C stack overflow$"), select(2, msg:gsub(":", ":")) > 100)

count = 0
local function resumer()
  count = count + 1
  local ok2, msg2 = coroutine.resume(coroutine.create(resumer))
  if not ok2 then error(msg2, 0) end
end
print(pcall(resumer))
print(count)

-- available C calls inside nested coroutines
local function avail()
  local depth = 0
  local function g() depth = depth + 1; return pcall(g) end
  g()
  return depth
end
print("main", avail())
coroutine.wrap(function ()
  print("level 1", avail())
  coroutine.wrap(function () print("level 2", avail()) end)()
end)()

-- limits in coroutines inside deep Lua calls
count = 0
local lim = 300
local function stack(n)
  if n > 0 then return stack(n - 1) + 1
  else coroutine.wrap(function () count = count + 1; stack(lim) end)()
  end
end
print(xpcall(stack, function () return "handled" end, lim))
print(count)

-- chain of coroutine.close: each __close closes the previous coroutine
count = 0
local coro = false
for i = 1, 300 do
  local previous = coro
  coro = coroutine.create(function ()
    local cc <close> = setmetatable({}, {__close = function ()
      count = count + 1
      if previous then assert(coroutine.close(previous)) end
    end})
    coroutine.yield()
  end)
  assert(coroutine.resume(coro))
end
local st, closeMessage = coroutine.close(coro)
print(st, closeMessage:match("C stack overflow"), count)

-- nesting of resuming yielded coroutines
count = 0
local function body()
  coroutine.yield()
  local f = coroutine.wrap(body)
  f()
  count = count + 1
  f()
end
local f = coroutine.wrap(body)
f()
print(pcall(f))
print(count)

-- nesting coroutines running after recoverable errors
count = 0
local function foo()
  count = count + 1
  pcall(1)
  coroutine.wrap(foo)()
end
print(pcall(foo))
print(count)

-- many arguments and results through resume/yield
local n = 5000
local co = coroutine.create(function (...)
  local t = table.pack(coroutine.yield(select("#", ...)))
  return table.unpack(t, 1, t.n)
end)
print(coroutine.resume(co, table.unpack({}, 1, n)))
print(select("#", coroutine.resume(co, table.unpack({}, 1, n))))
