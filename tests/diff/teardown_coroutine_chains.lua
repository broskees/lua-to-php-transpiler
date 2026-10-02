-- chains of coroutines, each keeping the previous one: in a register of the
-- stack it died with (an error) or was suspended with, as its error value,
-- as the coroutine of a wrapped function, or in an upvalue of the function
-- it has not started yet. Freeing a chain must not
-- exhaust PHP's C stack (freeing a coroutine frees its stack, which frees
-- the previous coroutine, ...): in the main thread, inside a coroutine (a
-- much smaller C stack), and when the state closes at exit
local N = 10000

local function diedWithError(n)
  local previous
  for _ = 1, n do
    local co = coroutine.create(function (previous) error(false) end)
    assert(not coroutine.resume(co, previous))
    previous = co
  end
  return previous
end

local function diedWithPreviousAsError(n)
  local previous = false
  for _ = 1, n do
    local co = coroutine.create(function (previous) error(previous) end)
    local ok, value = coroutine.resume(co, previous)
    assert(not ok and value == previous)
    previous = co
  end
  return previous
end

local function suspended(n)
  local previous
  for _ = 1, n do
    local co = coroutine.create(function (previous) coroutine.yield() end)
    assert(coroutine.resume(co, previous))
    previous = co
  end
  return previous
end

local function suspendedWrapped(n)
  local previous
  for _ = 1, n do
    local f = coroutine.wrap(function (previous) coroutine.yield() end)
    f(previous)
    previous = f
  end
  return previous
end

local function notStarted(n)
  local previous
  for _ = 1, n do
    local kept = previous
    previous = coroutine.create(function () return kept end)
  end
  return previous
end

local function buildAndDrop(where)
  local chain = diedWithError(N)
  assert(coroutine.status(chain) == "dead")
  chain = nil
  collectgarbage()
  print(where, "coroutines dead by an error freed")
  chain = diedWithPreviousAsError(N)
  chain = nil
  collectgarbage()
  print(where, "coroutines dead with the previous as error freed")
  chain = suspended(N)
  assert(coroutine.status(chain) == "suspended")
  chain = nil
  collectgarbage()
  print(where, "suspended coroutines freed")
  chain = suspendedWrapped(N)
  chain = nil
  collectgarbage()
  print(where, "suspended wrapped coroutines freed")
  chain = notStarted(N)
  assert(coroutine.status(chain) == "suspended")
  chain = nil
  collectgarbage()
  print(where, "coroutines not started freed")
end

buildAndDrop("main")
coroutine.wrap(buildAndDrop)("coroutine")
coroutine.wrap(function ()
  KEPT_UNTIL_EXIT = diedWithError(N)  -- freed when the state closes
  KEPT_TOO = suspended(N)
end)()
print("done")
