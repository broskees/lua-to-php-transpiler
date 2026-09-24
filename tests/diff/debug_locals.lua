-- debug.getlocal / debug.setlocal: named locals, temporaries, varargs, parameters
local function locals (level)
  local out = {}
  for i = 1, 20 do
    local name, value = debug.getlocal(level + 1, i)
    if not name then break end
    out[#out + 1] = name .. "=" .. tostring(value)
  end
  return table.concat(out, " ")
end

-- named locals active at the current instruction, then temporaries
local function f (a, b)
  local c = a + b
  do local inner = 1; print(locals(1)) end
  print(locals(1))
  local d <const> = 10
  return c + d
end
f(1, 2)

-- a temporary below the called function; limit at the call
local function g (a, b) return (a + 1) + (function ()
  print(debug.getlocal(2, 3), debug.getlocal(2, 4), debug.getlocal(2, 5))
  print(debug.setlocal(2, 3, 10))
  return 20
end)() end
print(g(0, 0))

-- C temporaries: the arguments of the running native function
print(debug.getlocal(0, 1))
print(debug.getlocal(0, 2))
print(debug.getlocal(0, 3), debug.getlocal(0, 0), debug.getlocal(0, -1))

-- parameter names of a function
local function params (x, y, ...) local z end
print(debug.getlocal(params, 1), debug.getlocal(params, 2), debug.getlocal(params, 3), debug.getlocal(params, 0))
print(debug.getlocal(print, 1), debug.getlocal(function () end, 1))

-- varargs
local function va (a, ...)
  local t = table.pack(...)
  for i = 1, t.n do io.write(tostring(debug.getlocal(1, -i)), ":", tostring(select(2, debug.getlocal(1, -i))), " ") end
  print(debug.getlocal(1, -(t.n + 1)), debug.setlocal(1, -(t.n + 1), 30))
  if t.n > 0 then
    (function (x)
      print(debug.setlocal(2, -1, x), debug.setlocal(2, -t.n, x))
    end)(430)
    print(...)
  end
end
va()
va(1)
va(1, 2, 3)
local function novararg () return debug.getlocal(1, -1) end
print(novararg(10))

-- writes are seen by the running function, and through open upvalues
local function writer ()
  local x = 1
  local function getx () return x end
  debug.setlocal(1, 1, 42)
  local y = "old"
  print(debug.setlocal(1, 3, "new"), x, getx(), y)
end
writer()

-- errors
print(pcall(debug.getlocal, 20, 1))
print(pcall(debug.setlocal, -1, 1, 10))
print(pcall(debug.setlocal, 1, 1))
print(pcall(debug.getlocal, 1))
print(pcall(debug.getlocal, "x", 1))
print(pcall(debug.getlocal, {}, 1))
print(pcall(debug.setlocal, print, 1, 1))

-- a local is visible only after its declaration completes
local function later ()
  local before = 1
  print(locals(1))
  local after = (function () print(locals(2)) return 2 end)()
  print(locals(1))
end
later()

-- stripped code has only temporaries
local stripped = load(string.dump(function (p)
  local q = p * 2
  return debug.getlocal(1, 1), debug.getlocal(1, 2)
end, true))
print(stripped(21))
