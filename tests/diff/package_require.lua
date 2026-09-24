-- loadlib.c: require, package.searchers, package.searchpath, package.preload

print(require"string" == string, require"_G" == _G, require"package" == package)
print(package.loaded.package == package, package.loaded._G == _G)
print(type(package.path), type(package.cpath), type(package.preload), type(package.searchers))
print(package.config)
print(#package.searchers)
for i, searcher in ipairs(package.searchers) do print(i, type(searcher)) end

package.path = "modules/?.lua;modules/?/init.lua"
package.cpath = "modules/?.so;modules/?/c.so"

-- a Lua module: loader receives the name and the file name
local greet, where = require"greet"
print(greet.name, greet.path, greet.loads, where)
local again, where2 = require"greet"
print(again == greet, where2, greet.loads)

-- submodules: '.' becomes the directory separator
local sub, subWhere = require"sub"
print(sub.kind, sub.args[1], sub.args[2], subWhere)
local child, childWhere = require"sub.child"
print(child.kind, child.args[1], child.args[2], childWhere)

-- no return value: true; package.loaded set by the module wins
print(require"noreturn", NORETURN_RAN, package.loaded.noreturn)
print(require"sets_loaded", package.loaded.sets_loaded)
print(require"returns_false", falses, package.loaded.returns_false)
print(require"returns_false", falses)

-- errors while loading
print(pcall(require, "runtime_error"))
print(pcall(require, "syntax_error"))
print(pcall(require, "no_such_module"))
print(pcall(require, "no.such.sub"))
print(pcall(require))
print(pcall(require, {}))

-- preload
package.preload.pre = function (...)
  print("preload loader", ...)
  return {preloaded = true}
end
local pre, preWhere = require"pre"
print(pre.preloaded, preWhere)
package.preload.pre = nil
package.loaded.pre = nil
print(pcall(require, "pre"))

-- custom searchers
local searchers = package.searchers
package.searchers = {
  function (name) return "\n\tfirst searcher says no" end,
  function (name) return nil end,
  function (name) return 42 end,
}
print(pcall(require, "custom"))
package.searchers = {
  function (name) return function (...) return {...} end, {"data"} end,
}
local custom, data = require"custom"
print(custom[1], type(custom[2]), data[1])
package.searchers = 3
print(pcall(require, "whatever"))
package.searchers = searchers

-- package.path must be a string (numbers are converted)
local oldpath = package.path
package.path = {}
print(pcall(require, "no-such-file"))
package.path = 12
print(pcall(require, "no-such-file"))
package.path = oldpath

-- searchpath
print(package.searchpath("greet", package.path))
print(package.searchpath("sub.child", package.path))
print(package.searchpath("sub.child", "modules/?.lua", ""))
print(package.searchpath("sub-child", "modules/?.lua", "-", "/"))
print(package.searchpath("a.b.c", "x/?;y/?", ".", "::"))
print(package.searchpath("x", ""))
print(package.searchpath("x", ";"))
print(package.searchpath("x", ";a;"))
print(package.searchpath("modules", "?"))
print(package.searchpath("x", "??\0?"))
print(pcall(package.searchpath, "x"))
print(pcall(package.searchpath))
print(pcall(package.searchpath, nil, nil, nil, {}))

-- the documented 'require' message format
package.path = "?.lua;?/?"
package.cpath = "?.so;?/init"
local st, msg = pcall(require, 'XXX')
print(msg)

-- require keeps working through its upvalue when 'package' is replaced
local p = package
package = {}
p.preload.pl = function (...)
  local _ENV = {...}
  function xuxu (x) return x + 20 end
  return _ENV
end
local pl, ext = require"pl"
print(require"pl" == pl, pl.xuxu(10), pl[1], pl[2], ext)
package = p

-- package.loaded with an __index metamethod is honoured
setmetatable(package.loaded, {__index = function (t, k) if k == "virtual" then return "from __index" end end})
print(require"virtual")
setmetatable(package.loaded, nil)
