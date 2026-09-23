<?php

declare(strict_types=1);

namespace LuaPhp\Compiler;

use LuaPhp\Runtime\StringToNumber;

/**
 * Port of llex.c (Lua 5.4.9): the lexical analyzer, plus C 'LexState'
 * (llex.h), which also carries the parser state shared by all functions of
 * a chunk (current FuncState, Dyndata, the scanner table used as constant
 * cache, the C-call depth used by enterlevel).
 *
 * Characters are ints (0..255) like C's 'current'; EOZ (-1) marks the end
 * of input. The token buffer is a byte string; error messages quote it as C
 * does, so an embedded '\0' ends the quoted text.
 */
final class Lexer
{
    // llex.h: FIRST_RESERVED and enum RESERVED ("ORDER RESERVED")
    public const FIRST_RESERVED = 256;
    public const TK_AND = 256;
    public const TK_BREAK = 257;
    public const TK_DO = 258;
    public const TK_ELSE = 259;
    public const TK_ELSEIF = 260;
    public const TK_END = 261;
    public const TK_FALSE = 262;
    public const TK_FOR = 263;
    public const TK_FUNCTION = 264;
    public const TK_GOTO = 265;
    public const TK_IF = 266;
    public const TK_IN = 267;
    public const TK_LOCAL = 268;
    public const TK_NIL = 269;
    public const TK_NOT = 270;
    public const TK_OR = 271;
    public const TK_REPEAT = 272;
    public const TK_RETURN = 273;
    public const TK_THEN = 274;
    public const TK_TRUE = 275;
    public const TK_UNTIL = 276;
    public const TK_WHILE = 277;
    public const TK_IDIV = 278;
    public const TK_CONCAT = 279;
    public const TK_DOTS = 280;
    public const TK_EQ = 281;
    public const TK_GE = 282;
    public const TK_LE = 283;
    public const TK_NE = 284;
    public const TK_SHL = 285;
    public const TK_SHR = 286;
    public const TK_DBCOLON = 287;
    public const TK_EOS = 288;
    public const TK_FLT = 289;
    public const TK_INT = 290;
    public const TK_NAME = 291;
    public const TK_STRING = 292;

    // lzio.h: end of stream
    public const EOZ = -1;

    // llex.c: luaX_tokens ("ORDER RESERVED")
    private const TOKEN_TEXTS = [
        'and', 'break', 'do', 'else', 'elseif',
        'end', 'false', 'for', 'function', 'goto', 'if',
        'in', 'local', 'nil', 'not', 'or', 'repeat',
        'return', 'then', 'true', 'until', 'while',
        '//', '..', '...', '==', '>=', '<=', '~=',
        '<<', '>>', '::', '<eof>',
        '<number>', '<integer>', '<name>', '<string>',
    ];

    // llex.c: luaX_init marks these strings as reserved words
    private const RESERVED_WORDS = [
        'and' => self::TK_AND, 'break' => self::TK_BREAK, 'do' => self::TK_DO,
        'else' => self::TK_ELSE, 'elseif' => self::TK_ELSEIF, 'end' => self::TK_END,
        'false' => self::TK_FALSE, 'for' => self::TK_FOR, 'function' => self::TK_FUNCTION,
        'goto' => self::TK_GOTO, 'if' => self::TK_IF, 'in' => self::TK_IN,
        'local' => self::TK_LOCAL, 'nil' => self::TK_NIL, 'not' => self::TK_NOT,
        'or' => self::TK_OR, 'repeat' => self::TK_REPEAT, 'return' => self::TK_RETURN,
        'then' => self::TK_THEN, 'true' => self::TK_TRUE, 'until' => self::TK_UNTIL,
        'while' => self::TK_WHILE,
    ];

    // llimits.h: MAX_INT
    private const MAX_INT = 2147483647;

    // lua.h: status code of syntax errors (CompileError code)
    public const LUA_ERRSYNTAX = 3;

    /** current character (charint) */
    public int $current;

    /** input line counter */
    public int $linenumber = 1;

