-- concatenation and string/number coercion
print(1 .. 2, 1.5 .. "", -0.0 .. "", 2^63 .. "", 10 // 1 .. "x", "a" .. 1e100)
print("x" .. 1 .. 2 .. 3, 1 .. "" .. 2, "" .. "")
local parts = {}
for i = 1, 20 do parts[#parts + 1] = i end
print(table.concat(parts))
local s = ""
for i = 1, 10 do s = s .. i .. "," end
print(s)
print("10" + 1, "10" + 1.5, "3" * "4", "2" ^ 2, "7" // 2, "7" % 3, -"2", "0x10" + 0, "1e2" * 1, " 5 " + 1)
print("10" / 2, "9" - 1, 10 - "1", "3.0" + 0, "-3" + 0, math.type("10" + 0), math.type("10.0" + 0))
print(pcall(function () return "abc" + 1 end))
print(pcall(function () return {} .. "" end))
print(pcall(function () return "1" & 1 end))
print(pcall(function () return "a" < 1 end))
print(pcall(function () local x = "x"; return -x end))
print(pcall(function () return "10" + {} end))
local meta = setmetatable({}, {__add = function (a, b) return "meta add" end})
print("10" + meta, meta + "10")
print(10 == "10", "abc" == "abc", 0 == -0)
-- results of more than 64 KB go through the concatenation helper (MemoryLimit checks their size)
local long = ("0123456789"):rep(6554)
print(#(long .. long), #(long .. "ab"), (long .. long .. 7):sub(-3), #(long:sub(1, 65534) .. "ab"), #(long:sub(1, 65534) .. "abc"))
