local M = {}
M.loadedAs = table.concat({...}, " ")

function M.greet(name)
  return "hello " .. name
end

function M.fail(message)
  error(message .. " in util")
end

return M
