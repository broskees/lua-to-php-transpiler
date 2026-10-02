-- every conversion os.date accepts, in the C locale, for times across the
-- whole range of years (negative years, year 0, the int limits of tm_year)

local conversions = {
  "a", "A", "b", "B", "c", "C", "d", "D", "e", "F", "g", "G", "h", "H", "I", "j", "m", "M", "n", "p",
  "r", "R", "S", "t", "T", "u", "U", "V", "w", "W", "x", "X", "y", "Y", "z", "Z", "%",
  "Ec", "EC", "Ex", "EX", "Ey", "EY",
  "Od", "Oe", "OH", "OI", "Om", "OM", "OS", "Ou", "OU", "OV", "Ow", "OW", "Oy",
}
local format = ""
for _, conversion in ipairs(conversions) do format = format .. "%" .. conversion .. "|" end

local times = {
  0, -1, 43200, 86399, 951782400 + 13 * 3600, 1700000000, 1719835200 - 1, 4102444800,
  -62135596800, -62135596801, -62167219200, -62198755200, -2208988800, -11644473600,
  1 << 40, -(1 << 40), 67767976233532799, -67768040609740800, 1 << 55, -(1 << 55),
}
-- the first and last days of years, for the week numbers
for _, year in ipairs{1999, 2000, 2004, 2009, 2010, 2020, 2021, 2100, -1, -100, -401} do
  for _, day in ipairs{1, 2, 3, 4, 362, 365, 366} do
    times[#times + 1] = ((year - 1970) * 365 + (day - 1)) * 86400  -- (near that day: leap days ignored)
  end
end
for _, t in ipairs(times) do
  print(t, pcall(os.date, "!" .. format, t))
end
print(pcall(os.date, "!%c", 1 << 60))
