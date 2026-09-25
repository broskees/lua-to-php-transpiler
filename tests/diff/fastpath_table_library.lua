-- table.insert/remove/concat/unpack: the fast paths (a table without a
-- metatable, the usual arguments) and every case that takes the C port

local function show(...) print(select("#", ...), ...) end
local function try(f, ...) print(pcall(f, ...)) end
local function list(t, n) local parts = {} for i = 1, n or #t do parts[i] = tostring(t[i]) end return "{" .. table.concat(parts, ",") .. "}" end

print("-- insert")
local t = {}
for i = 1, 5 do table.insert(t, i * 10) end
print(list(t), #t)
table.insert(t, nil)                       -- appends nil: nothing changes
print(list(t), #t)
table.insert(t, 1, "first")                -- 3 arguments: the C port
table.insert(t, #t + 1, "last")
table.insert(t, 3, "third")
print(list(t), #t)
local holes = {1, 2, nil, 4}               -- border from the constructor
table.insert(holes, "x")
print(list(holes, 5), #holes)
local zero = {[0] = "zero"}
table.insert(zero, "one")
print(zero[0], zero[1], #zero)
-- a length of maxinteger wraps around to mininteger
table.insert(setmetatable({}, {__len = function () return math.maxinteger end,
  __newindex = function (_, k, v) print("newindex", k, v) end}), "wrapped")
try(table.insert, nil, 1)
try(table.insert, "abc", 1)
try(table.insert, {})
try(table.insert, {}, 1, 2, 3)
try(table.insert, {1, 2}, 0, "x")
try(table.insert, {1, 2}, 4, "x")
try(table.insert, {1, 2}, 1.5, "x")
try(table.insert, {1, 2}, "2", "x")
-- metatables: '__len', '__index', '__newindex' are used, a plain metatable changes nothing
local log = {}
local proxy = setmetatable({}, {
  __len = function () log[#log + 1] = "len"; return 2 end,
  __index = function (_, k) log[#log + 1] = "index " .. k; return "old" .. k end,
  __newindex = function (_, k, v) log[#log + 1] = "newindex " .. k .. "=" .. tostring(v) end,
})
table.insert(proxy, "v")
table.insert(proxy, 1, "w")
print(table.concat(log, "; "))
local plainMeta = setmetatable({1, 2}, {})
table.insert(plainMeta, 3)
print(list(plainMeta), #plainMeta)
local lenOnly = setmetatable({}, {__len = function () return 10 end})
table.insert(lenOnly, "x")
print(lenOnly[11], rawlen(lenOnly))
try(table.insert, setmetatable({}, {__len = function () return 1.5 end}), "x")
try(table.insert, setmetatable({}, {__len = function () return "3" end}), "x")
print(pcall(table.insert, setmetatable({}, {__newindex = function () error("no insert") end}), 1))
local userLike = setmetatable({}, {__index = {}, __newindex = rawset, __len = rawlen})
print(pcall(table.insert, userLike, "u"))

print("-- remove")
local s = {1, 2, 3}
print(table.remove(s), table.remove(s), list(s), #s)
print(table.remove(s), table.remove(s), table.remove(s), #s)
local z = {[0] = "zero"}                   -- #z == 0: removes z[0]
print(table.remove(z), z[0])
local n = {n = 1}
print(table.remove(n), n.n)
print(table.remove({1, 2, 3}, nil))        -- nil position: the border
local p = {10, 20, 30, 40}
print(table.remove(p, 1), list(p))
print(table.remove(p, #p + 1), list(p))
print(table.remove(p, 2.0), list(p))
try(table.remove, p, 5)
try(table.remove, p, -1)
try(table.remove, p, 1.5)
try(table.remove, nil)
try(table.remove)
try(table.remove, "abc")
log = {}
table.remove(proxy)
table.remove(proxy, 1)
print(table.concat(log, "; "))
local withMeta = setmetatable({1, 2, 3}, {})
print(table.remove(withMeta), list(withMeta))
local holes2 = {1, 2, nil, 4}
print(table.remove(holes2), #holes2)

print("-- concat")
local words = {"a", "b", "c"}
print(table.concat(words), table.concat(words, ", "), table.concat(words, nil))
print(table.concat({}), table.concat({}, "x"), table.concat({1, 2, 3}, "-"))
print(table.concat({1, "two", 3.0, 4.5, -0.0, math.mininteger}, " "))
print(table.concat({math.maxinteger, 2^63, 1e100}, ";"))
print(table.concat(words, 1), table.concat(words, 2.5))   -- number separators
print(table.concat(words, ",", 2), table.concat(words, ",", 2, 3), table.concat(words, ",", 3, 2))
print(table.concat({"x", "y"}, "", 1, 2), table.concat({[0] = "z", "x"}, "|", 0))
try(table.concat, {1, {}, 3})
try(table.concat, {1, nil, 3})
try(table.concat, {true})
try(table.concat, {"a", "b"}, {})
try(table.concat, nil)
try(table.concat, "abc")
try(table.concat, {"a"}, ",", 1, 3)
try(table.concat, {"a"}, ",", "x")
print(table.concat(setmetatable({}, {__index = function (_, i) return "v" .. i end, __len = function () return 3 end}), "+"))
print(table.concat(setmetatable({"a", "b"}, {}), "+"))
print(table.concat(setmetatable({"a", "b"}, {__len = function () return 1 end}), "+"))
print(table.concat(setmetatable({"a", nil, "c"}, {__index = function () return "?" end}), "+"))
local big = {}
for i = 1, 10000 do big[i] = i % 10 end
print(#table.concat(big), #table.concat(big, ", "))
print(table.concat({"a\0b", "c"}, "\0") == "a\0b\0c")

print("-- unpack")
show(table.unpack({1, 2, 3}))
show(table.unpack({}))
show(table.unpack({1, nil, 3}))
show(table.unpack({1, 2, 3}, 2))
show(table.unpack({1, 2, 3}, 2, 5))
show(table.unpack({1, 2, 3}, 3, 1))
show(table.unpack({1, 2, 3}, -1, 1))
show(table.unpack({[0] = "z"}, 0, 0))
show(table.unpack({1, 2, 3}, nil, 2))
show(table.unpack(setmetatable({}, {__index = function (_, i) return i * 2 end, __len = function () return 3 end})))
show(table.unpack(setmetatable({1, 2}, {})))
show(table.unpack(setmetatable({1, 2}, {__len = function () return 4 end})))
show(table.unpack("abc"))
show(table.unpack({1, 2}, math.maxinteger, math.maxinteger))
show(table.unpack({1, 2}, math.mininteger, math.mininteger))
try(table.unpack, {}, 1, 1e8)
try(table.unpack, {}, math.mininteger, math.maxinteger)
try(table.unpack, nil)
try(table.unpack)
try(table.unpack, {}, 1.5)
local many = {}
for i = 1, 200000 do many[i] = i end
print(select("#", table.unpack(many)), (select(200000, table.unpack(many))))
many[1000000] = 1
for i = 200001, 999999 do many[i] = i end
try(table.unpack, many)
