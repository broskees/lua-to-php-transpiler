-- a pcall/xpcall inside a coroutine closes the variables abandoned by an
-- error with yieldable calls (ldo.c: precover -> finishpcallk ->
-- luaF_close(..., yy = 1)); outside a coroutine, or under a non-yieldable
-- call, the same __close cannot yield

local function func2close(f)
  return setmetatable({}, {__close = f})
end

local function foo(err)
  local z <close> = func2close(function (_, msg)
    print("z closing with", msg)
    print("z got", coroutine.yield("z"))
    return 100, 200
  end)
  local y <close> = func2close(function (_, msg)
    print("y closing with", msg)
    print("y got", coroutine.yield("y"))
    if err then error(err + 20, 0) end  -- creates or changes the error
  end)
  local x <close> = func2close(function (_, msg)
    print("x closing with", msg)
    print("x got", coroutine.yield("x"))
    return 100, 200
  end)
  if err == 10 then error(err, 0) else return 10, 20 end
end

local co = coroutine.wrap(function ()
  coroutine.yield(pcall(foo, nil))  -- no error
  coroutine.yield(pcall(foo, 1))    -- error in __close
  coroutine.yield(xpcall(foo, function (m) return "handled " .. m end, 10))  -- error in the body
  return pcall(foo, 10)
end)
for step = 1, 16 do
  print(step, co(step))
end

-- the error of a __close raised while closing replaces the first one
co = coroutine.wrap(function ()
  return pcall(function ()
    local a <close> = func2close(function (_, msg) print("a sees", msg); coroutine.yield("a") end)
    local b <close> = func2close(function (_, msg) print("b sees", msg); coroutine.yield("b"); error("from b", 0) end)
    error("from body", 0)
  end)
end)
print(co()); print(co()); print(co())

-- closing a coroutine suspended in such a __close also closes the
-- variables the error left pending (C: they are still on its stack)
local function closable(name, yields)
  return func2close(function (_, e)
    print(name .. " closing with", e)
    if yields then coroutine.yield("in " .. name) end
  end)
end
co = coroutine.create(function ()
  local outer <close> = closable("outer")
  pcall(function ()
    local a <close> = closable("a")
    local b <close> = closable("b", true)
    error("boom", 0)
  end)
end)
print(coroutine.resume(co))
print(coroutine.close(co))
co = coroutine.create(function ()
  local outer <close> = closable("outer")
  pcall(function ()
    local a <close> = closable("a")
    local b <close> = func2close(function (_, e)
      print("b closing with", e)
      local inner <close> = closable("inner")
      pcall(function ()
        local c <close> = closable("c")
        local d <close> = closable("d", true)
        error("second", 0)
      end)
    end)
    error("first", 0)
  end)
end)
print(coroutine.resume(co))
print(coroutine.close(co))

-- not yieldable: pcall called by a native without continuation
co = coroutine.wrap(function ()
  local results
  table.sort({1, 2}, function (p, q)
    results = table.pack(pcall(function ()
      local v <close> = func2close(function () coroutine.yield("never") end)
      error("sort body", 0)
    end))
    return p < q
  end)
  return table.unpack(results, 1, results.n)
end)
print(co())

-- not yieldable: the main thread
print(pcall(function ()
  local v <close> = func2close(function (_, msg) coroutine.yield() end)
  error("main body", 0)
end))

-- a __close method that fails leaves its own pending variables to close
print(pcall(function ()
  local a <close> = func2close(function (_, m) print("a sees", m) end)
  local b <close> = func2close(function (_, m)
    local inner <close> = func2close(function (_, m2) print("inner sees", m2) end)
    print("b sees", m)
    error("from b", 0)
  end)
  error("body", 0)
end))

-- an error raised by a __close while xpcall unwinds goes through the
-- message handler too (the handler is still current: ldo.c luaD_pcall)
print(xpcall(function ()
  local y <close> = func2close(function (_, m) print("y sees", m) end)
  local x <close> = func2close(function (_, m) print("x sees", m); error("in close", 0) end)
  error("body", 0)
end, function (m) return "handled " .. m end))
