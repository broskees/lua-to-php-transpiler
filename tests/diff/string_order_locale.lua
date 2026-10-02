-- String order follows LC_COLLATE (lvm.c l_strcmp: strcoll on each segment
-- between embedded '\0's). In the "C" locale it is byte order. Where the
-- locale is missing, os.setlocale returns nil in both implementations.
local function orderings(label)
  print(label, "a" < "B", "a" <= "B", "B" < "a", "a" > "B", "a" >= "B")
  print(label, "prefix\0a" < "prefix\0B", "prefix\0a" <= "prefix\0B", "x\0" < "x", "x" < "x\0", "x\0" <= "x\0")
  print(label, "a\0\0b" < "a\0\0B", "\0" < "", "" < "\0", "abc" < "abd", "abc" < "ab")
  local words = {"banana", "Apple", "cherry", "apple", "Banana", "a\0z", "a\0B", "_x", "1", "Zeta"}
  table.sort(words)
  print(label, (table.concat(words, " "):gsub("%z", "<0>")))
  table.sort(words, function (x, y) return x > y end)
  print(label, (table.concat(words, " "):gsub("%z", "<0>")))
  print(label, math.min("b", "A", "a"), math.max("b", "A", "a", "B"))
  -- comparisons through compact forms and constants (OP_LT/OP_LE between registers)
  local lower, upper = "a", "B"
  local count = 0
  for _ = 1, 3 do if lower < upper then count = count + 1 end end
  print(label, count, lower <= upper, not (upper < lower))
end

print(os.setlocale(nil, "collate"))
orderings("C")
local locale = os.setlocale("en_US.utf8", "collate")
print(locale)
orderings(locale and "en_US" or "missing")
print(os.setlocale("C", "collate"))
orderings("C again")
print(os.setlocale("en_US.utf8"))
orderings(locale and "all en_US" or "missing")
print(os.setlocale("C", "ctype"), os.setlocale(nil, "collate"))
orderings(locale and "ctype C" or "missing")
print(os.setlocale("C"))
orderings("all C")
