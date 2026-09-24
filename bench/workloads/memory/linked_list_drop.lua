-- a 1M-node linked list, built and then dropped: freeing it must not crash
local list = nil
for i = 1, 1000000 do list = {value = i, next = list} end
list = nil
collectgarbage()
print("survived")
print(io.open("/proc/self/status"):read("a"))
