-- coroutines stopped 100000 Lua calls deep (suspended, or dead by an error,
-- which keeps its stack) are dropped: freeing their call stacks must not
-- exhaust PHP's C stack, whether the main thread or a coroutine drops them,
-- or the state closes at exit
local DEPTH = 100000

local function deepCoroutine(finish)
  local function dive(n)
    if n > 0 then dive(n - 1) end
    finish()
  end
  return coroutine.create(function () dive(DEPTH) end)
end

local function dropDeepCoroutines(where)
  local suspended = deepCoroutine(coroutine.yield)
  assert(coroutine.resume(suspended))
  print(where, coroutine.status(suspended))
  suspended = nil
  collectgarbage()
  print(where, "suspended coroutine freed")

  local dead = deepCoroutine(function () error("deep error") end)
  local ok, message = coroutine.resume(dead)
  print(where, ok, message, coroutine.status(dead))
  dead = nil
  collectgarbage()
  print(where, "dead coroutine freed")
end

dropDeepCoroutines("main")
coroutine.wrap(dropDeepCoroutines)("coroutine")
KEPT_UNTIL_EXIT = deepCoroutine(coroutine.yield)  -- freed when the state closes
assert(coroutine.resume(KEPT_UNTIL_EXIT))
print("done")
