-- File names are plain paths, as C's fopen sees them: "php://memory",
-- "data:..." or "compress.zlib://./x" name files in directories called
-- "php:", "data:..." and "compress.zlib:" (none exists here), never PHP
-- stream wrappers (which would read memory, inline data or another file,
-- or make network requests for "http://..."). This file exists, so
-- "compress.zlib://./" plus its name would find it through the wrapper.

local self = "file_names_are_plain_paths"
local through_wrapper = "compress.zlib://./" .. self .. ".lua"

-- loadlib.c: searchpath's readable() (fopen)
print(package.searchpath("unused", "php://memory"))
print(package.searchpath("unused", "data:text/plain,hello"))
print(package.searchpath("unused", "http://127.0.0.1:9/?.lua"))
print(package.searchpath(self, "compress.zlib://./?.lua"))
print(package.searchpath(self, "compress.zlib://./?.lua;./?.lua"))

-- require's Lua searcher
package.path = "compress.zlib://./modules/?.lua"
print(pcall(require, "greet"))

-- luaL_loadfilex: loadfile and dofile
print(loadfile(through_wrapper))
print(pcall(dofile, through_wrapper))
print(loadfile("data:text/plain,return 1"))

-- liolib.c and loslib.c already open plain paths
print(io.open(through_wrapper))
print(io.open("php://memory", "w"))
print(pcall(io.lines, through_wrapper))
print(os.remove(through_wrapper))
print(os.rename(through_wrapper, "compress.zlib://./copy"))
