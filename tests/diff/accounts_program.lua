#!/usr/bin/env lua
-- a non-trivial program: classes via metatables, closures, varargs, error handling
local Account = {}
Account.__index = Account
Account.__tostring = function (self) return "Account(" .. self.owner .. ", " .. self.balance .. ")" end
Account.__eq = function (a, b) return a.balance == b.balance end
Account.__lt = function (a, b) return a.balance < b.balance end
Account.__add = function (a, b) return Account.new(a.owner .. "+" .. b.owner, a.balance + b.balance) end

function Account.new(owner, balance)
  return setmetatable({owner = owner, balance = balance or 0, history = {}}, Account)
end

function Account:deposit(amount)
  if type(amount) ~= "number" or amount <= 0 then
    error({code = "EINVAL", amount = amount})
  end
  self.balance = self.balance + amount
  self.history[#self.history + 1] = amount
  return self
end

function Account:withdraw(amount)
  if amount > self.balance then
    error("insufficient funds: balance " .. self.balance .. ", requested " .. amount, 2)
  end
  self.balance = self.balance - amount
  self.history[#self.history + 1] = -amount
  return self
end

local function sum(...)
  local total = 0
  for i = 1, select('#', ...) do total = total + (select(i, ...)) end
  return total, select('#', ...)
end

local function makeCounter(step)
  local count = 0
  return function (...)
    count = count + step * select('#', ...)
    return count
  end
end

local alice = Account.new("alice", 100):deposit(50)
local bob = Account.new("bob"):deposit(10):deposit(20)
print(alice, bob, alice == bob, bob < alice, tostring(alice + bob))
print(pcall(alice.withdraw, alice, 1000))
local ok, err = pcall(bob.deposit, bob, -5)
print(ok, type(err), err.code, err.amount)
print(pcall(function () local nothing; return nothing.balance end))
print(xpcall(function () return bob:withdraw(1e9) end, function (m) return "handled: " .. m end))
print(sum(1, 2, 3, 4.5), sum())
local counter = makeCounter(2)
counter(1, 2); counter(nil, nil, nil)
print(counter())
local accounts = {}
for i = 1, 5 do accounts[i] = Account.new("user" .. i, i * 10) end
local byBalance = {}
for _, account in ipairs(accounts) do byBalance[#byBalance + 1] = account.balance end
print(table.concat(byBalance, ","), #accounts, #alice.history)
local memo = setmetatable({}, {__index = function (t, n)
  local value = n < 2 and n or t[n - 1] + t[n - 2]
  rawset(t, n, value)
  return value
end})
print(memo[80], 2^53 + 1 == 2^53, math.maxinteger + 1 == math.mininteger, 7 // 2, 7 / 2, -7 % 3)
print("args:", select('#', ...), ...)
print("arg[0]:", arg[0], "arg[1]:", arg[1], #arg)
local function deep(n) if n == 0 then return 0 end return 1 + deep(n - 1) end
print(deep(5000))
do
  local closed = {}
  do
    local handle <close> = setmetatable({}, {__close = function () closed[#closed + 1] = "closed" end})
  end
  print(closed[1])
end
error("final uncaught error")
