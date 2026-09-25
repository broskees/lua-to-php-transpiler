-- A small project for bin/lua2php's directory mode (tests/unit/AheadOfTimeTest.php):
-- modules in subdirectories, an init.lua, a module with a syntax error, one
-- that fails while loading, a package.path change, dofile/loadfile of project
-- files. Run from this directory; the transpiled project must behave exactly
-- like `lua5.4 main.lua`.

local util = require "lib.util"
print(util.greet("world"), package.loaded["lib.util"] == util, util.loadedAs)

local shapes, where = require "lib.shapes"
print(shapes.area(3), where, shapes.loadedAs)
print(require "lib.shapes" == shapes)

print(pcall(require, "broken"))
print(pcall(require, "failing"))
print(pcall(require, "no.such.module"))

package.path = "./vendor/?.lua;" .. package.path
local extra, extraWhere = require "extra"
print(extra.name, extraWhere, package.searchpath("extra", package.path))

print(dofile("data/config.lua").answer)
local chunk, message = loadfile("data/config.lua")
print(type(chunk), message, chunk().answer)
print(loadfile("data/config.lua", "b"))
print(loadfile("data/nope.lua"))
print(pcall(dofile, "data/nope.lua"))
print(loadfile("broken.lua"))
print(loadfile("./broken.lua", "t"))

-- helper.php exists, but lua2php did not generate it: there is no module
print(pcall(require, "helper"))
print(pcall(dofile, "helper.lua"))

-- parser nesting: a module fails to load where compiling it would
local function at(depth, f)
  if depth == 0 then return f() end
  local ok, result = pcall(at, depth - 1, f)
  return result
end
for _, depth in ipairs{1, 132, 133, 134, 135, 136} do
  print(depth, at(depth, function ()
    package.loaded["lib.deep"] = nil
    return select(2, pcall(require, "lib.deep"))
  end), at(depth, function () return select(2, pcall(dofile, "lib/deep.lua")) end))
end

local info = debug.getinfo(util.greet, "S")
print(info.source, info.short_src, info.linedefined)
print(pcall(util.fail, "caught"))

util.fail("uncaught")  -- error with traceback on stderr, exit status 1
