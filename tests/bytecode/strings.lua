-- Short strings with every escape, long strings and long comments of all levels.
local s1 = "a\a\b\f\n\r\t\v\\\"\'z"
local s2 = 'single \'quoted\' "double" inside'
local s3 = "\x41\x7a\x00\xff\xFe"
local s4 = "\65\066\0677\0\255\1\12\123"
local s5 = "\u{0}\u{7F}\u{80}\u{7FF}\u{800}\u{FFFF}\u{10000}\u{10FFFF}\u{7FFFFFFF}\u{000041}"
local s6 = "line1\
line2\
line3"
local s7 = "zap: \z
            continues here \z

   and here"
local s8 = [[long
string with ]] .. [==[
level two ]] ]=] still inside ]==] .. [=[]]]=]
local s9 = [[
first newline skipped]]
local s10 = [[

two newlines, one kept]]
--[[ a long
comment ]] local after_comment = 1
--[==[ level two comment
]] ]=] ]===] still comment
]==] local after_comment2 = 2
-- short comment with [[ brackets
--[ not a long comment
--[= not a long comment either
local s11 = "" local s12 = '' local s13 = [[]] local s14 = [==[]==]
local s15 = "a very long string constant exceeding forty characters for sure"
local s16 = [[a very long string constant exceeding forty characters for sure]]
local s17 = "exactly forty characters long string!!!!"
local s18 = "exactly forty-one characters long string!!"
local crlf = "\r\n"
return s1, s2, s3, s4, s5, s6, s7, s8, s9, s10, s11, s12, s13, s14, s15, s16, s17, s18, crlf
