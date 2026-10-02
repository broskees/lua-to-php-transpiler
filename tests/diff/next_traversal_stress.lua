-- Random interleavings of traversals of one table (no keys added), each
-- checked against next's contract: a traversal gives no key twice, never a
-- key deleted at that moment, and every key present from its start to its
-- end. The choices come from a generator of our own, so both interpreters
-- make the same ones; the output does not depend on traversal order.

local seed
local function random(n)  -- 1 .. n
  seed = (seed * 1103515245 + 12345) % 2147483648
  return seed // 65536 % n + 1
end

local universe = {}
for i = 1, 12 do universe[#universe + 1] = i end
for i = 1, 12 do universe[#universe + 1] = "s" .. i end
for _, k in ipairs({0.5, 1.5, -2.25, true, false, "10", "-3"}) do universe[#universe + 1] = k end
for i = 1, 4 do universe[#universe + 1] = setmetatable({}, {__name = "key" .. i}) end

local function newTable()
  local t = {}
  for i, k in ipairs(universe) do t[k] = i end
  return t
end

-- agents step through next from one shared function (iterator objects) or
-- from call sites of their own
local function sharedStep(t, key) return next(t, key) end
local ownSteps = {
  function (t, key) return next(t, key) end,
  function (t, key) return next(t, key) end,
  function (t, key) return next(t, key) end,
  function (t, key) return next(t, key) end,
}

local function run(rounds, ticks, shared)
  local failures = 0
  for _ = 1, rounds do
    local t = newTable()
    local agents = {}
    for i = 1, 4 do agents[i] = {done = true, step = shared and sharedStep or ownSteps[i]} end

    local function start(agent)
      agent.done = false
      agent.started = false
      agent.key = nil
      agent.visited = {}
      agent.presentAtStart = {}
      for _, k in ipairs(universe) do if t[k] ~= nil then agent.presentAtStart[k] = true end end
      agent.deleted = {}
    end

    local function step(agent)
      local k
      if agent.started then k = agent.step(t, agent.key) else k = agent.step(t, nil) end
      agent.started = true
      if k == nil then
        for key in pairs(agent.presentAtStart) do
          assert(agent.visited[key] or agent.deleted[key], "a key present throughout was not given")
        end
        agent.done = true
        return
      end
      assert(t[k] ~= nil, "a deleted key was given")
      assert(agent.presentAtStart[k], "a key added during the traversal")
      assert(not agent.visited[k], "a key given twice")
      agent.visited[k] = true
      agent.key = k
    end

    local function delete(k)
      if t[k] == nil then return end
      t[k] = nil
      for _, agent in ipairs(agents) do
        if not agent.done then agent.deleted[k] = true end
      end
    end

    local ok, message = pcall(function ()
      for _ = 1, ticks do
        local agent = agents[random(4)]
        local action = random(10)
        if agent.done then
          if action <= 6 then start(agent) end
        elseif action <= 5 then
          step(agent)
        elseif action == 6 then
          if agent.started and agent.key ~= nil then delete(agent.key) end
        elseif action == 7 then
          delete(universe[random(#universe)])
        elseif action == 8 then
          local first = next(t)
          assert(first == nil or t[first] ~= nil)
        elseif action == 9 then
          if agent.started and agent.key ~= nil then
            local following = next(t, agent.key)
            assert(following == nil or t[following] ~= nil)
          end
        else
          start(agent)  -- abandon its traversal, start another
        end
      end
      for _, agent in ipairs(agents) do  -- finish every traversal
        while not agent.done do step(agent) end
      end
    end)
    if not ok then
      failures = failures + 1
      if failures <= 3 then print("failure", message) end
    end
  end
  return failures
end

seed = 20261002
print("own call sites", run(150, 120, false))
seed = 4242
print("one shared call site", run(150, 120, true))
seed = 777
print("long rounds", run(20, 1500, false), run(20, 1500, true))
