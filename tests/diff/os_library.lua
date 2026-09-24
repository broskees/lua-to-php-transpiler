-- loslib.c: date, time, difftime, clock, getenv, remove, rename, tmpname,
-- execute, setlocale (os.exit has its own cases)

local function show(...)
  local t = table.pack(...)
  for i = 1, t.n do t[i] = tostring(t[i]) end
  print(table.concat(t, " | ", 1, t.n))
end

local function fields(t)
  return string.format("%s-%s-%s %s:%s:%s yday=%s wday=%s isdst=%s",
    t.year, t.month, t.day, t.hour, t.min, t.sec, t.yday, t.wday, t.isdst)
end

-- every conversion specifier, in UTC and in local time
local specifiers = {"a", "A", "b", "B", "c", "C", "d", "D", "e", "F", "g", "G", "h", "H", "I", "j",
  "m", "M", "n", "p", "r", "R", "S", "t", "T", "u", "U", "V", "w", "W", "x", "X", "y", "Y", "z", "Z", "%",
  "Ec", "EC", "Ex", "EX", "Ey", "EY", "Od", "Oe", "OH", "OI", "Om", "OM", "OS", "Ou", "OU", "OV", "Ow", "OW", "Oy"}
local times = {0, 1, -1, 86399, 951782400, 1230768000, 1262304000, 1293840000, 1600000000,
  -62135596800, -62170156800, -30610224000, 253402300800, 2^40, -2000000000000, 1710054000, 1730613600}