    /** line of last token 'consumed' */
    public int $lastline = 1;

    /** current token */
    public Token $t;

    /** look ahead token */
    public Token $lookahead;

    /** current function (parser) */
    public ?FuncState $fs = null;

    /** dynamic structures used by the parser */
    public Dyndata $dyd;

    /** current source name (the chunk name) */
    public string $source;

    /** environment variable name */
    public string $envn = '_ENV';

    /**
     * The scanner table 'h' as lcode.c addk uses it: constant key ->
     * index of that constant in the function that added it last. Shared by
     * all functions of the chunk. (The strings llex.c anchors there have
     * non-integer values, which addk treats like absent entries.)
     *
     * @var array<string, int>
     */
    public array $constantCache = [];

    /** lstate.h: getCcalls(L), the C-stack depth that enterlevel counts */
    public int $nCcalls;

    /** buffer for tokens (C 'Mbuffer') */
    private string $buffer = '';

    private string $input;

    private int $inputPosition = 0;

    private int $inputLength;

    /**
     * llex.c: luaX_setinput. $nCcallsAtEntry is the C-call depth at which
     * parsing starts (2 for load() called from a main chunk run by lua.c).
     */
    public function __construct(string $input, string $source, int $nCcallsAtEntry)
    {
        $this->input = $input;
        $this->inputLength = strlen($input);
        $this->source = $source;
        $this->nCcalls = $nCcallsAtEntry;
        $this->t = new Token();
        $this->lookahead = new Token();
        $this->lookahead->token = self::TK_EOS;  // no look-ahead token
        $this->dyd = new Dyndata();
        $this->next();  // C passes the first char in; lua_load reads it from the stream
    }

    // llex.c: next (zgetc)
    private function next(): void
    {
        $this->current = $this->inputPosition < $this->inputLength
            ? ord($this->input[$this->inputPosition++])
            : self::EOZ;
    }

    // llex.c: currIsNewline
    private function currIsNewline(): bool
    {
        return $this->current === 10 || $this->current === 13;
    }

    // llex.c: save
    private function save(int $c): void
    {
        $this->buffer .= chr($c & 0xFF);
    }

    // llex.c: save_and_next
    private function save_and_next(): void
    {
        $this->save($this->current);
        $this->next();
    }

    // lzio.h: luaZ_buffremove
    private function buffremove(int $count): void
    {
        $this->buffer = substr($this->buffer, 0, strlen($this->buffer) - $count);
    }

    // llex.c: luaX_token2str
    public function luaX_token2str(int $token): string
    {
        if ($token < self::FIRST_RESERVED) {  // single-byte symbols?
            if (self::lisprint($token)) {
                return "'" . chr($token) . "'";
            }
            return "'<\\" . $token . ">'";  // control character
        }
        $text = self::TOKEN_TEXTS[$token - self::FIRST_RESERVED];
        if ($token < self::TK_EOS) {  // fixed format (symbols and reserved words)?
            return "'" . $text . "'";
        }
        return $text;  // names, strings, and numerals
    }

    // llex.c: txtToken
    private function txtToken(int $token): string
    {
        switch ($token) {
            case self::TK_NAME:
            case self::TK_STRING:
            case self::TK_FLT:
            case self::TK_INT:
                return "'" . self::asCString($this->buffer) . "'";
            default:
                return $this->luaX_token2str($token);
        }
    }

    // llex.c: lexerror (with ldebug.c luaG_addinfo)
    public function lexerror(string $message, int $token): never
    {
        $message = ChunkId::of($this->source) . ':' . $this->linenumber . ': ' . $message;
        if ($token !== 0) {
            $message .= ' near ' . $this->txtToken($token);
        }
        throw new CompileError($message, self::LUA_ERRSYNTAX);
    }

    // llex.c: luaX_syntaxerror
    public function luaX_syntaxerror(string $message): never
    {
        $this->lexerror($message, $this->t->token);
    }

