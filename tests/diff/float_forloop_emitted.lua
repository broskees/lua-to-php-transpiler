-- Numeric float loops use their increment and direction in every iteration.
for value = 0.5, 2.5, 1.0 do
  print(value)
end

for value = 3.0, 1.0, -0.5 do
  print(value)
end

-- A fractional increment that cannot reach the limit stops after the last
-- value within it.
for value = -1.0, 0.2, 0.3 do
  print(value)
end
