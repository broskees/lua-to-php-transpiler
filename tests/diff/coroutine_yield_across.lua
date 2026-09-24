-- yields that must work: across Lua frames, pcall/xpcall, metamethods
-- called by the VM, 'for' iterators, __pairs, dofile, __close, __call

-- drive a coroutine to the end, printing what it yields
local function run(label, f, ...)
  local co = coroutine.create(f)
  local results = table.pack(coroutine.resume(co, ...))
  local yields = {}
  while coroutine.status(co) == "suspended" do
    yields[#yields + 1] = tostring(results[2]) .. (results[3] ~= nil and ("/" .. tostring(results[3])) or "")
    results = table.pack(coroutine.resume(co, #yields))
  end
  print(label, table.concat(yields, " "), table.unpack(results, 1, results.n))
end

-- pcall and xpcall
run("pcall", function ()
  return pcall(function (a) local b = coroutine.yield(a); return b * 2 end, 5)
end)
run("pcall error after yield", function ()
  return pcall(function () coroutine.yield("before"); error("after yield") end)
end)
run("xpcall handler after yield", function ()
  return xpcall(function () coroutine.yield("x"); error({1}) end,
                function (e) return "handled " .. type(e) end)
end)
run("nested pcall", function ()
  return pcall(pcall, function () coroutine.yield("deep"); error("e", 0) end)
end)
run("xpcall of pcall", function ()
  return xpcall(pcall, function (...) return ... end, function ()
    local s = 0
    for i in function (_, i) if i < 3 then return coroutine.yield(i) end end, nil, 0 do
      pcall(function () s = s + i end)
    end
    error({s})
  end)
end)
run("pcall of yield", function () return pcall(coroutine.yield, "direct") end)
run("error in xpcall after yield, handler sees traceback position", function ()
  local function f(a, b) a = coroutine.yield(a); error{a + b} end
  local function g(x) return x[1] * 2 end
  return xpcall(f, g, 10, 20)
end)

-- metamethods called by the VM may yield
local function val(x) if type(x) == "table" then return x.x else return x end end
local mt = {
  __eq = function (a, b) coroutine.yield("eq"); return val(a) == val(b) end,
  __lt = function (a, b) coroutine.yield("lt"); return val(a) < val(b) end,
  __le = function (a, b) coroutine.yield("le"); return a - b <= 0 end,
  __add = function (a, b) coroutine.yield("add"); return val(a) + val(b) end,
  __sub = function (a, b) coroutine.yield("sub"); return val(a) - val(b) end,
  __mul = function (a, b) coroutine.yield("mul"); return val(a) * val(b) end,
  __div = function (a, b) coroutine.yield("div"); return val(a) / val(b) end,
  __idiv = function (a, b) coroutine.yield("idiv"); return val(a) // val(b) end,
  __mod = function (a, b) coroutine.yield("mod"); return val(a) % val(b) end,
  __pow = function (a, b) coroutine.yield("pow"); return val(a) ^ val(b) end,
  __unm = function (a) coroutine.yield("unm"); return -val(a) end,
  __band = function (a, b) coroutine.yield("band"); return val(a) & val(b) end,
  __bor = function (a, b) coroutine.yield("bor"); return val(a) | val(b) end,
  __bxor = function (a, b) coroutine.yield("bxor"); return val(a) ~ val(b) end,
  __shl = function (a, b) coroutine.yield("shl"); return val(a) << val(b) end,
  __shr = function (a, b) coroutine.yield("shr"); return val(a) >> val(b) end,
  __bnot = function (a) coroutine.yield("bnot"); return ~val(a) end,
  __concat = function (a, b) coroutine.yield("concat"); return val(a) .. val(b) end,
  __len = function (a) coroutine.yield("len"); return 99 end,
  __index = function (t, k) coroutine.yield("index"); return t.k[k] end,
  __newindex = function (t, k, v) coroutine.yield("newindex"); t.k[k] = v end,
  __call = function (self, a) coroutine.yield("call"); return a + 1 end,
}
local function new(x) return setmetatable({x = x, k = {}}, mt) end
local a, b, c = new(10), new(12), new("hello")
run("le", function () return a >= b, a <= b end)
run("lt", function () return a < b, 1 < a, a > 2 end)
run("eq", function () return a == b, a ~= new(10) end)
run("arith", function () return a + b, a - 25, 2 * a, a / 4, a // 3, a % 6, a ^ 2, -a end)
run("arith k", function () return a + 1000, a - 25000, 2000 * a, a % 600, a // 500 end)
run("bitwise", function () return a & b, a | 2, 2 ~ a, a << 2, 1 >> a, ~a end)
run("concat", function () return a .. b .. c .. a, "a" .. "b" .. a .. "c" .. c .. b .. "x" end)
run("len", function () return #a end)
run("index", function () a.BB = "stored"; return a.BB, a.k.BB end)
run("call", function () return a(41) end)
run("_ENV", function ()
  local env = new(0); env.k.BBB = 10
  local f = load("AAA = BBB + 1; return AAA", "chunk", "t", env)
  return f(), env.k.AAA
end)

-- 'for' iterators, including __pairs and __call iterators
run("for iterator", function ()
  local s = 0
  for i in function (limit, i) if i % 2 == 0 then coroutine.yield("for " .. i) end
                                if i < limit then return i + 1 end end, 4, 0 do
    s = s + i
  end
  return s
end)
run("__pairs", function ()
  local t = setmetatable({}, {__pairs = function (t)
    coroutine.yield("in __pairs")
    return function (_, k) if k == nil then return 1, "one" end end, t, nil
  end})
  local r = {}
  for k, v in pairs(t) do r[#r + 1] = k .. "=" .. v end
  return table.concat(r, ",")
end)
run("callable iterator", function ()
  local it = setmetatable({}, {__call = function (_, s, i)
    coroutine.yield("it " .. i)
    if i < 2 then return i + 1 end
  end})
  local n = 0
  for i in it, nil, 0 do n = n + i end
  return n
end)

-- dofile runs its chunk as a yieldable call
local name = os.tmpname()
local file = io.open(name, "w")
file:write("local x = coroutine.yield('in dofile'); return x, 'from file'")
file:close()
run("dofile", function () return dofile(name) end)
os.remove(name)

-- tail calls into yielding natives and __close at return
run("tail call to yield", function (...) return coroutine.yield(...) end, "t1", "t2")
run("__close on return", function ()
  local x <close> = setmetatable({}, {__close = function () coroutine.yield("closing") end})
  return "returned"
end)

-- a coroutine resuming another from inside a metamethod
run("nested", function ()
  local inner = coroutine.wrap(function () coroutine.yield(a + b) end)
  return inner()
end)

-- isyieldable inside pcall and metamethods
run("isyieldable", function ()
  local r = {}
  pcall(function () r[1] = coroutine.isyieldable() end)
  local t = setmetatable({}, {__index = function () return coroutine.isyieldable() end})
  r[2] = t.x
  return r[1], r[2]
end)
