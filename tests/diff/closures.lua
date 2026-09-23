-- closures, upvalues, fresh locals per loop iteration
local fns = {}
for i = 1, 3 do fns[i] = function () return i end end
print(fns[1](), fns[2](), fns[3]())
local whileFns, j = {}, 1
while j <= 3 do local k = j; whileFns[j] = function () k = k + 10; return k end; j = j + 1 end
print(whileFns[1](), whileFns[1](), whileFns[2](), whileFns[3]())
local function counter()
  local n = 0
  return function () n = n + 1; return n end, function () return n end
end
local inc, get = counter()
inc(); inc()
local inc2 = counter()
inc2()
print(get(), inc(), get(), inc2())
-- shared upvalue between sibling closures, closed after the function returns
local function pair()
  local v = "start"
  local function set(x) v = x end
  local function read() return v end
  return set, read
end
local set, read = pair()
set("changed")
print(read())
-- upvalues of upvalues
local function outer()
  local x = 1
  return function ()
    return function () x = x * 2; return x end
  end
end
local deep = outer()()
print(deep(), deep(), deep())
-- recursive local function sees itself
local function fact(n) if n <= 1 then return 1 end return n * fact(n - 1) end
print(fact(20), fact(21))
-- loop variables captured in nested loops
local grid = {}
for x = 1, 2 do for y = 1, 2 do grid[#grid + 1] = function () return x * 10 + y end end end
for _, f in ipairs(grid) do io = io; print(f()) end
-- closures in repeat and generic for
local rep, r = {}, 0
repeat local rr = r; rep[#rep + 1] = function () return rr end; r = r + 1 until r == 3
print(rep[1](), rep[2](), rep[3]())
local gen = {}
for k, v in ipairs({"a", "b"}) do gen[k] = function () return k .. v end end
print(gen[1](), gen[2]())
-- debug upvalue functions
local up1, up2 = 1, 2
local function f1() return up1 end
local function f2() return up2 end
print(debug.getupvalue(f1, 1), debug.getupvalue(f2, 1), debug.getupvalue(f1, 2))
print(debug.setupvalue(f1, 1, 100), up1, f1())
print(debug.upvalueid(f1, 1) == debug.upvalueid(f1, 1), debug.upvalueid(f1, 1) == debug.upvalueid(f2, 1))
local function g1() return up1, up2 end
print(debug.upvalueid(f1, 1) == debug.upvalueid(g1, 1), type(debug.upvalueid(f1, 1)))
debug.upvaluejoin(f1, 1, f2, 1)
print(f1(), f2(), up1, up2)
up2 = 22
print(f1(), f2())
print(pcall(debug.upvaluejoin, f1, 2, f2, 1))
print(pcall(debug.upvalueid, f1, 5), debug.getupvalue(print, 1), debug.getupvalue(print, 0))
print(pcall(debug.upvaluejoin, print, 1, f2, 1))
