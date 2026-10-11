-- utf8.codes steps over the continuation bytes of the character before
-- (lutf8lib.c iter_aux: 'while (iscontp(s + n)) n++'), up to 3 of them
-- per call; many calls make that loop hot for PHP's tracing JIT

local s = string.rep("a\u{E9}\u{20AC}\u{10348}", 200)
local sum, count = 0, 0
for round = 1, 20 do
  for position, code in utf8.codes(s) do
    sum = sum + position * round + code
    count = count + 1
  end
end
print(count, sum)

-- lax mode, and characters up to 6 bytes long
local lax = string.rep("\u{7FFFFFFF}\u{200000}z", 50)
sum, count = 0, 0
for round = 1, 20 do
  for position, code in utf8.codes(lax, true) do
    sum = sum + position + code % 1000
    count = count + 1
  end
end
print(count, sum)

-- positions of the first characters
local positions = {}
for position, code in utf8.codes("a\u{E9}\u{20AC}\u{10348}b") do
  positions[#positions + 1] = position .. ":" .. code
end
print(table.concat(positions, " "))

-- a continuation byte where a character should start
print(pcall(function()
  for _ in utf8.codes("ab\x80") do end
end))
print(pcall(function()
  for _ in utf8.codes("\u{E9}\x80") do end
end))
