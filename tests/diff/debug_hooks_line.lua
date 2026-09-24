-- line hooks: the exact sequence of "line" events for many constructs
-- (ldebug.c: luaG_traceexec, changedline; new line, backward jump, function entry)
local function trace (source)
  local lines = {}
  local function hook (event, line) lines[#lines + 1] = line end
  local chunk = assert(load(source))
  debug.sethook(hook, "l"); chunk(); debug.sethook()
  print((table.concat(lines, ",")))
end

trace[[if
math.sin(1)
then
  a=1
else
  a=2
end
]]
trace[[
local function foo()
end
foo()
A = 1
A = 2
A = 3
]]
trace[[--
if nil then
  a=1
else
  a=2
end
]]
trace[[a=1
repeat
  a=a+1
until a==3
]]
trace[[ do
  return
end
]]
trace[[local a
a=1
while a<=3 do
  a=a+1
end
]]
trace[[while math.sin(1) do
  if math.sin(1)
  then break
  end
end
a=1]]
trace[[for i=1,3 do
  a=i
end
]]
trace[[for i,v in pairs{'a','b'} do
  a=tostring(i) .. v
end
]]
trace[[for i=1,4 do a=1 end]]
trace[[for i=3,1 do a=1 end
a=2]]
trace[[for i=1.0,2.0,0.5 do
  a=i
end]]
trace[[local t = {
  1,
  2,
  f = function () return 1 end,
}
local x = t.f(
  1,
  2
)
]]
trace[[local a, b = 1, nil
local c = a and
  b or
  3
if a and b then c = 1 elseif a or b then c = 2 else c = 3 end
if not a then c = 4 end
c = a == 1 and "one" or "other"
c = (a < 2) == (a <= 2)
]]
trace[[local s = "a" ..
  "b" ..
  tostring(1)
local n = #s +
  2 *
  3
]]
trace[[local i = 1
::top::
i = i + 1
if i < 3 then goto top end
do goto skip end
i = 10
::skip::
]]
trace[[local function f (x)
  if x > 0 then
    return f(x - 1)
  end
  return x
end
f(2)
]]
trace[[local t = setmetatable({}, {__index = function (t, k)
  return k
end})
local v = t.x
v = t.y
]]
trace[[local o = {}
function o:m (x)
  return x
end
o:m(1)
o
  :m(2)
]]
trace[[local x = 1 local y = 2 local z = x + y]]
trace[[local a = {1, 2, 3}
local s = 0
for _, v in ipairs(a) do s = s + v end
]]
trace[[local function g (...)
  local a, b = ...
  return a
end
g(1, 2)
]]
trace[[local co = 0
repeat
  local x = co
  co = co + 1
until x >= 1
]]

-- large gaps between lines (absolute line information)
local s = [[
     local b = {10}
     a = b[1] X + Y b[1]
     b = 4
  ]]
for _, i in ipairs{1, 10, 126, 127, 128, 129, 130, 255, 256, 1000} do
  local subs = {X = string.rep("\n", i), Y = string.rep("\n", 3)}
  trace((string.gsub(s, "[XY]", subs)))
end

-- stripped code: one event (without line) at function entry
local stripped = load(string.dump(function ()
  local a = 1
  local b = 2
  return b
end, true))
local events = {}
debug.sethook(function (e, l) events[#events + 1] = e .. ":" .. tostring(l) end, "l")
stripped()
debug.sethook()
print(table.concat(events, " "))

-- a hook set while a function runs starts at its next instruction; the
-- caller sees a line event only when its next instruction is on a new line
local function sets ()
  debug.sethook(function (e, l) io.write(l, " ") end, "l"); local x = 1
  x = 2
  x = 3
end
sets(); debug.sethook()
print()
