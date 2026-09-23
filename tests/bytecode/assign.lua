-- Multiple assignment, including check_conflict cases.
local a, b, c = 1, 2
local t, i = {}, 1
a, b = b, a
a, b, c = c
a, b = 1, 2, 3
t[i], i = i, 2
i, t[i] = 3, 4
t, t.x = {}, 1
t.x, t = 1, {}
t[a], t[b], a, b = a, b, b, a
local up = {}
local function f()
  up, up.x = 1, 2
  up.x, up = 1, 2
  up[up], up = 1, 2
  G, G.x = 1, 2
  G.x, G = 1, 2
end
a.b.c, a.b = 1, 2
a[1], a[b], a.x = f()
a, b, c = f(), f()
local x, y, z = f()
local n1, n2, n3
local u1, u2 = nil, nil
local v1 = nil
local v2, v3
x, y, z = nil, nil, nil
return f
