-- /dev/stdout, /dev/stderr, /dev/fd/N and /proc/self/fd/N name the
-- process's own descriptors (Linux opens these links itself), also when
-- the descriptor is a pipe, which has no path (stdout and stderr are
-- files or pipes as the test runner sets them up)

for _, name in ipairs{"/dev/stdout", "/dev/fd/1", "/proc/self/fd/1", "/dev/stderr", "/proc/self/fd/2"} do
  local f, message, code = io.open(name, "a")
  print(name, io.type(f), message, code)
  if f then
    print(f:write("[written to ", name, "]\n"))
    print(f:close())
  end
end
print(io.open("/proc/self/fd/99", "a"))
print(io.open("/dev/fd/99"))
io.stdout:flush()
