-- parser nesting limits inside load(): "C stack overflow" is raised as a
-- runtime error, so the current message handler sees it
local function rep(s, n) local t = {} for i = 1, n do t[i] = s end return table.concat(t) end
print(load("return " .. rep("(", 100) .. "1" .. rep(")", 100))())
print(load("return " .. rep("(", 300) .. "1" .. rep(")", 300)))
print(load("local a = " .. rep("{", 300) .. rep("}", 300)))
print(load(rep("do ", 300) .. rep(" end", 300)))
print(pcall(load, "return " .. rep("(", 300)))
print(xpcall(load, function (m) return "handled: " .. m end, "return " .. rep("(", 300)))
print(xpcall(function () return load("x = " .. rep("-", 300) .. "1") end, function (m) return "in handler" end))
