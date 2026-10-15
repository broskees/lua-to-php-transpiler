-- word frequencies: gmatch, lower, upper, gsub and table.sort with a comparator over generated text
local syllables = { "ka", "lo", "mi", "ne", "su", "ta", "ri", "po", "ve", "do" }
local seed = 42
local function random(n)
  seed = (seed * 1103515245 + 12345) % 2147483648
  return seed % n + 1
end

local lines = {}
for i = 1, 3000 do
  local words = {}
  for w = 1, 12 do
    local parts = {}
    for p = 1, random(3) do parts[p] = syllables[random(#syllables)] end
    local word = table.concat(parts)
    if random(5) == 1 then word = word:upper() end
    words[w] = word
  end
  lines[i] = table.concat(words, random(2) == 1 and " " or ", ") .. "."
end
local text = table.concat(lines, "\n")

local counts = {}
for word in text:gmatch("%a+") do
  word = word:lower()
  counts[word] = (counts[word] or 0) + 1
end
local list = {}
for word, count in pairs(counts) do list[#list + 1] = { word = word, count = count } end
table.sort(list, function (a, b)
  if a.count ~= b.count then return a.count > b.count end
  return a.word < b.word
end)
for i = 1, 10 do print(list[i].word, list[i].count) end

local cleaned = text:gsub("[,.]", ""):gsub("%s+", " ")
local capitalized = cleaned:gsub("(%a)(%a*)", function (first, rest) return first:upper() .. rest:lower() end)
print(#list, #cleaned, #capitalized)
