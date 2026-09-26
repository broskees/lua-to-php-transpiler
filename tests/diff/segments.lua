-- big functions: their emitted PHP is segments under a dispatcher
-- (FunctionEmitter::planSegments), and control passes between segments
-- through it; everything must behave as in one piece of code

-- n copies of a statement, # replaced by the copy's number
local function lines(n, template)
  local t = {}
  for i = 1, n do t[i] = (template:gsub("#", tostring(i))) end
  return table.concat(t, "\n")
end

-- loops whose bodies straddle segments, goto and break across them
local loops = assert(load([[
local n, acc = ...
for i = 1, n do
]] .. lines(80, "  acc = acc + i * # % 7") .. [[

  if i % 3 == 0 then goto continue end
]] .. lines(80, "  acc = acc - # // 2") .. [[

  ::continue::
end
local j = 0
while true do
  j = j + 1
]] .. lines(60, "  acc = acc ~ (j + #)") .. [[

  if j >= n then break end
end
repeat
  acc = acc + 1
]] .. lines(60, "  if acc % # == 0 then acc = acc + # end") .. [[

until acc % 5 == 0
return acc, j
]], "=loops"))
print("loops", loops(10, 0))
print("loops", loops(1, 100))

-- a generic for whose body straddles segments (OP_TFORPREP jumps to its
-- OP_TFORCALL past the hook check), a small loop inside a big function
local generic = assert(load([[
local t, acc = ...
for k, v in ipairs(t) do
]] .. lines(80, "  acc = acc + v * # + k") .. [[

end
for k, v in pairs({a = 1}) do acc = acc + v end
return acc
]], "=generic"))
print("generic", generic({3, 4, 5}, 0))

-- line, count, call and return hooks, and debug.getlocal from a hook
local lineCount, lineSum, localsSeen = 0, 0, {}
debug.sethook(function (event, line)
  local info = debug.getinfo(2, "S")
  if info.source ~= "=loops" then return end
  lineCount = lineCount + 1
  lineSum = lineSum + line * lineCount
  if line % 37 == 0 then
    local names = {}
    for i = 1, 10 do
      local name, value = debug.getlocal(2, i)
      if not name then break end
      if name ~= "(temporary)" then  -- (in C, the last ones are stale stack slots)
        names[#names + 1] = name .. "=" .. tostring(value)
      end
    end
    localsSeen[#localsSeen + 1] = line .. ":" .. table.concat(names, ",")
  end
end, "l")
loops(4, 0)
debug.sethook()
print("line hook", lineCount, lineSum)
for _, entry in ipairs(localsSeen) do print("", entry) end
for _, count in ipairs({1, 7, 100}) do
  local steps, sum = 0, 0
  debug.sethook(function ()
    local info = debug.getinfo(2, "Sl")
    if info.source == "=generic" then
      steps = steps + 1
      sum = sum + info.currentline * steps
    end
  end, "", count)
  generic({1, 2}, 0)
  debug.sethook()
  print("count hook", count, steps, sum)
end
local events = {}
debug.sethook(function (event)
  local info = debug.getinfo(2, "S")
  if info.source == "=generic" or info.what == "C" then events[#events + 1] = event end
end, "cr")
generic({1}, 0)
debug.sethook()
print("call hooks", table.concat(events, " "))

-- errors with their positions in later segments
local errors = assert(load([[
local x, what = ...
]] .. lines(150, "  x = x + #") .. [[

if what == "concat" then return x .. {} end
]] .. lines(150, "  x = x - #") .. [[

if what == "index" then local t = nil; return t.field end
]] .. lines(150, "  x = x * 1") .. [[

if what == "error" then error("raised at the end") end
return x
]], "=errors"))
for _, what in ipairs({"concat", "index", "error", "none"}) do
  print("error", what, pcall(errors, 0, what))
end
print("traceback", select(2, xpcall(errors, debug.traceback, 0, "index")))

-- to-be-closed variables declared in one segment and closed in another
local closing = assert(load([[
local log, fail = ...
do
  local a <close> = setmetatable({}, {__close = function (_, e) log[#log + 1] = "a " .. tostring(e) end})
]] .. lines(120, "  log.n = (log.n or 0) + #") .. [[

  do
    local b <close> = setmetatable({}, {__close = function (_, e) log[#log + 1] = "b " .. tostring(e) end})
]] .. lines(120, "    log.n = log.n + #") .. [[

    if fail then error("fail", 0) end
  end
]] .. lines(60, "  log.n = log.n - #") .. [[

end
return log.n
]], "=closing"))
for _, fail in ipairs({false, true}) do
  local log = {}
  print("close", fail, pcall(closing, log, fail))
  print("", table.concat(log, "; "), log.n)
end

-- yields from every part of a big function, and a loop that yields
local yielding = assert(load([[
local acc = 0
]] .. lines(60, "acc = acc + coroutine.yield(#)") .. [[

for i = 1, 3 do
]] .. lines(60, "  acc = acc + coroutine.yield(i * #)") .. [[

end
return acc
]], "=yielding"))
local co = coroutine.wrap(yielding)
local yields, sum, value = 0, 0, co()
while value ~= nil do
  yields = yields + 1
  sum = sum + value
  local result = co(yields % 5)
  if yields == 240 then print("returned", result) break end
  value = result
end
print("yields", yields, sum)
