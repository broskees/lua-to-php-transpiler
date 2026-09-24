-- a million tables chained through a field or through their metatables, or
-- in a cycle (which only PHP's cycle collector frees), are freed without
-- exhausting PHP's C stack: in the main thread, inside a coroutine (a much
-- smaller C stack), and when the state closes at exit
local N = 1000000

local function linkedList(n)
  local list = nil
  for i = 1, n do list = {value = i, next = list} end
  return list
end

local function metatableChain(n)
  local chain = {}
  for _ = 1, n do chain = setmetatable({}, chain) end
  return chain
end

local function ring(n)
  local first = {value = 1}
  local last = first
  for i = 2, n do last.next = {value = i}; last = last.next end
  last.next = first
  return first
end

local function buildAndDrop(where)
  local list = linkedList(N)
  assert(list.value == N and list.next.next.value == N - 2)
  list = nil
  collectgarbage()
  print(where, "linked list freed")
  local chain = metatableChain(N)
  assert(getmetatable(getmetatable(chain)) ~= nil)
  chain = nil
  collectgarbage()
  print(where, "metatable chain freed")
  local cycle = ring(N)
  assert(cycle.next.next.value == 3)
  cycle = nil
  collectgarbage()
  print(where, "cycle freed")
end

buildAndDrop("main")
coroutine.wrap(buildAndDrop)("coroutine")
coroutine.wrap(function ()
  KEPT_UNTIL_EXIT = linkedList(N)  -- freed when the state closes
end)()
print("done")
