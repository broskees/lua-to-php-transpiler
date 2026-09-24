-- print flushes stdout after each call (lauxlib.h: lua_writeline calls
-- fflush(stdout)); io.write leaves its output in stdout's buffer (fully
-- buffered, as stdout is a file here), so child processes started by
-- os.execute and io.popen, which write to the same stdout directly, get
-- ahead of it.

print("first print")
os.execute("echo from os.execute 1")
io.write("io.write without newline; ")
io.write("io.write with newline\n")
os.execute("echo from os.execute 2")
print("second print (flushes the io.write text)")
os.execute("echo from os.execute 3")

local reader = io.popen("echo from io.popen read")
print(reader:read("a"))
reader:close()

local writer = io.popen("cat", "w")
writer:write("from io.popen cat\n")
io.write("io.write before the pipe closes\n")
writer:close()
print("after io.popen cat")

print("print", "with", 3, "values")
io.write("io.write left in the buffer at exit\n")
os.execute("echo from os.execute 4")
