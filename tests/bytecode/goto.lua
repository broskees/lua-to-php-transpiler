-- goto and labels: forward/backward jumps, closing upvalues, labels at
-- block ends, 'break' inside nested blocks, repeat-until scoping.
local function f1(n)
  local i = 0
  ::top::
  i = i + 1
  if i < n then goto top end
  do
    goto skip
    local unused = 1
    print(unused)
  end
  ::skip::
  return i
end

local function f2()
  local closures = {}
  for i = 1, 3 do
    local x = i
    closures[i] = function() return x end
    if i == 2 then goto continue end
    x = x * 10
    ::continue::
  end
  return closures
end

local function f3()
  do
    local a = 1
    local g = function() return a end
    goto out
  end
  ::out::
  do
    local b = 2
    ::back::
    local c = function() return b end
    b = b - 1
    if b > 0 then goto back end
  end
  while true do
    local captured = 0
    local h = function() captured = captured + 1 end
    if h then break end
  end
end

local function f4(x)
  repeat
    local y = x
    local z = function() return y end
    x = x - 1
  until y < 0 or z() == 3
  repeat
    local w = x
  until w
  repeat local q = x; if q then break end until false
  repeat
    local captured = x
    local k = function() return captured end
    if captured == 7 then goto finish end
  until captured > 10
  ::finish::
  return x
end

local function f5(t)
  for k, v in pairs(t) do
    if v then goto next_item end
    do
      local w = v
      t[k] = function() return w end
      goto next_item
    end
    ::next_item::
  end
  if t then goto e1 end
  goto e1
  ::e1:: ;;
  ::e2::
end

local function f6()
  -- labels with the same name in different functions and blocks
  do ::l1:: end
  do ::l1:: end
  local function inner() goto l1; ::l1:: end
  while true do if inner then break end end
  for i = 1, 2 do if i then break else break end end
  ::l1::
end

local function f7(a)
  if a then goto l end
  local x <close> = nil
  do return end
  ::l::
end
return f1, f2, f3, f4, f5, f6, f7
