-- weak tables: which entries a full collection removes (lgc.c: clearbykeys,
-- clearbyvalues, traverseephemeron). Automatic collections are stopped so
-- only the explicit collectgarbage() calls clear entries.
collectgarbage("stop")

local function count (t)
  local n = 0
  for _ in pairs(t) do n = n + 1 end
  return n
end

local function keys (t)
  local list = {}
  for k in pairs(t) do list[#list + 1] = tostring(type(k) == "table" and "table" or k) end
  table.sort(list)
  return table.concat(list, " ")
end

-- weak keys: collectable keys go, strings, numbers and booleans stay
local wk = setmetatable({}, {__mode = "k"})
local kept = {}
wk[kept] = "kept"
for i = 1, 10 do wk[{}] = i end
wk[1] = "one"; wk["s"] = "string"; wk[true] = "bool"; wk[2.5] = "float"
wk[print] = "light C function"
collectgarbage()
print("weak keys", count(wk), wk[kept], wk[1], wk.s, wk[true], wk[2.5], wk[print])

-- weak values: collectable values go, whatever the key
local wv = setmetatable({}, {__mode = "v"})
local keptValue = {}
wv[1] = keptValue; wv[2] = {}; wv.x = {}; wv[{}] = {}; wv[kept] = kept
wv[3] = "string"; wv[4] = 42; wv[5] = print; wv[6] = function () end
wv[7] = coroutine.create(print)
collectgarbage()
print("weak values", count(wv), wv[1] == keptValue, wv[2], wv.x, wv[kept] == kept, wv[3], wv[4], wv[5] == print, wv[6], wv[7])

-- all weak
local kv = setmetatable({}, {__mode = "kv"})
local x, y = {}, {}
kv[x] = 1; kv[2] = y; kv[{}] = {}; kv[x] = y; kv.s = "v"
collectgarbage()
print("all weak", count(kv), kv[x] == y, kv[2] == y, kv.s)
x, y = nil, nil
collectgarbage()
print("all weak after", keys(kv))

-- a mode that is not a string, or has neither 'k' nor 'v', is strong
local notWeak = setmetatable({}, {__mode = 1})
notWeak[{}] = {}
local noLetters = setmetatable({}, {__mode = "xyz"})
noLetters[1] = {}
collectgarbage()
print("not weak", count(notWeak), count(noLetters))

-- changing __mode after creation takes effect at the next collection
local mt = {}
local changing = setmetatable({}, mt)
changing[1] = {}
collectgarbage()
print("before __mode", count(changing))
mt.__mode = "v"
collectgarbage()
print("after __mode", count(changing))
changing[1] = {}
mt.__mode = nil
collectgarbage()
print("after removing __mode", count(changing))
setmetatable(changing, {__mode = "k"})
changing[{}] = 1
collectgarbage()
print("after new metatable", count(changing))

-- ephemerons: a value reachable only through its own key does not keep
-- the key alive; chains through several tables converge
local e = setmetatable({}, {__mode = "k"})
do
  local k1 = {}
  e[k1] = {k1}            -- value refers to its key
  local chain = nil
  for i = 1, 20 do local n = {}; e[n] = {next = chain}; chain = n end
  _G.head = chain
end
collectgarbage()
local n, length = head, 0
while n do n = e[n].next; length = length + 1 end
print("ephemeron chain", length, count(e))
head = nil
collectgarbage()
print("ephemeron chain dropped", count(e))

local e1 = setmetatable({}, {__mode = "k"})
local e2 = setmetatable({}, {__mode = "k"})
do
  local a, b = {}, {}
  e1[a] = b; e2[b] = "reached through e1"
  _G.anchor = a
end
collectgarbage()
print("two ephemerons", count(e1), count(e2))
anchor = nil
collectgarbage()
print("two ephemerons dropped", count(e1), count(e2))

-- closures and coroutines are collectable keys and values
local objects = setmetatable({}, {__mode = "k"})
local keepFunction = function () return 1 end
local keepThread = coroutine.create(function () end)
objects[keepFunction] = 1; objects[function () end] = 2
objects[keepThread] = 3; objects[coroutine.create(function () end)] = 4
collectgarbage()
print("functions and threads", count(objects), objects[keepFunction], objects[keepThread])

-- a suspended coroutine keeps its locals alive; a dead one does not
local weakLocals = setmetatable({}, {__mode = "v"})
local co = coroutine.create(function ()
  local mine = {}
  weakLocals[1] = mine
  coroutine.yield()
  weakLocals[2] = "finished"
end)
coroutine.resume(co)
collectgarbage()
print("suspended coroutine", weakLocals[1] ~= nil)
coroutine.resume(co)
collectgarbage()
print("dead coroutine", weakLocals[1], weakLocals[2])

-- a coroutine only reachable from a weak table is collected, with its stack
local weakThreads = setmetatable({}, {__mode = "v"})
weakThreads[1] = coroutine.create(function () local t = {}; weakLocals[3] = t; coroutine.yield() end)
coroutine.resume(weakThreads[1])
collectgarbage()
print("unreachable coroutine", weakThreads[1], weakLocals[3])

-- coroutine.wrap keeps its coroutine
local wrapped = setmetatable({}, {__mode = "v"})
local gen = coroutine.wrap(function () local t = {}; wrapped[1] = t; coroutine.yield(1); coroutine.yield(t) end)
gen()
collectgarbage()
print("wrap", wrapped[1] ~= nil, gen() == wrapped[1])

-- entries cleared during a traversal: next continues from the cleared key
local traversed = setmetatable({}, {__mode = "k"})
for i = 1, 5 do traversed[{}] = i end
local seen = 0
for k, v in pairs(traversed) do
  seen = seen + 1
  collectgarbage()
end
print("traversal", seen >= 1, count(traversed))

-- a frame running a hook keeps all its registers, even the arguments of
-- the call about to be made (ldo.c: luaD_hook protects the whole frame)
local hookWeak = setmetatable({}, {__mode = "k"})
local function lookup (t) return hookWeak[t] end
local function make () local t = {}; hookWeak[t] = "alive"; return t end
debug.sethook(function () collectgarbage() end, "", 1)
local found = lookup(make())
debug.sethook()
print("hook", found)

-- the stack slots above a call are not roots
local slots = setmetatable({}, {__mode = "k"})
for i = 1, 5 do slots[{}] = i end
collectgarbage()
print("temporaries", count(slots))
