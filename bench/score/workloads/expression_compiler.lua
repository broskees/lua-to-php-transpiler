-- an expression compiler: tokenizing with string patterns, a recursive descent parser with
-- metatable methods, compiling to closures, then calling the closures many times
local function tokenize(source)
  local tokens, position = {}, 1
  while position <= #source do
    local _, spaceEnd = source:find("^%s+", position)
    if spaceEnd then
      position = spaceEnd + 1
    else
      local number = source:match("^%d+%.?%d*", position)
      local name = number == nil and source:match("^%a%w*", position)
      if number then
        tokens[#tokens + 1] = { kind = "number", value = tonumber(number) }
        position = position + #number
      elseif name then
        tokens[#tokens + 1] = { kind = "name", value = name }
        position = position + #name
      else
        tokens[#tokens + 1] = { kind = source:sub(position, position) }
        position = position + 1
      end
    end
  end
  tokens[#tokens + 1] = { kind = "end" }
  return tokens
end

local binary = {
  ["+"] = { 1, function (a, b) return a + b end },
  ["-"] = { 1, function (a, b) return a - b end },
  ["*"] = { 2, function (a, b) return a * b end },
  ["/"] = { 2, function (a, b) return a / b end },
  ["%"] = { 2, function (a, b) return a % b end },
  ["^"] = { 3, function (a, b) return a ^ b end },
}
local functions = { min = math.min, max = math.max, abs = math.abs, floor = math.floor }

local Parser = {}
Parser.__index = Parser

function Parser.new(tokens)
  return setmetatable({ tokens = tokens, index = 1 }, Parser)
end

function Parser:peek()
  return self.tokens[self.index]
end

function Parser:advance()
  local token = self.tokens[self.index]
  self.index = self.index + 1
  return token
end

function Parser:expect(kind)
  local token = self:advance()
  if token.kind ~= kind then error("expected " .. kind .. ", got " .. token.kind) end
  return token
end

function Parser:primary()
  local token = self:advance()
  if token.kind == "number" then
    local value = token.value
    return function () return value end
  elseif token.kind == "name" then
    local name = token.value
    if self:peek().kind ~= "(" then
      return function (env) return env[name] end
    end
    self:advance()
    local arguments = { self:expression(0) }
    while self:peek().kind == "," do
      self:advance()
      arguments[#arguments + 1] = self:expression(0)
    end
    self:expect(")")
    local f = functions[name]
    local a, b = arguments[1], arguments[2]
    if b == nil then
      return function (env) return f(a(env)) end
    end
    return function (env) return f(a(env), b(env)) end
  elseif token.kind == "(" then
    local inner = self:expression(0)
    self:expect(")")
    return inner
  elseif token.kind == "-" then
    local operand = self:primary()
    return function (env) return -operand(env) end
  end
  error("unexpected " .. token.kind)
end

function Parser:expression(minimum)
  local left = self:primary()
  while true do
    local entry = binary[self:peek().kind]
    if entry == nil or entry[1] < minimum then return left end
    self:advance()
    local precedence, operate = entry[1], entry[2]
    local right = self:expression(precedence == 3 and precedence or precedence + 1)
    local leftOperand = left
    left = function (env) return operate(leftOperand(env), right(env)) end
  end
end

local sources = {
  "x * x + 2 * x * y - y / 3",
  "max(x, y) - min(x, y) + abs(x - y) * 0.5",
  "(x + 1) * (y - 1) % 7 + floor(x / 3)",
  "2 ^ 3 ^ 0.5 + -x * -y",
  "((x + y) * (x - y) + 1) / (abs(y) + 1)",
}
local total = 0
for round = 1, 40 do
  for _, source in ipairs(sources) do
    local compiled = Parser.new(tokenize(source)):expression(0)
    for i = 1, 150 do
      total = total + compiled({ x = i, y = round })
    end
  end
end
print(string.format("%.6f", total))
