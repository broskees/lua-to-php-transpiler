-- liolib.c: files, read formats, write, seek, buffering, lines, errors

local name = os.tmpname()
local other = os.tmpname()

local function show(...)
  local t = table.pack(...)
  for i = 1, t.n do t[i] = tostring(t[i]) end
  print(table.concat(t, " | ", 1, t.n))
end

local function writefile(contents)
  local f = assert(io.open(name, "wb"))
  assert(f:write(contents))
  assert(f:close())
end

-- handles, types, tostring
print(io.type(io.stdin), io.type(io.stdout), io.type(io.stderr), io.type(42), io.type({}))
print(getmetatable(io.stdout).__name, type(io.stdout))
show(io.stdin:close())
show(io.close(io.stderr))
show(pcall(io.stdin.close))
show(pcall(io.type))

-- open modes
for _, mode in ipairs{"r", "w", "a", "r+", "w+", "a+", "rb", "wb", "r+b", "w+b", "rw", "rb+", "r+bk", "", "+", "b", "x", "r\0junk"} do
  local ok, f = pcall(io.open, name, mode)
  if ok and f then f:close() show(mode, "ok") else show(mode, ok, f) end
end

-- errors opening files
show(io.open("/nonexistent/dir/file"))
show(io.open("/nonexistent/dir/file", "w"))
show(io.open(""))
show(io.open("php://stdin"))
show(pcall(io.lines, "/nonexistent/file"))
show(pcall(io.input, "/nonexistent/file"))
show(pcall(io.open, {}))

-- writing numbers (LUA_INTEGER_FMT / LUA_NUMBER_FMT) and strings
writefile("")
local f = assert(io.open(name, "w"))
show(f:write(1, " ", -7, " ", 1.0, " ", 1.5, " ", 2^63, " ", -0.0, " ", 1e100, " ", 1/0, " ", -1/0, " ", math.mininteger, "\n") == f)
show(pcall(f.write, f, {}))
show(pcall(f.write, f, "a", nil))
f:close()
f = io.open(name)
show(f:read("a"))
f:close()

-- read formats
writefile("a line\nanother line\n1234\n3.45\none\ntwo\nthree\n")
f = io.open(name)
show(f:read("l", "L", "n", "n"))
f:close()
f = io.open(name)
show(f:read(7, "l", "n", "n", 1, "l", "l"))
f:close()
f = io.open(name)
show(f:read("l", "n", "n", "l"))
show(f:read("*l", "*L", "*a"))
show(f:read("a"), f:read("l"), f:read("L"), f:read(0), f:read(1), f:read("n"))
show(pcall(f.read, f, "x"))
show(pcall(f.read, f, "*"))
show(pcall(f.read, f, 1.5))
show(pcall(f.read, f, {}))
show(pcall(f.read, f, -1))
f:close()

-- numbers: termination, hex, exponents, too long, invalid sequences
local long = {"1234"}
for i = 1, 1000 do long[#long + 1] = "0" end
writefile("-12.3-\t-0xffff+  .3|5.E-3X  +234e+13E 0xDEADBEEFDEADBEEFx\n"
  .. "0x1.13Ap+3e\n" .. table.concat(long) .. "\n"
  .. ".e+\t0.e;\t--;  0xX;\n  0x   \n 9223372036854775807 9223372036854775808 -9223372036854775808 1e500 0x7fffffffffffffffff")
f = io.open(name)
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(2))
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(2))
show(f:read("n"), f:read(1))
show(f:read("n"), #f:read("L"))
show(f:read("n"), f:read(2))
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(2))
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(1))
show(f:read("n"), f:read(3))
show(f:read("n", "n", "n", "n", "n"))
show(f:read("n"), f:read(0))
f:close()

-- line endings and the L format
writefile("\n\nline\nother")
f = io.open(name)
show(f:read("L"), f:read("L"), f:read("L"), f:read("L"), f:read("L"))
f:close()
local s = ""
for l in io.lines(name, "L") do s = s .. "[" .. l .. "]" end
print(s)
s = ""
for l in io.lines(name) do s = s .. "[" .. l .. "]" end
print(s)
for a, b in io.lines(name, 1, 2) do show(a, b) end
for a, b, c in io.lines(name, "a", 0, 1) do show(a, b, c) break end

