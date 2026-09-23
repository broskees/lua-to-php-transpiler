-- Nested closures: upvalues of upvalues, shared upvalues, _ENV handling.
local a = 1
local function level1()
  local b = 2
  local function level2()
    local c = 3
    local function level3()
      a = a + b + c
      return function() return a, b, c, level1, level2 end
    end
    return level3
  end
  return level2
end
local function setters()
  local v
  return function(x) v = x end, function() return v end
end
local _ENV = {print = print}
function globalInNewEnv() return print end
local function useEnv() return x, y, z end
do
  local _ENV = {}
  w = 1
end
local function rec(n) if n > 0 then return rec(n - 1) end return n end
local mutual1, mutual2
function mutual1(n) return mutual2(n) end
function mutual2(n) return mutual1 end
local t = {}
function t.nested() return function() return t end end
return level1, setters, useEnv, rec, mutual1, t
