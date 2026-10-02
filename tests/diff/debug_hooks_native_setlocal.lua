-- call and return hooks run on the live stack of the hooked function (ldo.c:
-- precallC calls the hook before the C function reads its arguments,
-- luaD_poscall calls it before the results are moved to the caller), so a
-- debug.setlocal from the hook changes what a native function gets and
-- what it returns, however it is called

-- runs body() with a hook that, when 'target' is called, sets its first
-- argument to 'newArgument' and, when it returns, sets its result number
-- 'resultIndex' to 'newResult' (nil: leaves it)
local function withHook(target, newArgument, newResult, resultIndex, body)
  debug.sethook(function (event)
    if debug.getinfo(2, "f").func ~= target then return end
    if event == "call" and newArgument ~= nil then
      local name, old = debug.getlocal(2, 1)
      print("  call: argument 1 was", old, debug.setlocal(2, 1, newArgument))
    elseif event == "return" and newResult ~= nil then
      local slot = debug.getinfo(2, "r").ftransfer + resultIndex - 1
      local name, old = debug.getlocal(2, slot)
      print("  return: result " .. resultIndex .. " was", old, debug.setlocal(2, slot, newResult))
    end
  end, "cr")
  local results = table.pack(body())
  debug.sethook()
  return table.unpack(results, 1, results.n)
end

print("call", withHook(math.abs, -99, 123, 1, function ()
  local result = math.abs(-5)
  return result
end))
print("tail call", withHook(math.abs, -99, 123, 1, function () return math.abs(-5) end))
local function tailCaller() return math.abs(-5) end
print("caller of a tail call", withHook(tailCaller, nil, 456, 1, tailCaller))
print("pcall", withHook(math.abs, -7, nil, 1, function () return pcall(math.abs, -5) end))
print("metamethod", withHook(type, 5, nil, 1, function ()
  return -setmetatable({}, {__unm = type})
end))
print("metamethod result", withHook(type, nil, "changed", 1, function ()
  return -setmetatable({}, {__unm = type})
end))
local co = coroutine.create(math.abs)  -- a native function as the body
debug.sethook(co, function (event)
  if debug.getinfo(2, "f").func ~= math.abs then return end
  if event == "call" then
    print("  call: argument 1 was", select(2, debug.getlocal(2, 1)), debug.setlocal(2, 1, -11))
  elseif event == "return" then
    local slot = debug.getinfo(2, "r").ftransfer
    print("  return: result 1 was", select(2, debug.getlocal(2, slot)), debug.setlocal(2, slot, 22))
  end
end, "cr")
print("coroutine body", coroutine.resume(co, -5))
print("second result", withHook(select, nil, "two", 2, function ()
  return select(1, "a", "b", "c")
end))
print("argument of a function called by a native", withHook(string.upper, "zz", nil, 1, function ()
  return string.gsub("abc", "b", string.upper)
end))
