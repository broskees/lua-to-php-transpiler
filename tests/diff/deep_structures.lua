-- long chains of nested tables are built, walked and freed without crashing
local list = nil
for i = 1, 200000 do list = {value = i, next = list} end
local count, node = 0, list
while node do count = count + 1; node = node.next end
print(count, list.value, list.next.value)
list = nil
collectgarbage()
local nested = {}
local current = nested
for _ = 1, 100000 do current[1] = {}; current = current[1] end
nested = nil
collectgarbage()
print("freed")
