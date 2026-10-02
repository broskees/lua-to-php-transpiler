-- Decimal numerals just past the 64-bit integer range become floats (lobject.c
-- l_str2int falls back to l_str2d); hexadecimal ones wrap around. The magnitude
-- 2^63 itself is only an integer with a minus sign: one more digit after it
-- overflows, whatever the digit, and must not reach the integer path.
local numerals = {
  "-9223372036854775808", "-92233720368547758080", "-92233720368547758089",
  "-922337203685477580800", "-9223372036854775808000000", "  -92233720368547758080  ",
  "92233720368547758080", "+92233720368547758080", "9223372036854775808",
  "-9223372036854775809", "-00009223372036854775808", "-000092233720368547758080",
  "18446744073709551615", "-18446744073709551616", "99999999999999999999999",
  "0x8000000000000000", "-0x8000000000000000", "0x80000000000000000", "-0x10000000000000001",
  "0xffffffffffffffffff", "-92233720368547758080x", "-92233720368547758080 1",
}
for _, numeral in ipairs(numerals) do
  local number = tonumber(numeral)
  print(string.format("%-28s", numeral), math.type(number), number)
end

-- the same conversion behind arithmetic, comparison-free coercions and library arguments
print("-92233720368547758080" + 0, "-92233720368547758080" * 1, -"-92233720368547758080")
print("-92233720368547758080" // 1, "-9223372036854775808" + 0, "-9223372036854775808" - 1)
print(math.tointeger("-92233720368547758080"), math.tointeger("-9223372036854775808"))
print(pcall(function () return "-92233720368547758080" | 0 end))
print(pcall(string.format, "%d", "-92233720368547758080"))
print(string.format("%.0f", "-92233720368547758080"), string.format("%d", "-9223372036854775808"))
print(math.abs("-92233720368547758080"), math.floor("-92233720368547758080"))
print(pcall(string.rep, "x", "-92233720368547758080"))
print(("-92233720368547758080"):len(), #tostring(tonumber("-92233720368547758080")))
print(load("return -92233720368547758080")(), load("return 92233720368547758080")())
