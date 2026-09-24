-- an error inside a wrapped coroutine reaches the top level: its message
-- gets the wrap caller's position, the traceback is the main thread's
local function inner()
  local x = nil
  return x.field
end
local gen = coroutine.wrap(function ()
  coroutine.yield(1)
  inner()
end)
print(gen())
local function caller()
  return gen() + 1
end
caller()
