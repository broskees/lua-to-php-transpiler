-- a chain of a million closures, each capturing the previous one, is freed
-- without exhausting PHP's C stack: in the main thread, inside a coroutine
-- (a much smaller C stack), and when the state closes at exit
local N = 1000000

local function closureChain(n)
  local f = function () return nil end
  for _ = 1, n do
    local previous = f
    f = function () return previous end
  end
  return f
end

local function length(f)
  local count = 0
  while true do
    f = f()
    if f == nil then return count end
    count = count + 1
  end
end

local function buildAndDrop(where)
  local chain = closureChain(N)
  assert(length(chain) == N)
  chain = nil
  collectgarbage()
  print(where, "closure chain freed")
end

buildAndDrop("main")
coroutine.wrap(buildAndDrop)("coroutine")
coroutine.wrap(function ()
  KEPT_UNTIL_EXIT = closureChain(N)  -- freed when the state closes
end)()
print("done")
