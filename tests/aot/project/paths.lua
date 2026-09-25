-- "mod" and "mod.lua" both map to mod.php: the precompiled file belongs to
-- mod.lua only, so "mod" is a file that does not exist
package.path = "./?;./?.lua"
print(package.searchpath("mod", package.path))
local f, e = loadfile("mod")
print(f and f() or nil, e)
print(require("mod"))
print(pcall(dofile, "mod"))
