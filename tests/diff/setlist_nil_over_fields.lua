-- a nil list item removes the field of its index that the constructor set
-- before it (OP_SETLIST comes last): up to the top (varargs, a call's
-- results: a loop in the emitted code and in Op::setList, where the element
-- is unset only when it is there) and with a fixed count (a constant run)

local function show(t)
  local entries = {}
  for k, v in pairs(t) do entries[#entries + 1] = tostring(k) .. "=" .. tostring(v) end
  table.sort(entries)
  return #entries .. ": " .. table.concat(entries, " ")
end

local function withVarargs(...) return {[1] = "one", [2] = "two", [4] = "four", ...} end
print(show(withVarargs(nil, nil)))
print(show(withVarargs(nil, "b", nil, nil)))
print(show(withVarargs()))

local function threeNils() return nil, nil, nil end
print(show({[2] = "two", [3] = "three", threeNils()}))
print(show({"a", [2] = "two", [5] = "five", threeNils()}))

print(show({[1] = "one", [2] = "two", nil, nil}))
print(show({[1] = "one", [3] = "three", nil, "b", nil}))
