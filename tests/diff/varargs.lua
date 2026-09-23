-- varargs everywhere
local function count(...) return select('#', ...) end
local function pass(...) return ... end
local function pack2(...) return {n = select('#', ...), ...} end
print(count(), count(nil), count(nil, nil), count(pass(1, nil, 3)), count(pass()))
print(pass(1, 2, 3), (pass(1, 2, 3)), ({pass(1, 2, 3)})[3] == nil)
local t = pack2(1, nil, 3, nil)
print(t.n, t[1], t[2], t[3], t[4], #t >= 0)
print(select(2, "a", "b", "c"), select(-1, "a", "b", "c"), select(-3, "a", "b", "c"))
print(pcall(select, 0, "a"), pcall(select, -4, "a", "b", "c"))
print(select('#', table.unpack({1, 2, nil, 4}, 1, 4)), table.unpack({1, 2, 3}, 2), table.unpack({1, 2, 3}, 2, 5))
print(table.unpack({}, 1, 0), table.unpack({"x"}, -1, 1))
local packed = table.pack(1, nil, 3)
print(packed.n, packed[1], packed[2], packed[3])
local function middle(...) local a, b = ..., "fixed"; return a, b end
print(middle(1, 2, 3), middle())
local function varargTable(...) local x = {...}; return #x, x[1], x[#x] end
print(varargTable(10, 20, 30))
local function withFixed(a, b, ...) return a, b, select('#', ...), ... end
print(withFixed(1), withFixed(1, 2, 3, 4))
local function tailVararg(...) return pass(...) end
print(tailVararg(1, 2, 3))
local function varargInCall(...) return count(..., "end"), count("start", ...) end
print(varargInCall(1, 2, 3), varargInCall())
local function varargMethod(self, ...) return self.name, ... end
local obj = {name = "obj", m = varargMethod}
print(obj:m(1, 2), obj.m(obj))
local function manyResults() return 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 end
print(manyResults())
print(({manyResults()})[10], #{manyResults(), manyResults()}, #{manyResults(), (manyResults())})
local a, b, c, d = manyResults()
print(a, b, c, d)
local function lots(...) return select('#', ...) end
local big = {}
for i = 1, 250 do big[i] = i end
print(lots(table.unpack(big)), select(250, table.unpack(big)))
local main = load("return select('#', ...), ...")
print(main(1, 2, 3))
print(string.format == nil or true)
