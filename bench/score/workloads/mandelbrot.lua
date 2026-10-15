-- mandelbrot: float loops and bitwise operators; a checksum of the bitmap's bytes
local width = 200
local height = width
local iterations = 50
local limit2 = 4.0
local checksum = 0
for y = 0, height - 1 do
  local Ci = 2 * y / height - 1
  local bits, bitCount = 0, 0
  for x = 0, width - 1 do
    local Cr = 2 * x / width - 1.5
    local Zr, Zi, Tr, Ti = 0.0, 0.0, 0.0, 0.0
    local escaped = false
    for _ = 1, iterations do
      Zi = 2 * Zr * Zi + Ci
      Zr = Tr - Ti + Cr
      Tr = Zr * Zr
      Ti = Zi * Zi
      if Tr + Ti > limit2 then
        escaped = true
        break
      end
    end
    bits = (bits << 1) | (escaped and 0 or 1)
    bitCount = bitCount + 1
    if bitCount == 8 then
      checksum = (checksum * 31 + bits) & 0xFFFFFFFF
      bits, bitCount = 0, 0
    end
  end
  if bitCount > 0 then checksum = (checksum * 31 + (bits << (8 - bitCount))) & 0xFFFFFFFF end
end
print(width, height, checksum)
