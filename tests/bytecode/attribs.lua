-- <const> (compile-time constants propagate and fold) and <close>.
local K1 <const> = 10
local K2 <const> = K1 * 2 + 1
local S <const> = "const string"
local F <const> = 2.5
local B <const> = true
local N <const> = nil
local NEG <const> = -K1
local BIG <const> = 1 << 62
local TOOBIG <const> = 9223372036854775807 + K1
local NOTK <const> = {}
local CALLED <const> = print("not a constant")
local x, y <const>, z <const> = 1, 2, 3
local p <const>, q = 4, 5
local LONG <const> = "a string longer than forty characters to use as a constant"

local t = {K1, K2, S, F, B, N, NEG, BIG, TOOBIG, NOTK, x, y, z, p, q, LONG}
t[K1] = S
t[S] = K2
t.f = K1 + F
local cmp = K1 < K2 and S == "const string" and F > 2
local idx = t[K2 // 3]

local function use_upvalues()
  local inner <const> = K1 + 1
  return K1, K2, S, F, B, N, NOTK, inner, x, y, z, p, q, CALLED, LONG
end

local function closes()
  local a <close> = nil
  local b <close> = setmetatable({}, {__close = print})
  do
    local c <close> = b
    return c
  end
end

local function close_and_tail(f)
  local r <close> = f
  return f()
end

local function close_loop(n)
  for i = 1, n do
    local c <close> = nil
    if i == 2 then break end
  end
  local d <const>, e <close> = 1, nil
  return d
end

return t, cmp, idx, use_upvalues, closes, close_and_tail, close_loop
