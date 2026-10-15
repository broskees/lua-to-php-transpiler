-- a JSON encoder and decoder (string scanning, gsub, string.format, table.concat, recursion)
-- round-tripping generated records
local escapes = {
  ['"'] = '\\"', ['\\'] = '\\\\', ['\b'] = '\\b', ['\f'] = '\\f', ['\n'] = '\\n', ['\r'] = '\\r', ['\t'] = '\\t',
}
local unescapes = { b = "\b", f = "\f", n = "\n", r = "\r", t = "\t" }

local function escapeString(s)
  return '"' .. s:gsub('[%c"\\]', function (c)
    return escapes[c] or string.format("\\u%04x", c:byte())
  end) .. '"'
end

local encodeValue

local function encodeTable(t, buffer)
  if #t > 0 or next(t) == nil then
    buffer[#buffer + 1] = "["
    for i = 1, #t do
      if i > 1 then buffer[#buffer + 1] = "," end
      encodeValue(t[i], buffer)
    end
    buffer[#buffer + 1] = "]"
    return
  end
  local keys = {}
  for k in pairs(t) do keys[#keys + 1] = k end
  table.sort(keys)
  buffer[#buffer + 1] = "{"
  for i, k in ipairs(keys) do
    if i > 1 then buffer[#buffer + 1] = "," end
    buffer[#buffer + 1] = escapeString(k)
    buffer[#buffer + 1] = ":"
    encodeValue(t[k], buffer)
  end
  buffer[#buffer + 1] = "}"
end

function encodeValue(v, buffer)
  local kind = type(v)
  if kind == "table" then
    encodeTable(v, buffer)
  elseif kind == "string" then
    buffer[#buffer + 1] = escapeString(v)
  elseif kind == "number" then
    if math.type(v) == "integer" then
      buffer[#buffer + 1] = tostring(v)
    else
      buffer[#buffer + 1] = string.format("%.14g", v)
    end
  elseif kind == "boolean" then
    buffer[#buffer + 1] = v and "true" or "false"
  else
    buffer[#buffer + 1] = "null"
  end
end

local function encode(v)
  local buffer = {}
  encodeValue(v, buffer)
  return table.concat(buffer)
end

local decodeValue

local function skipSpace(s, i)
  return s:find("[^ \t\r\n]", i) or #s + 1
end

local function decodeString(s, i)
  local parts = {}
  local j = i + 1
  while true do
    local k = s:find('["\\]', j)
    parts[#parts + 1] = s:sub(j, k - 1)
    if s:byte(k) == 34 then return table.concat(parts), k + 1 end
    local c = s:sub(k + 1, k + 1)
    if c == "u" then
      parts[#parts + 1] = utf8.char(tonumber(s:sub(k + 2, k + 5), 16))
      j = k + 6
    else
      parts[#parts + 1] = unescapes[c] or c
      j = k + 2
    end
  end
end

local function decodeNumber(s, i)
  local text = s:match("^-?%d+%.?%d*[eE]?[-+]?%d*", i)
  return tonumber(text), i + #text
end

local function decodeArray(s, i)
  local result = {}
  i = skipSpace(s, i + 1)
  if s:byte(i) == 93 then return result, i + 1 end
  while true do
    local value
    value, i = decodeValue(s, i)
    result[#result + 1] = value
    i = skipSpace(s, i)
    if s:byte(i) == 93 then return result, i + 1 end
    i = skipSpace(s, i + 1)
  end
end

local function decodeObject(s, i)
  local result = {}
  i = skipSpace(s, i + 1)
  if s:byte(i) == 125 then return result, i + 1 end
  while true do
    local key, value
    key, i = decodeString(s, i)
    i = skipSpace(s, i)
    value, i = decodeValue(s, skipSpace(s, i + 1))
    result[key] = value
    i = skipSpace(s, i)
    if s:byte(i) == 125 then return result, i + 1 end
    i = skipSpace(s, i + 1)
  end
end

function decodeValue(s, i)
  local c = s:byte(i)
  if c == 123 then return decodeObject(s, i)
  elseif c == 91 then return decodeArray(s, i)
  elseif c == 34 then return decodeString(s, i)
  elseif c == 116 then return true, i + 4
  elseif c == 102 then return false, i + 5
  elseif c == 110 then return nil, i + 4
  end
  return decodeNumber(s, i)
end

local function decode(s)
  local value = decodeValue(s, skipSpace(s, 1))
  return value
end

local records = {}
for i = 1, 300 do
  records[i] = {
    id = i,
    name = "user" .. i,
    email = string.format("user%d@example.com", i),
    score = i * 1.25,
    active = i % 3 == 0,
    tags = { "t" .. (i % 7), "t" .. (i % 11), 'quote"d', "tab\tbed", "line\1break" },
    address = { street = i .. " Main St", city = "City" .. (i % 13), zip = string.format("%05d", i * 37 % 100000) },
  }
end
local text = encode(records)
local total = 0
for _ = 1, 15 do
  local decoded = decode(text)
  local again = encode(decoded)
  assert(again == text)
  total = total + #again + #decoded
end
print(#text, total)
