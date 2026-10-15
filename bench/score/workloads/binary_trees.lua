-- binary trees (Benchmarks Game): allocating and walking about 700,000 small tables
local function bottomUpTree(depth)
  if depth == 0 then return {} end
  depth = depth - 1
  return { bottomUpTree(depth), bottomUpTree(depth) }
end

local function itemCheck(tree)
  if tree[1] then
    return 1 + itemCheck(tree[1]) + itemCheck(tree[2])
  end
  return 1
end

local maxDepth = 12
local stretchDepth = maxDepth + 1
print(string.format("stretch tree of depth %d\t check: %d", stretchDepth, itemCheck(bottomUpTree(stretchDepth))))
local longLivedTree = bottomUpTree(maxDepth)
for depth = 4, maxDepth, 2 do
  local iterations = 1 << (maxDepth - depth + 4)
  local check = 0
  for _ = 1, iterations do
    check = check + itemCheck(bottomUpTree(depth))
  end
  print(string.format("%d\t trees of depth %d\t check: %d", iterations, depth, check))
end
print(string.format("long lived tree of depth %d\t check: %d", maxDepth, itemCheck(longLivedTree)))