for _, t in ipairs(times) do
  local utc, localtime = {}, {}
  for _, s in ipairs(specifiers) do
    utc[#utc + 1] = s .. "=" .. os.date("!%" .. s, t)
    localtime[#localtime + 1] = s .. "=" .. os.date("%" .. s, t)
  end
  print(t, table.concat(utc, " "))
  print(t, table.concat(localtime, " "))
  print(t, fields(os.date("!*t", t)), fields(os.date("*t", t)))
end

-- formats that are not conversions
show(os.date("", 0), os.date("!", 0), os.date("\0\0", 0), os.date("!\0\0", 0), #os.date(string.rep("a", 10000), 0))
show(os.date("!*t\0junk", 0).year, os.date("! %Y %%Y", 86400 * 365 .. ""))
show(os.date(string.rep("%", 200), 0) == string.rep("%", 100))
show(os.date("!%Y-%m-%d", 1e9), os.date("!%H", 3600.0))

-- errors
for _, format in ipairs{"%", "%9", "%O", "%E", "%Ea", "%Ez", "%Oz", "%s", "%k", "%\0x", "abc%", "%E\0c", "%5d"} do
  show(pcall(os.date, format, 0))
end
show(pcall(os.date, "%Y", 1.5))
show(pcall(os.date, "%Y", "x"))
show(pcall(os.date, {}, 0))
show(pcall(os.date, "%Y", 2^60))
show(pcall(os.date, "!%Y", -2^60))
show(pcall(os.date, "*t", 2^60))

-- os.time: fields, defaults, normalization
show(os.time{year = 2000, month = 1, day = 1, hour = 0}, os.time{year = 2000, month = 1, day = 1})
show(os.time{year = 2000, month = 1, day = 1, hour = 0, isdst = false}, os.time{year = 2000, month = 7, day = 1, hour = 0})
local normalized = {year = 2005, month = 1, day = 1, hour = 1, min = 0, sec = -3602}
show(os.time(normalized), fields(normalized))
normalized = {year = 2020, month = 14, day = 31, hour = 25, min = 61, sec = 61}
show(os.time(normalized), fields(normalized))
normalized = {year = 2021, month = -13, day = -400, hour = -1}
show(os.time(normalized), fields(normalized))
normalized = {year = "2001", month = 2.0, day = "29"}
show(os.time(normalized), fields(normalized))
show(os.time{year = 1970, month = 1, day = 1, hour = 0, sec = (1 << 31) - 1}, os.time{year = 1970, month = 1, day = 0, sec = -(1 << 31)})
show(pcall(os.time, {year = 1970, month = 1, day = 1, sec = 1 << 31}))

-- isdst: nil means "whatever applies"; true/false shift by the zone's DST offset
for _, isdst in ipairs{"unset", true, false} do
  local winter = {year = 2020, month = 1, day = 15, hour = 12}
  local summer = {year = 2020, month = 7, day = 15, hour = 12}
  if isdst ~= "unset" then winter.isdst = isdst; summer.isdst = isdst end
  show(tostring(isdst), os.time(winter), fields(winter), os.time(summer), fields(summer))
end
-- wall clocks in the spring-forward gap and in the fall-back overlap (of zones with DST)
for _, t in ipairs{
  {year = 2024, month = 3, day = 10, hour = 2, min = 30},
  {year = 2024, month = 3, day = 10, hour = 2, min = 30, isdst = false},
  {year = 2024, month = 3, day = 10, hour = 2, min = 30, isdst = true},
  {year = 2024, month = 11, day = 3, hour = 1, min = 30},
  {year = 2024, month = 11, day = 3, hour = 1, min = 30, isdst = false},
  {year = 2024, month = 11, day = 3, hour = 1, min = 30, isdst = true},
} do
  show(os.time(t), fields(t))
end
do  -- a table from os.date round-trips
  local now = os.time()
  local d = os.date("*t", now)
  show(os.time(d) == now)
  d.isdst = nil
  show(os.time(d) == now)
end

-- os.time errors
show(pcall(os.time, {hour = 12}))
show(pcall(os.time, {year = 2000, day = 1}))
show(pcall(os.time, {year = 2000, month = 1}))
show(pcall(os.time, {year = 1000, month = 1, day = 1, hour = "x"}))
show(pcall(os.time, {year = 1000, month = 1, day = 1, hour = 1.5}))
show(pcall(os.time, {year = 1000, month = 1, day = 1, min = {}}))
show(pcall(os.time, {year = -(1 << 31) + 1899, month = 1, day = 1}))
show(pcall(os.time, {year = -(1 << 31), month = 1, day = 1}))
show(pcall(os.time, {year = (1 << 31) + 1900, month = 1, day = 1}))
show(pcall(os.time, {year = 0, month = 1, day = 2^32}))
show(pcall(os.time, {year = 0, month = -((1 << 31) + 1), day = 1}))
show(pcall(os.time, {year = 2^60, month = 1, day = 1}))
show(pcall(os.time, {year = -math.maxinteger, month = 1, day = 1}))
show(pcall(os.time, 5))
show(pcall(os.time, "x"))
show(math.type(os.time()), math.type(os.time(nil)))

-- difftime, clock
show(os.difftime(10, 3), os.difftime(3, 10), math.type(os.difftime(0, 0)), os.difftime(2^53, -2^53))
show(pcall(os.difftime, 1))
show(pcall(os.difftime, 1.5, 1))
show(math.type(os.clock()), os.clock() >= 0)

-- environment
show(type(os.getenv("PATH")), os.getenv("LUA_TEST_SURELY_NOT_SET"), os.getenv(""), os.getenv("PATH\0junk") == os.getenv("PATH"))
show(pcall(os.getenv))
show(pcall(os.getenv, {}))

-- files
show(os.remove("/nonexistent/dir/file"))
show(os.remove(""))
show(os.rename("/nonexistent/a", "/nonexistent/b"))
show(pcall(os.rename, "a"))
local name = os.tmpname()
show(#name, string.match(name, "^/tmp/lua_%w%w%w%w%w%w$") ~= nil, io.open(name) ~= nil)
local renamed = name .. "_renamed"
show(os.rename(name, renamed))
show(select("#", os.rename(name, renamed)), select(3, os.rename(name, renamed)))
show(os.remove(renamed))
show(select(3, os.remove(renamed)))
local directory = os.tmpname()
os.remove(directory)
show(os.execute("mkdir " .. directory))
show(os.remove(directory))
show(os.execute("mkdir " .. directory .. " && touch " .. directory .. "/inside"))
show(select(3, os.remove(directory)))
show(os.remove(directory .. "/inside"), os.remove(directory))

-- execute and popen
show(os.execute())
show(os.execute("exit 0"))
show(os.execute("exit 3"))
show(os.execute("exit 256"))
show(os.execute("kill -s TERM $$"))
io.stdout:flush()  -- lua.c's print flushes stdout; make sure ours has too before a child writes
show(os.execute("echo from execute"))
show(io.popen("exit 5"):close())
show(io.popen("kill -s INT $$"):close())
local p = io.popen("echo line one; echo line two")
show(io.type(p), p:read("l"), p:read("L"), p:read("a"), p:read("l"))
show(p:close())
show(pcall(p.close, p))
p = io.popen("cat", "w")
show(p:write("written to cat\n") == p)
io.stdout:flush()
show(p:close())
show(pcall(io.popen, "cat", "r+"))
show(pcall(io.popen, "cat", ""))
show(pcall(io.popen, "cat", "rw"))
show(pcall(io.popen))
for l in io.popen("printf 'a\\nb\\n'"):lines() do show("popen line", l) end

-- setlocale
show(os.setlocale(), os.setlocale(nil, "numeric"))
show(os.setlocale("C"), os.setlocale("POSIX"), os.setlocale("xx_YY.nothing"), os.setlocale("0"))
show(os.setlocale("C", "time"), os.setlocale("C", "ctype"), os.setlocale(nil, "collate"))
show(pcall(os.setlocale, "C", "bogus"))
show(pcall(os.setlocale, {}))
show(os.setlocale("C", "all"), os.setlocale())
