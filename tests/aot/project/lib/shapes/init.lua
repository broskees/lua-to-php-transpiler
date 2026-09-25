-- found through ./?/init.lua
local name, file = ...
return {
  loadedAs = name .. " from " .. file,
  area = function (side) return side * side end,
}
