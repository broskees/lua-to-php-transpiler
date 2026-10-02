-- C counts the C calls of a coroutine from the resume (ldo.c: lua_resume:
-- the resumer's count + 1): a yield unwinds the C calls in between, and
-- the code that continues after the resume runs from continuations that
-- count no level. So a coroutine may nest pcalls or metamethod calls
-- deeper than LUAI_MAXCCALLS (200) as long as it yields in between. The
-- same when a pcall inside a coroutine recovers from an error (ldo.c:
-- precover): the count goes back to the resume's.

local function deepAcrossYields(depth, nest)
  local co = coroutine.create(function ()
    local function f(n)
      coroutine.yield()
      if n > 0 then nest(f, n - 1) end
    end
    f(depth)
    return "done"
  end)
  local resumes = 0
  while true do
    local ok, value = coroutine.resume(co)
    resumes = resumes + 1
    if not ok or value == "done" then return resumes, ok, value end
  end
end

print("pcalls", deepAcrossYields(300, function (f, n)
  local ok, err = pcall(f, n)
  if not ok then error(err, 0) end
end))
print("metamethods", deepAcrossYields(300, function (f, n)
  local _ = setmetatable({}, {__index = function () f(n) end}).field
end))
print("for iterators", deepAcrossYields(300, function (f, n)
  for _ in function (_, done) if not done then f(n) return true end end do end
end))

-- how many nested '__index' calls fit before "C stack overflow"
local function indexDepth()
  local depth = 0
  local t = setmetatable({}, {__index = function (t)
    depth = depth + 1
    return t.field
  end})
  local ok, err = pcall(function () return t.field end)
  return depth, err
end

local function nestedIndexes(n, inner)  -- runs inner() n '__index' calls deep
  if n == 0 then return inner() end
  return setmetatable({}, {__index = function () return nestedIndexes(n - 1, inner) end}).field
end

print("main thread", indexDepth())
print("coroutine", coroutine.wrap(indexDepth)())
local co = coroutine.wrap(function ()
  return nestedIndexes(100, function ()
    coroutine.yield()
    return indexDepth()
  end)
end)
co()
print("coroutine resumed after a yield 100 calls deep", co())
print("coroutine after a pcall recovered", coroutine.wrap(function ()
  return nestedIndexes(100, function ()
    pcall(error, "recovered")
    return indexDepth()
  end)
end)())
print("main thread after a pcall recovered", nestedIndexes(100, function ()
  pcall(error, "recovered")
  return indexDepth()
end))
-- calls that a yield left pending return normally, and the count is the
-- resume's again
co = coroutine.wrap(function ()
  nestedIndexes(150, coroutine.yield)
  return indexDepth()
end)
co()
print("after the pending calls returned", co())
