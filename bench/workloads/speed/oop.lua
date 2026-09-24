-- method calls through a metatable's __index (1e6 calls)
local Account = {}
Account.__index = Account
function Account.new(balance) return setmetatable({balance = balance}, Account) end
function Account:deposit(amount) self.balance = self.balance + amount end
function Account:getBalance() return self.balance end
local account = Account.new(0)
for i = 1, 500000 do
  account:deposit(i)
  account:getBalance()
end
print(account:getBalance())