    // llex.c: inclinenumber (skips \n, \r, \n\r, or \r\n)
    private function inclinenumber(): void
    {
        $old = $this->current;
        $this->next();  // skip '\n' or '\r'
        if ($this->currIsNewline() && $this->current !== $old) {
            $this->next();  // skip '\n\r' or '\r\n'
        }
        if (++$this->linenumber >= self::MAX_INT) {
            $this->lexerror('chunk has too many lines', 0);
        }
    }

    // llex.c: check_next1
    private function check_next1(int $c): bool
    {
        if ($this->current === $c) {
            $this->next();
            return true;
        }
        return false;
    }

    // llex.c: check_next2 (current char in a two-char set? save it)
    private function check_next2(string $set): bool
    {
        if ($this->current === ord($set[0]) || $this->current === ord($set[1])) {
            $this->save_and_next();
            return true;
        }
        return false;
    }

    /**
     * llex.c: read_numeral. Accepts roughly
     * %d(%x|%.|([Ee][+-]?))* | 0[Xx](%x|%.|([Pp][+-]?))*
     * and lets luaO_str2num reject ill-formed numerals.
     */
    private function read_numeral(Token $token): int
    {
        $exponentMarks = 'Ee';
        $first = $this->current;
        $this->save_and_next();
        if ($first === 48 && $this->check_next2('xX')) {  // hexadecimal?
            $exponentMarks = 'Pp';
        }
        for (;;) {
            if ($this->check_next2($exponentMarks)) {  // exponent mark?
                $this->check_next2('-+');  // optional exponent sign
            } elseif (self::lisxdigit($this->current) || $this->current === 46) {  // '%x|%.'
                $this->save_and_next();
            } else {
                break;
            }
        }
        if (self::lislalpha($this->current)) {  // is numeral touching a letter?
            $this->save_and_next();  // force an error
        }
        $number = StringToNumber::convert($this->buffer);
        if ($number === null) {  // format error?
            $this->lexerror('malformed number', self::TK_FLT);
        }
        $token->seminfo = $number;
        return is_int($number) ? self::TK_INT : self::TK_FLT;
    }

    /**
     * llex.c: skip_sep. Reads '[=*[' or ']=*]', leaving the last bracket.
     * Returns the number of '='s + 2 if well formed; 1 for a single bracket;
     * 0 for an unfinished '[==...'.
     */
    private function skip_sep(): int
    {
        $count = 0;
        $bracket = $this->current;
        $this->save_and_next();
        while ($this->current === 61) {  // '='
            $this->save_and_next();
            $count++;
        }
        if ($this->current === $bracket) {
            return $count + 2;
        }
        return $count === 0 ? 1 : 0;
    }

    // llex.c: read_long_string ($token null means a comment)
    private function read_long_string(?Token $token, int $sep): void
    {
        $line = $this->linenumber;  // initial line (for error message)
        $this->save_and_next();  // skip 2nd '['
        if ($this->currIsNewline()) {  // string starts with a newline?
            $this->inclinenumber();  // skip it
        }
        for (;;) {
            switch ($this->current) {
                case self::EOZ:
                    $what = $token !== null ? 'string' : 'comment';
                    $this->lexerror("unfinished long $what (starting at line $line)", self::TK_EOS);
                    // no break: lexerror does not return
                case 93:  // ']'
                    if ($this->skip_sep() === $sep) {
                        $this->save_and_next();  // skip 2nd ']'
                        break 2;
                    }
                    break;
                case 10:
                case 13:
                    $this->save(10);
                    $this->inclinenumber();
                    if ($token === null) {
                        $this->buffer = '';  // avoid wasting space
                    }
                    break;
                default:
                    if ($token !== null) {
                        $this->save_and_next();
                    } else {
                        $this->next();
                    }
            }
        }
        if ($token !== null) {
            $token->seminfo = substr($this->buffer, $sep, strlen($this->buffer) - 2 * $sep);
        }
    }

