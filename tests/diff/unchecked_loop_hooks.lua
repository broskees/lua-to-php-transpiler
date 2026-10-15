-- The unchecked copy of a loop (FunctionEmitter::findUncheckedRegions)
-- runs without hook checks while no hook is set, and must notice one set
-- while its code runs Lua (a call, a metamethod, a to-be-closed method).
-- Each loop below sets or clears a hook where the copy has to look at
-- 'trap' again; the output must be lua5.4's, hook events included.

local function show(event, line)
  io.write(event, " ", tostring(line), "\n")
end

print("-- a line hook set and cleared inside a numeric loop")
local sum = 0
for i = 1, 8 do
  sum = sum + i * 2
  if i == 2 then
    debug.sethook(show, "l")
  end
  if i == 5 then
    debug.sethook()
  end
end
print(sum)

print("-- a count hook over a loop, then cleared inside it")
debug.sethook(function () io.write("count\n") end, "", 5)
local x = 0
for i = 1, 24 do x = x + 1 end
debug.sethook()
print(x)

print("-- a metamethod in a loop sets a line hook on its first call")
local first = true
local t = setmetatable({}, {
  __add = function (a, b)
    if first then
      first = false
      debug.sethook(show, "l")
    end
    return 10
  end,
})
local r = 0
for i = 1, 3 do
  r = r + t
end
print(r)

print("-- a call in a loop sets and clears a count hook")
local function touch(i)
  if i == 3 then
    debug.sethook(function () io.write("tick\n") end, "", 3)
  elseif i == 6 then
    debug.sethook()
  end
  return i
end
local s = 0
for i = 1, 9 do
  s = s + touch(i)
end
print(s)

print("-- a to-be-closed variable's __close sets a hook in a loop")
for i = 1, 2 do
  local _ <close> = setmetatable({}, {
    __close = function ()
      debug.sethook(show, "c")
    end,
  })
  local q = i * 3
end
debug.sethook()
print("done")
