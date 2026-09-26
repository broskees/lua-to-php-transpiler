-- debug.getlocal's "(temporary)" slots up to C's stack top (ldebug.c:
-- luaG_findlocal's limit: L->top for the running frame, else the next
-- frame's function). Only slots whose values C defines are printed with
-- their value; the others hold what earlier calls left on C's stack.

local HOOKTABLE
local function show (v)
  if v ~= nil and v == HOOKTABLE then return "<hook table>" end
  local t = type(v)
  if t == "table" or t == "function" or t == "thread" or t == "userdata" then
    return "<" .. t .. ">"
  end
  return tostring(v)
end

-- slots of the function at 'level' (of the caller); values from slot 'from' on
local function slots (level, from)
  local out = {}
  local n = 1
  while true do
    local name, value = debug.getlocal(level + 1, n)
    if not name then break end
    if n >= from or not name:find("^%(") then
      out[#out + 1] = n .. "=" .. name .. ":" .. show(value)
    else
      out[#out + 1] = n .. "=" .. name
    end
    n = n + 1
  end
  return table.concat(out, " ")
end

debug.sethook(function () end, "l")
HOOKTABLE = debug.getregistry()._HOOKKEY
debug.sethook()
assert(type(HOOKTABLE) == "table")

-- a hook runs above the hooked frame's whole register window (luaD_hook
-- raises L->top to ci->top); ldblib.c's hookf pushes the hook table there,
-- below the hook function
local function target (a, b)
  local c = a + b
  local d = c * 2
  return d, c
end
local function hookOn (name, mask, count)
  debug.sethook(function (event, line)
    if debug.getinfo(2, "n").name == name then
      local info = debug.getinfo(2, "l")
      print(event, info.currentline, slots(2, 99))
      local last = 1
      while debug.getlocal(2, last + 1) do last = last + 1 end
      print("", "last slot", last, show(select(2, debug.getlocal(2, last))))
    end
  end, mask, count)
end
hookOn("target", "crl")
target(1, 2)
debug.sethook()

-- the return hook: the results are the frame's last slots before the table
local function many (...)
  local x = 1
  return ...
end
debug.sethook(function (event)
  if event == "return" and debug.getinfo(2, "n").name == "many" then
    print(event, slots(2, 3))
  end
end, "r")
many(10, 20, 30, 40, 50, 60, 70)
debug.sethook()

-- a call hook sees all the arguments, even past the frame's registers
local function few (a)
  return a
end
debug.sethook(function (event)
  if event == "call" and debug.getinfo(2, "n").name == "few" then
    print(event, slots(2, 1))
  end
end, "c")
few(1, 2, 3, 4, 5, 6)
debug.sethook()

-- a count hook before an instruction that takes the values left by the
-- previous one (lopcodes.h: isIT) sees them all
local t = {10, 20, 30, 40, 50, 60, 70, 80}
local function unpacked ()
  local x = select('#', table.unpack(t))
  return x
end
debug.sethook(function (event)
  if debug.getinfo(2, "n").name == "unpacked" then
    local n = 0
    while debug.getlocal(2, n + 1) do n = n + 1 end
    if n > 6 then print(event, slots(2, 1)) end
  end
end, "", 1)
unpacked()
debug.sethook()

-- a native function's hooks: its arguments (and results) and the table
debug.sethook(function (event)
  local info = debug.getinfo(2, "nS")
  if info.what == "C" and info.name == "max" then print(event, slots(2, 1)) end
end, "cr")
local m = math.max(3, 9, 4)
debug.sethook()

-- debug.setlocal reaches the hook table's slot, and nothing past it
local function settable (a)
  local b = a
  return b
end
debug.sethook(function (event)
  if event == "call" and debug.getinfo(2, "n").name == "settable" then
    local n = 1
    while debug.getlocal(2, n + 1) do n = n + 1 end
    print("set", n, debug.setlocal(2, n, "replaced"), select(2, debug.getlocal(2, n)))
    print("set past", debug.setlocal(2, n + 1, "no"), debug.getlocal(2, n + 1))
  end
end, "c")
settable(1)
debug.sethook()
print("hook table intact", debug.getregistry()._HOOKKEY == HOOKTABLE)

-- a vararg hook function: its function slot and its arguments too
debug.sethook(function (...)
  if debug.getinfo(2, "n").name == "target" then print("vararg hook", ..., slots(2, 7)) end
end, "c")
target(1, 2)
debug.sethook()

-- a vararg function moves its frame above its arguments (ltm.c:
-- luaT_adjustvarargs): the caller's frame reaches up to it and holds the
-- function, the fixed parameters (erased) and the extra arguments
local function vcallee (p, ...)
  print("vararg callee", slots(2, 2))
  print("set extra", debug.setlocal(2, 6, "changed"), ...)
  return p
end
local function vcaller (a)
  local b = vcallee(a, 7, 8, 9) + 1
  return b
end
vcaller(5)

-- the same for a vararg iterator of a generic for (at A + 4)
local function viter (...)
  print("vararg iterator", slots(2, 1))
  return nil
end
local function vloop ()
  local a = 1
  for k in viter, "state", "ctl" do end
end
vloop()

-- and for a vararg metamethod (called at the frame's top)
local vmt = {__index = function (...)
  print("vararg __index", slots(2, 5))
  return 1
end}
local function vindex (p)
  local obj = setmetatable({}, vmt)
  local s = obj[p]
  return s
end
vindex("k")

-- a finalizer called by an automatic collection step at OP_NEWTABLE sees
-- the frame up to the new table (lvm.c: checkGC(L, ra + 1))
do
  local done = false
  local function allocate ()
    local a, b, c = 10, 20, 30
    setmetatable({}, {__gc = function ()
      if done then return end
      done = true
      local names = {}
      local n = 1
      while true do
        local name, value = debug.getlocal(2, n)
        if not name then break end
        names[#names + 1] = name
        n = n + 1
      end
      print("finalizer", table.concat(names, " "), type(select(2, debug.getlocal(2, n - 1))))
    end})
    local t
    for i = 1, 10000000 do
      t = {i}
      if done then break end
    end
    local p, q, r = 1, 2, 3
    return t
  end
  allocate()
  print("finalized", done)
end