    // llex.c: esccheck
    private function esccheck(bool $condition, string $message): void
    {
        if (!$condition) {
            if ($this->current !== self::EOZ) {
                $this->save_and_next();  // add current to buffer for error message
            }
            $this->lexerror($message, self::TK_STRING);
        }
    }

    // llex.c: gethexa
    private function gethexa(): int
    {
        $this->save_and_next();
        $this->esccheck(self::lisxdigit($this->current), 'hexadecimal digit expected');
        return self::luaO_hexavalue($this->current);
    }

    // llex.c: readhexaesc
    private function readhexaesc(): int
    {
        $value = $this->gethexa();
        $value = ($value << 4) + $this->gethexa();
        $this->buffremove(2);  // remove saved chars from buffer
        return $value;
    }

    // llex.c: readutf8esc
    private function readutf8esc(): int
    {
        $removeCount = 4;  // chars to be removed: '\', 'u', '{', and first digit
        $this->save_and_next();  // skip 'u'
        $this->esccheck($this->current === 123, "missing '{'");  // '{'
        $value = $this->gethexa();  // must have at least one digit
        for (;;) {
            $this->save_and_next();
            if (!self::lisxdigit($this->current)) {
                break;
            }
            $removeCount++;
            $this->esccheck($value <= (0x7FFFFFFF >> 4), 'UTF-8 value too large');
            $value = ($value << 4) + self::luaO_hexavalue($this->current);
        }
        $this->esccheck($this->current === 125, "missing '}'");  // '}'
        $this->next();  // skip '}'
        $this->buffremove($removeCount);  // remove saved chars from buffer
        return $value;
    }

    // lobject.c: luaO_utf8esc (UTF-8 sequence for values up to 0x7FFFFFFF)
    public static function luaO_utf8esc(int $value): string
    {
        if ($value < 0x80) {  // ascii?
            return chr($value);
        }
        $bytes = '';
        $maximumInFirstByte = 0x3f;
        do {  // add continuation bytes
            $bytes = chr(0x80 | ($value & 0x3f)) . $bytes;
            $value >>= 6;  // remove added bits
            $maximumInFirstByte >>= 1;  // now there is one less bit available in first byte
        } while ($value > $maximumInFirstByte);  // still needs continuation byte?
        return chr(((~$maximumInFirstByte << 1) | $value) & 0xFF) . $bytes;  // add first byte
    }

    // llex.c: utf8esc
    private function utf8esc(): void
    {
        $this->buffer .= self::luaO_utf8esc($this->readutf8esc());
    }

    // llex.c: readdecesc
    private function readdecesc(): int
    {
        $value = 0;
        for ($digitCount = 0; $digitCount < 3 && self::lisdigit($this->current); $digitCount++) {  // up to 3 digits
            $value = 10 * $value + $this->current - 48;
            $this->save_and_next();
        }
        $this->esccheck($value <= 255, 'decimal escape too large');
        $this->buffremove($digitCount);  // remove read digits from buffer
        return $value;
    }

