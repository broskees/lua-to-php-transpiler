-- os.time and os.date("*t") read and write their table's fields directly
-- when it has no metatable (OsLib's fast path); with one, through
-- __index and __newindex (lua_getfield, lua_setfield), in loslib.c's order

local t = {year = 2024, month = 7, day = 1, hour = 12, min = 30, sec = 12}
print(os.time(t) - os.time{year = 2024, month = 7, day = 1, hour = 12, min = 30, sec = 12})
print(t.year, t.month, t.day, t.hour, t.min, t.sec, t.yday, t.wday, t.isdst)

local reads, writes = {}, {}
local fields = {year = 2000, month = 13, day = 32, hour = 25}
local proxy = setmetatable({}, {
  __index = function (_, key) reads[#reads + 1] = key return fields[key] end,
  __newindex = function (_, key, value) writes[#writes + 1] = key .. "=" .. tostring(value) end,
})
print(pcall(os.time, proxy))
print(table.concat(reads, " "))
print(table.concat(writes, " "))

-- a metatable whose __index gives a field, and a field set raw before
local partial = setmetatable({year = 1999}, {__index = {month = 2, day = 30}})
print(pcall(os.time, partial))
print(rawget(partial, "month"), rawget(partial, "day"), partial.yday, partial.isdst)

-- errors name the field, both ways
print(pcall(os.time, {year = 2000, month = "x", day = 1}))
print(pcall(os.time, setmetatable({}, {__index = {year = 2000, month = 1}})))
print(pcall(os.time, {year = 2000, month = 1, day = 1, hour = 2^40}))

local d = os.date("*t", 0)
print(getmetatable(d), d.year, d.month, d.day, d.hour, d.min, d.sec, d.yday, d.wday, d.isdst)
