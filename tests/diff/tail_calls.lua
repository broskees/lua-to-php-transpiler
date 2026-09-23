-- proper tail calls do not grow the stack
local function countdown(n) if n == 0 then return "done" end return countdown(n - 1) end
print(countdown(100000))
local isEven, isOdd
function isEven(n) if n == 0 then return true end return isOdd(n - 1) end
function isOdd(n) if n == 0 then return false end return isEven(n - 1) end
print(isEven(100001), isOdd(100001))
local function accumulate(n, acc) if n == 0 then return acc end return accumulate(n - 1, acc + n) end
print(accumulate(200000, 0))
local function multi(n) if n == 0 then return 1, 2, 3 end return multi(n - 1) end
print(multi(50000))
local function tailNative(...) return select('#', ...) end
local function callsNative(n) if n == 0 then return tailNative(1, 2) end return callsNative(n - 1) end
print(callsNative(30000))
local callable = setmetatable({}, {__call = function (self, n) if n == 0 then return "via __call" end return self(n - 1) end})
local function tailCallable(n) return callable(n) end
print(tailCallable(1000))
local function vararg(n, ...) if n == 0 then return select('#', ...), ... end return vararg(n - 1, ...) end
print(vararg(10000, "a", "b"))
print(pcall(function () local function loop(n) if n > 0 then return loop(n - 1) end error("at bottom") end return loop(50000) end))
