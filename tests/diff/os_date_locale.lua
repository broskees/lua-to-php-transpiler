-- os.date in the LC_TIME locale os.setlocale sets (strftime: names,
-- AM/PM and the %c %x %X %r formats come from the locale). Locales that
-- are not installed give nil from os.setlocale, and the C locale's names.

local conversions = {
  "a", "A", "b", "B", "c", "C", "d", "D", "e", "F", "g", "G", "h", "H", "I", "j", "m", "M", "n", "p",
  "r", "R", "S", "t", "T", "u", "U", "V", "w", "W", "x", "X", "y", "Y", "z", "Z", "%",
  "Ec", "EC", "Ex", "EX", "Ey", "EY",
  "Od", "Oe", "OH", "OI", "Om", "OM", "OS", "Ou", "OU", "OV", "Ow", "OW", "Oy",
}
local format = "!"
for _, conversion in ipairs(conversions) do
  format = format .. "%" .. conversion .. "|"
end

local times = {
  0,                    -- Thursday 1970-01-01 00:00:00
  951782400 + 13 * 3600 + 5 * 60 + 9,  -- Tuesday 2000-02-29 13:05:09
  1700000000,           -- Tuesday 2023-11-14 22:13:20
  1719835200,           -- Monday 2024-07-01 12:00:00
  -2208988800 + 86399,  -- Monday 1900-01-01 23:59:59
}

local function show(locale, category)
  print(locale, category, os.setlocale(locale, category))
  for _, t in ipairs(times) do
    print(os.date(format, t))
  end
end

for _, locale in ipairs{"C", "POSIX", "C.UTF-8", "en_US.utf8", "en_US.UTF-8", "de_DE.utf8", "fr_FR.utf8",
                        "ja_JP.utf8", "th_TH.utf8"} do
  show(locale, "time")
  os.setlocale("C", "time")
end
show("en_US.utf8", "all")
-- another category leaves os.date alone
os.setlocale("C")
show("en_US.utf8", "numeric")
os.setlocale("C")
print(os.date("!%c", 0))