-- the lines iterator closes the file it opened; then reports it
local iterator = io.lines(name)
while iterator() do end
show(pcall(iterator))
f = io.open(name)
local fileIterator = f:lines()
show(fileIterator(), fileIterator(), fileIterator(), fileIterator(), fileIterator())
show(io.type(f))
f:close()
show(pcall(fileIterator))
show(pcall(io.lines, name, "x"))
show(pcall(io.lines(name, "x")))
local formats = {}
for i = 1, 251 do formats[i] = "l" end
show(pcall(io.lines, name, table.unpack(formats)))
formats[251] = nil
show(#{io.lines(name, table.unpack(formats))()})

-- breaking out of a generic for closes the file (4th value is to-be-closed)
local captured
do
  local it, state, control, closing = io.lines(name)
  captured = closing
  show(it ~= nil, state, control, io.type(closing))
end
for l in io.lines(name) do break end
show(io.type(captured))
captured:close()

-- seek
writefile("0123456789")
f = io.open(name, "r+")
show(f:seek(), f:seek("set", 3), f:read(2), f:seek("cur"), f:seek("cur", -1), f:read(1), f:seek("end"), f:seek("end", -2), f:read("a"))
show(f:seek("set", -5))
show(pcall(f.seek, f, "bogus"))
show(pcall(f.seek, f, "set", 1.5))
show(f:seek("set", 20), f:write("x"), f:seek("end"))
f:close()
f = io.open(name, "rb")
local contents = f:read("a")
show(#contents, contents:sub(1, 10), contents:byte(11), contents:sub(21))
f:close()

-- read and write in update modes
writefile("hello world\n")
f = io.open(name, "r+")
show(f:read(5), f:write("XX"), f:seek("cur"), f:read("l"))
f:seek("set")
show(f:read("a"))
f:close()
f = io.open(name, "w+")
show(f:write("abc"), f:seek("set", 1), f:read(1), f:read("a"))
f:close()
f = io.open(name, "a+")
show(f:seek(), f:read("a"), f:write("tail"), f:seek("set"), f:read("a"))
f:close()
f = io.open(name, "a")
show(f:seek(), f:write("more"), f:seek("set", 0), f:write("!"))
f:close()
f = io.open(name)
show(f:read("a"))
f:close()

-- wrong direction: error triples
f = io.open(name, "w")
show(f:read())
show(f:read("a"))
show(pcall(f:lines()))
f:close()
f = io.open(name, "r")
show(f:write("x"))
show(f:write(1))
f:close()

-- reading a directory
f = io.open(".")
show(io.type(f), f:read(1))
show(f:read("l"))
f:close()

-- buffering, observed through a second handle
do
  local w = assert(io.open(name, "w"))
  local r = assert(io.open(name, "r"))
  show(w:setvbuf("full", 2000))
  w:write("x")
  show(r:read("a"))
  w:close()
  r:seek("set")
  show(r:read("a"))
  w = assert(io.open(name, "a"))
  show(w:setvbuf("no"))
  w:write("y")
  r:seek("set")
  show(r:read("a"))
  show(w:setvbuf("line"))
  w:write("z")
  r:seek("set")
  show(r:read("a"))
  w:write("1\n2")
  r:seek("set")
  show(r:read("a"))
  w:flush()
  r:seek("set")
  show(r:read("a"))
  show(pcall(w.setvbuf, w, "bogus"))
  show(pcall(w.setvbuf, w))
  w:close()
  r:close()
end

-- glibc's buffering rules, seen through the size of what reached the file
do
  local w = assert(io.open(name, "w"))
  local r = assert(io.open(name, "r"))
  local sizes = {}
  local function visible() r:seek("set") sizes[#sizes + 1] = #r:read("a") end
  w:setvbuf("line")
  for _, piece in ipairs{"a\nb", "c", "d\ne\nf", "g", "h\n", "i"} do w:write(piece) visible() end
  w:write("j", "\n", "k") visible()
  w:setvbuf("full")
  w:write(string.rep("x", 5000)) visible()
  w:write(string.rep("y", 5000)) visible()
  w:close() visible()
  w = assert(io.open(name, "w"))
  for _, n in ipairs{4095, 1, 1, 10000, 100, 8192} do w:write(string.rep("z", n)) visible() end
  w:setvbuf("no") visible()
  w:write("abc") visible()
  w:setvbuf("line") w:write("x") visible()
  w:write("1\n2") visible()
  w:setvbuf("full") w:write("more") visible()
  w:write(string.rep("m", 300)) visible()
  w:close() visible()
  w = assert(io.open(name, "w"))
  w:setvbuf("line")
  w:write(string.rep("L", 5000) .. "\n" .. string.rep("T", 10)) visible()
  w:write("u\nv") visible()
  w:close()
  r:close()
  print(table.concat(sizes, " "))
end

-- closed files
f = io.open(name)
show(f:close())
show(tostring(f), io.type(f))
for _, method in ipairs{"read", "write", "lines", "flush", "seek", "setvbuf", "close"} do
  show(method, pcall(f[method], f))
end
show(pcall(io.close, f))
show(pcall(tostring, setmetatable({}, getmetatable(io.stdout))))
show(pcall(io.stdout.write, {}))

-- default input and output
writefile("first\nsecond\n3 4\n")
show(io.input() == io.stdin, io.output() == io.stdout)
io.input(name)
show(io.read(), io.read("L"), io.read("n", "n"), io.read("a"), io.read("l"))
show(pcall(io.read, "x"))
show(io.input():close())
show(pcall(io.read))
show(pcall(io.lines))
io.input(io.stdin)
io.output(other)
show(io.write("to other ", 1, "\n") == io.output())
show(io.output():seek())
show(io.close())
show(pcall(io.write, "x"))
show(pcall(io.flush))
show(pcall(io.close))
io.output(io.stdout)
io.input(other)
show(io.read("a"))
io.input():close()
io.input(io.stdin)
show(pcall(io.input, {}))
show(pcall(io.input, 42))  -- a number is a file name (for io.output it would create the file)

-- tmpfile
f = io.tmpfile()
show(io.type(f), f:write("alo"), f:seek("set"), f:read("a"))
f:close()

-- standard output shares print's buffer; seek on it reports the position
io.write("io.write then ")
print("print")
io.stdout:write("method write\n")
show(io.stdout:seek())
show(io.stdout:flush() == true, io.flush() == true)

assert(os.remove(name))
assert(os.remove(other))
local missing, message, code = io.open(name)
show(missing, message == name .. ": No such file or directory", code)
