-- errno of failing file operations (liolib.c, loslib.c, lauxlib.c:
-- luaL_fileresult, luaL_loadfilex): the kernel's reason, whatever the
-- path lookup meets (a file used as a directory, a symbolic link loop, a
-- name too long, a directory without permissions, another file system)

local root = os.tmpname()
assert(os.remove(root))
local function sh (command) assert(os.execute(command)) end
sh("mkdir " .. root)
sh("cd " .. root .. " && printf 'return 1\\n' > file && mkdir dir emptydir nonempty"
  .. " && printf x > nonempty/f && printf x > noperm && chmod 000 noperm"
  .. " && mkdir nopermdir && printf x > nopermdir/x && chmod 000 nopermdir"
  .. " && mkdir nosearch && printf x > nosearch/x && chmod 600 nosearch"
  .. " && mkdir readonlydir && printf x > readonlydir/x && chmod 500 readonlydir"
  .. " && ln -s loop2 loop1 && ln -s loop1 loop2 && ln -s selfloop selfloop"
  .. " && ln -s missing dangling && ln -s file goodlink && ln -s dir dirlink")

local long256 = string.rep("a", 256)
local longpath = root .. "/" .. string.rep("./", 2100) .. "file"

-- messages name the fixture directory; print them without it
local function clean (v)
  if type(v) ~= "string" then return tostring(v) end
  local i, j = v:find(longpath, 1, true)
  if i then v = v:sub(1, i - 1) .. "<long path>" .. v:sub(j + 1) end
  i, j = v:find(root, 1, true)
  if i then v = v:sub(1, i - 1) .. "<root>" .. v:sub(j + 1) end
  i, j = v:find(long256, 1, true)
  if i then v = v:sub(1, i - 1) .. "<256 bytes>" .. v:sub(j + 1) end
  return v
end
local function show (...)
  local t = table.pack(...)
  for i = 1, t.n do
    t[i] = io.type(t[i]) and "file" or type(t[i]) == "function" and "function" or clean(t[i])
  end
  print(table.concat(t, " | ", 1, t.n))
end

local names = {"missing", "missing/x", "file/x", "file/", "goodlink/x", "dir", "dir/",
  "dirlink/", "noperm", "nopermdir", "nopermdir/x", "nosearch/x", "readonlydir/new",
  long256, "dir/" .. long256, "missing/" .. long256, long256 .. "/x", "loop1", "selfloop",
  "loop1/x", "dir/../loop1", "dangling/", "dir/./../file/"}

for _, mode in ipairs({"r", "w", "a", "r+", "w+", "a+"}) do
  print("io.open", mode)
  for _, name in ipairs(names) do
    local f, message, code = io.open(root .. "/" .. name, mode)
    if f then
      show(name, "opened", f:read(1))
      f:close()
    else
      show(name, f, message, code)
    end
  end
  show("<long path>", io.open(longpath, mode))
end
os.remove(root .. "/missing")  -- created by the modes that create
os.remove(root .. "/readonlydir/new")

print("io.lines, io.input, io.output, loadfile, dofile")
for _, name in ipairs(names) do
  local path = root .. "/" .. name
  show(name, "lines", pcall(io.lines, path))
  show(name, "input", pcall(io.input, path))
  io.input(io.stdin)
  if name ~= "missing" and name ~= "dangling/" then
    show(name, "output", pcall(io.output, path))
    io.output(io.stdout)
  end
  if name ~= "noperm" then  -- (lua2php output never opens an existing Lua file it did not transpile)
    show(name, "loadfile", loadfile(path))
    show(name, "dofile", pcall(dofile, path))
  end
end
os.remove(root .. "/missing")
show("<long path>", pcall(io.lines, longpath))
show("<long path>", loadfile(longpath))
-- file names are C strings: an embedded zero ends them
show("zero", loadfile(root .. "/missing\0junk"))
show("zero", pcall(dofile, root .. "/missing\0junk"))
show("zero", io.open(root .. "/missing\0junk"))

print("os.remove")
for _, name in ipairs({"missing", "missing/x", "file/x", "file/", "nonempty", "nopermdir/x",
    "nosearch/x", "readonlydir/x", "loop1/x", long256, "missing/" .. long256}) do
  show(name, os.remove(root .. "/" .. name))
end
show("<long path>", os.remove(longpath))

print("os.rename")
for _, names in ipairs({{"missing", "x"}, {"file/x", "x"}, {"file", "file/x"}, {"file", "dir"},
    {"dir", "file"}, {"emptydir", "nonempty"}, {"dir", "dir/sub"}, {"readonlydir/x", "readonlydir/y"},
    {"nosearch/x", "x"}, {"loop1/x", "x"}, {"file", "loop1/x"}, {"file", long256}, {long256, "x"}}) do
  show(names[1], names[2], os.rename(root .. "/" .. names[1], root .. "/" .. names[2]))
end
show("<long path>", os.rename(longpath, root .. "/x"))
-- rename(2) does not copy across file systems (/tmp and /dev/shm here)
local other = "/dev/shm/luaphp-io-errno-" .. root:match("[^/]*$")
show("file", "other fs", os.rename(root .. "/file", other))
show("missing", "other fs", os.rename(root .. "/missing", other))
show("dir", "other fs", os.rename(root .. "/dir", other))
show("source kept", io.open(root .. "/file") ~= nil, io.open(other) ~= nil)
os.remove(other)

sh("chmod -R u+rwx " .. root .. " && rm -rf " .. root)
