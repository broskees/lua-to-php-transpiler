-- Replays pattern matching cases made by tests/fuzz/patterns.php (random
-- patterns, valid and malformed, over subjects built from their bytes)
-- through string.find, match, gmatch and gsub, and prints every result or
-- error, one line per case. The committed corpus is
-- string_pattern_corpus.bin (fixed seed); the fuzzer points
-- LUA_PATTERN_CASES at its own batches.
--
-- Every case runs twice: in bin/lua, the first use of a pattern on a short
-- subject runs the port of lstrlib.c's matcher, the next one its PCRE
-- translation (src/Lib/String/PatternRegex.php). A second run that differs
-- from the first prints a line of its own.

local path = os.getenv("LUA_PATTERN_CASES") or "string_pattern_corpus.bin"
local file = assert(io.open(path, "rb"))
local data = file:read("a")
file:close()

local escapes = {}
for byte = 0, 255 do
  local printable = byte >= 32 and byte < 127 and byte ~= 34 and byte ~= 92
  escapes[byte] = printable and string.char(byte) or string.format("\\%03d", byte)
end

local function show (value)
  local kind = math.type(value)
  if kind == "integer" then return "i" .. value end
  if kind == "float" then return string.format("f%.17g", value) end
  if type(value) ~= "string" then return tostring(value) end
  local parts = {}
  for i = 1, #value do parts[i] = escapes[string.byte(value, i)] end
  return '"' .. table.concat(parts) .. '"'
end

local function showAll (...)
  local values = table.pack(...)
  for i = 1, values.n do values[i] = show(values[i]) end
  return table.concat(values, ",", 1, values.n)
end

-- gsub replacements
local replacementTable = {a = "A", b = false, ab = "<ab>", ["1"] = "one", [1] = "first", [2] = 2,
                          [3] = 1.5, [""] = "empty", ["\0"] = "zero", x = {}}
local calls  -- arguments of every call of a replacement function
local replacementFunctions = {
  ["nil"] = function () return nil end,
  ["false"] = function () return false end,
  concat = function (...)
    local values = table.pack(...)
    for i = 1, values.n do values[i] = tostring(values[i]) end
    return table.concat(values, "+", 1, values.n)
  end,
  count = function (...) return select("#", ...) end,
  table = function () return {} end,
  first = function (first) return first end,
}

local runners = {}
function runners.find (subject, pattern, init, plain)
  return showAll(string.find(subject, pattern, init, plain))
end
function runners.match (subject, pattern, init)
  return showAll(string.match(subject, pattern, init))
end
function runners.gmatch (subject, pattern, init)
  local iterator = string.gmatch(subject, pattern, init)
  local results = {}
  for _ = 1, 100 do
    local values = table.pack(iterator())
    if values.n == 0 then break end
    results[#results + 1] = showAll(table.unpack(values, 1, values.n))
  end
  return table.concat(results, " ")
end
function runners.gsub (subject, pattern, _, _, replacement, limit)
  calls = {}
  local result, count = string.gsub(subject, pattern, replacement, limit)
  return showAll(result, count) .. " " .. table.concat(calls, " ")
end

local position, index = 1, 0
while position <= #data do
  local kind, subject, pattern, hasInit, init, plain, replacementKind, replacementText, hasLimit, limit
  kind, subject, pattern, hasInit, init, plain, replacementKind, replacementText, hasLimit, limit, position =
    string.unpack("<s4s4s4BjBBs4Bj", data, position)
  index = index + 1
  local replacement
  if replacementKind == 1 then
    replacement = replacementText
  elseif replacementKind == 2 then
    replacement = replacementTable
  elseif replacementKind == 3 then
    local f = replacementFunctions[replacementText]
    replacement = function (...)
      calls[#calls + 1] = "(" .. showAll(...) .. ")"
      return f(...)
    end
  else
    replacement = tonumber(replacementText)
  end
  local plainArgument = nil
  if plain == 1 then plainArgument = true elseif plain == 2 then plainArgument = false end
  local function run ()
    local ok, result = pcall(runners[kind], subject, pattern, hasInit == 1 and init or nil,
                             plainArgument, replacement, hasLimit == 1 and limit or nil)
    return ok and result or "error " .. show(result)
  end
  local first = run()
  print(index, first)
  local second = run()
  if second ~= first then print(index, "second run: " .. second) end
end
