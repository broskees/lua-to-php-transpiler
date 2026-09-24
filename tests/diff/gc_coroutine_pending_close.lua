-- while a '__close' method suspended by a yield runs during a pcall's
-- unwinding (ldo.c: precover), the frames abandoned by the error still
-- keep their other to-be-closed values: a collection must not clear them
collectgarbage("stop")
local weak = setmetatable({}, {__mode = "v"})
local co = coroutine.wrap(function ()
  local ok, err = pcall(function ()
    local first <close> = setmetatable({}, {__close = function (o) print("closing first", weak[1] == o) end})
    weak[1] = first
    local second <close> = setmetatable({}, {__close = function () coroutine.yield("suspended in __close") end})
    error("boom", 0)
  end)
  return "pcall returned " .. tostring(ok) .. " " .. err
end)
print(co())
collectgarbage()
print("after collect", weak[1] ~= nil)
print(co())