    // llex.c: read_string
    private function read_string(int $delimiter, Token $token): void
    {
        $this->save_and_next();  // keep delimiter (for error messages)
        while ($this->current !== $delimiter) {
            switch ($this->current) {
                case self::EOZ:
                    $this->lexerror('unfinished string', self::TK_EOS);
                    // no break: lexerror does not return
                case 10:
                case 13:
                    $this->lexerror('unfinished string', self::TK_STRING);
                    // no break: lexerror does not return
                case 92:  // '\\': escape sequences
                    $this->save_and_next();  // keep '\\' for error messages
                    $escapedValue = null;  // final character to be saved
                    $readNext = true;  // C label 'read_save' (else 'only_save')
                    switch ($this->current) {
                        case 97: $escapedValue = 7; break;    // '\a'
                        case 98: $escapedValue = 8; break;    // '\b'
                        case 102: $escapedValue = 12; break;  // '\f'
                        case 110: $escapedValue = 10; break;  // '\n'
                        case 114: $escapedValue = 13; break;  // '\r'
                        case 116: $escapedValue = 9; break;   // '\t'
                        case 118: $escapedValue = 11; break;  // '\v'
                        case 120:  // 'x'
                            $escapedValue = $this->readhexaesc();
                            break;
                        case 117:  // 'u'
                            $this->utf8esc();
                            continue 3;  // no_save
                        case 10:
                        case 13:
                            $this->inclinenumber();
                            $escapedValue = 10;
                            $readNext = false;  // only_save
                            break;
                        case 92:  // '\\'
                        case 34:  // '"'
                        case 39:  // '\''
                            $escapedValue = $this->current;
                            break;
                        case self::EOZ:
                            continue 3;  // no_save: will raise an error next loop
                        case 122:  // 'z': zap following span of spaces
                            $this->buffremove(1);  // remove '\\'
                            $this->next();  // skip the 'z'
                            while (self::lisspace($this->current)) {
                                if ($this->currIsNewline()) {
                                    $this->inclinenumber();
                                } else {
                                    $this->next();
                                }
                            }
                            continue 3;  // no_save
                        default:
                            $this->esccheck(self::lisdigit($this->current), 'invalid escape sequence');
                            $escapedValue = $this->readdecesc();  // digital escape '\ddd'
                            $readNext = false;  // only_save
                            break;
                    }
                    if ($readNext) {  // read_save
                        $this->next();
                    }
                    // only_save
                    $this->buffremove(1);  // remove '\\'
                    $this->save($escapedValue);
                    break;
                default:
                    $this->save_and_next();
            }
        }
        $this->save_and_next();  // skip delimiter
        $token->seminfo = substr($this->buffer, 1, strlen($this->buffer) - 2);
    }

    // llex.c: llex
    private function llex(Token $token): int
    {
        $this->buffer = '';  // luaZ_resetbuffer
        for (;;) {
            switch ($this->current) {
                case 10:  // '\n'
                case 13:  // '\r': line breaks
                    $this->inclinenumber();
                    break;
                case 32:  // ' '
                case 12:  // '\f'
                case 9:   // '\t'
                case 11:  // '\v': spaces
                    $this->next();
                    break;
                case 45:  // '-': '-' or '--' (comment)
                    $this->next();
                    if ($this->current !== 45) {
                        return 45;
                    }
                    // else is a comment
                    $this->next();
                    if ($this->current === 91) {  // long comment?
                        $sep = $this->skip_sep();
                        $this->buffer = '';  // 'skip_sep' may dirty the buffer
                        if ($sep >= 2) {
                            $this->read_long_string(null, $sep);  // skip long comment
                            $this->buffer = '';  // previous call may dirty the buff.
                            break;
                        }
                    }
                    // else short comment: skip until end of line (or end of file)
                    while (!$this->currIsNewline() && $this->current !== self::EOZ) {
                        $this->next();
                    }
                    break;
                case 91:  // '[': long string or simply '['
                    $sep = $this->skip_sep();
                    if ($sep >= 2) {
                        $this->read_long_string($token, $sep);
                        return self::TK_STRING;
                    }
                    if ($sep === 0) {  // '[=...' missing second bracket?
                        $this->lexerror('invalid long string delimiter', self::TK_STRING);
                    }
                    return 91;
                case 61:  // '='
                    $this->next();
                    return $this->check_next1(61) ? self::TK_EQ : 61;  // '==' or '='
                case 60:  // '<'
                    $this->next();
                    if ($this->check_next1(61)) {
                        return self::TK_LE;  // '<='
                    }
                    if ($this->check_next1(60)) {
                        return self::TK_SHL;  // '<<'
                    }
                    return 60;
                case 62:  // '>'
                    $this->next();
                    if ($this->check_next1(61)) {
                        return self::TK_GE;  // '>='
                    }
                    if ($this->check_next1(62)) {
                        return self::TK_SHR;  // '>>'
                    }
                    return 62;
                case 47:  // '/'
                    $this->next();
                    return $this->check_next1(47) ? self::TK_IDIV : 47;  // '//' or '/'
                case 126:  // '~'
                    $this->next();
                    return $this->check_next1(61) ? self::TK_NE : 126;  // '~=' or '~'
                case 58:  // ':'
                    $this->next();
                    return $this->check_next1(58) ? self::TK_DBCOLON : 58;  // '::' or ':'
                case 34:  // '"'
                case 39:  // '\'': short literal strings
                    $this->read_string($this->current, $token);
                    return self::TK_STRING;
                case 46:  // '.': '.', '..', '...', or number
                    $this->save_and_next();
                    if ($this->check_next1(46)) {
                        if ($this->check_next1(46)) {
                            return self::TK_DOTS;  // '...'
                        }
                        return self::TK_CONCAT;  // '..'
                    }
                    if (!self::lisdigit($this->current)) {
                        return 46;
                    }
                    return $this->read_numeral($token);
                case 48: case 49: case 50: case 51: case 52:
                case 53: case 54: case 55: case 56: case 57:  // '0'..'9'
                    return $this->read_numeral($token);
                case self::EOZ:
                    return self::TK_EOS;
                default:
                    if (self::lislalpha($this->current)) {  // identifier or reserved word?
                        do {
                            $this->save_and_next();
                        } while (self::lislalnum($this->current));
                        $name = $this->buffer;  // luaX_newstring
                        $token->seminfo = $name;
                        return self::RESERVED_WORDS[$name] ?? self::TK_NAME;
                    }
                    // single-char tokens ('+', '*', '%', '{', '}', ...)
                    $c = $this->current;
                    $this->next();
                    return $c;
            }
        }
    }

