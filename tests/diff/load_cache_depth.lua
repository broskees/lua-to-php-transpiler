-- load() keeps compiled chunks in a cache (LoadCache). A chunk cached at a
-- shallow C-call depth must still fail where compiling it fails: its parser
-- nesting reaches LUAI_MAXCCALLS ("C stack overflow"), exactly as a fresh
-- compile does, message handler included.

local source = "return " .. string.rep("(", 60) .. "1" .. string.rep(")", 60)
print(load(source)())  -- compiled, then cached

-- every level is one more C call (pcall)
local function at(depth)
  if depth == 0 then
    local f, message = load(source)
    return f and "ok" or message
  end
  local ok, result = pcall(at, depth - 1)
  return result
end
for _, depth in ipairs{0, 1, 100, 134, 135, 136, 137, 150} do print(depth, at(depth)) end
print(load(source)())  -- still usable at a shallow depth

-- the same with a message handler at every level
local function handler(message) return "handled: " .. message end
local function handled(depth)
  if depth == 0 then
    local f, message = load(source)
    return f and "ok" or message
  end
  local ok, result = xpcall(handled, handler, depth - 1)
  return result
end
for _, depth in ipairs{1, 134, 135, 136} do print(depth, handled(depth)) end

-- failing first, then succeeding
local other = "local t = " .. string.rep("{", 70) .. string.rep("}", 70) .. " return #t"
print(at(140))
local f, message = load(other)
print(type(f), message)