    // llex.c: luaX_next
    public function luaX_next(): void
    {
        $this->lastline = $this->linenumber;
        if ($this->lookahead->token !== self::TK_EOS) {  // is there a look-ahead token?
            $this->t->token = $this->lookahead->token;  // use this one
            $this->t->seminfo = $this->lookahead->seminfo;
            $this->lookahead->token = self::TK_EOS;  // and discharge it
            return;
        }
        $this->t->seminfo = null;
        $this->t->token = $this->llex($this->t);  // read next token
    }

    // llex.c: luaX_lookahead
    public function luaX_lookahead(): int
    {
        $this->lookahead->seminfo = null;
        $this->lookahead->token = $this->llex($this->lookahead);
        return $this->lookahead->token;
    }

    // lctype.h: lisdigit
    private static function lisdigit(int $c): bool
    {
        return $c >= 48 && $c <= 57;
    }

    // lctype.h: lisxdigit
    private static function lisxdigit(int $c): bool
    {
        return ($c >= 48 && $c <= 57) || ($c >= 97 && $c <= 102) || ($c >= 65 && $c <= 70);
    }

    // lctype.h: lislalpha (letters and '_'; non-ASCII bytes are not letters)
    private static function lislalpha(int $c): bool
    {
        return ($c >= 97 && $c <= 122) || ($c >= 65 && $c <= 90) || $c === 95;
    }

    // lctype.h: lislalnum
    private static function lislalnum(int $c): bool
    {
        return self::lislalpha($c) || self::lisdigit($c);
    }

    // lctype.h: lisspace (' ', '\t', '\n', '\v', '\f', '\r')
    private static function lisspace(int $c): bool
    {
        return $c === 32 || ($c >= 9 && $c <= 13);
    }

    // lctype.h: lisprint
    private static function lisprint(int $c): bool
    {
        return $c >= 32 && $c <= 126;
    }

    // lobject.c: luaO_hexavalue
    private static function luaO_hexavalue(int $c): int
    {
        if (self::lisdigit($c)) {
            return $c - 48;
        }
        return ($c | 0x20) - 97 + 10;  // ltolower(c) - 'a' + 10
    }

    /** What a C "%s" of these bytes shows: everything before the first '\0'. */
    public static function asCString(string $bytes): string
    {
        $nulPosition = strpos($bytes, "\0");
        return $nulPosition === false ? $bytes : substr($bytes, 0, $nulPosition);
    }
}
