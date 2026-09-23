# lua-to-php-transpiler

Lua 5.4 source goes in, PHP comes out, and the PHP behaves exactly like Lua 5.4.9
at runtime. The transpiler and its runtime are written in PHP (8.5 CLI, no
Composer, no extensions beyond what `php -m` shows).

## Definition of done

From `reference/lua-5.4.9-tests/`:

    php ../../bin/lua -e"_U=true" all.lua

prints `final OK !!!`, exactly as `lua5.4 -e"_U=true" all.lua` does on this
machine. Full mode (without `_U`) is out of scope: it needs C libraries via
`package.loadlib` and the internal `T` test API.

## Architecture (decided; do not redesign)

We port Lua 5.4.9's own C implementation and replace only the bytecode
interpreter loop with an ahead-of-time bytecode→PHP emitter:

    Lua source  --Lexer/Parser/CodeGen (llex.c, lparser.c, lcode.c)-->  Proto
    binary chunk --Undump (lundump.c)-->                                 Proto
    Proto --Emitter--> PHP source (one PHP function per Proto; registers in an
                       array $R; jumps are `goto` labels)
    Runtime: lvm.c/ldo.c/ldebug.c/ltm.c/lobject.c/ltable.c semantics
    Libraries: lbaselib.c, lstrlib.c, ltablib.c, lmathlib.c, ... ported to PHP

A Proto is real Lua 5.4 bytecode plus debug info, identical to what `luac5.4`
produces. Reason: the official tests inspect VM internals — register slots
through `debug.getlocal` (e.g. `"(temporary)"`), exact line-hook sequences,
`activelines`, variable names in error messages (`upvalue 'b'`), and
`string.dump` round-trips. Emitting the same bytecode as luac makes all of that
exact and mechanically checkable with `luac5.4 -l -l`.

The C source in `reference/lua-5.4.9/src/` is the spec. Port faithfully and
name the C origin in a comment (`// lcode.c: luaK_exp2anyreg`) so the next
agent can diff behavior against C. Never edit anything under `reference/`.

## Layout

    bin/lua            standalone interpreter, mirrors lua.c (arg table, -e, -l, -v, -, script args)
    bin/luac           mirrors luac.c (-l, -l -l, -p, -o, -s)
    bin/lua2php        the transpiler deliverable: in.lua -> standalone out.php
    src/autoload.php   PSR-4 autoloader: namespace LuaPhp\ -> src/
    src/Compiler/      Proto, OpCodes, Lexer, Parser, CodeGen, Dump, Undump, Listing, Compiler
    src/Emitter/       Proto -> PHP source
    src/Runtime/       values, tables, calls, errors, coroutines, metamethods, debug info
    src/Lib/           standard libraries (one file per C lib)
    tests/             our own tests + harness scripts (see Testing)
    reference/         Lua 5.4.9 C source and official test suites (read-only)

## Value representation

| Lua      | PHP                                                             |
|----------|-----------------------------------------------------------------|
| nil      | `null`                                                          |
| boolean  | `bool`                                                          |
| integer  | `int` (64-bit; must wrap like C — PHP overflows into float, so integer arithmetic goes through runtime helpers) |
| float    | `float`                                                         |
| string   | `string` (raw bytes)                                            |
| table    | `LuaPhp\Runtime\LuaTable`                                       |
| function | `LuaPhp\Runtime\LuaClosure` (Lua) or `LuaPhp\Runtime\NativeFunction` (builtin; `what == "C"`) |
| thread   | `LuaPhp\Runtime\Coroutine` (built on PHP `Fiber`)               |
| userdata | `LuaPhp\Runtime\Userdata` (e.g. io file handles)                |

Never apply PHP's `==`, `<`, `+`, `.` etc. directly to Lua values: Lua compares
int and float exactly (2^53+1 ~= 2^53), coerces strings in arithmetic, formats
floats with `%.14g`, and dispatches metamethods. Use the runtime helpers.

PHP arrays convert numeric-string keys ("10") to int keys — LuaTable must keep
Lua string keys and integer keys distinct.

## Contracts between components

- `LuaPhp\Compiler\Compiler::compile(string $source, string $chunkname): Proto`
  is the only way source becomes a Proto. It throws `LuaPhp\Compiler\CompileError`
  whose message is exactly Lua's (e.g. `[string "x = +"]:1: unexpected symbol near '+'`).
- `Proto` mirrors C `Proto` (lobject.h); instructions are encoded exactly as in
  lopcodes.h; constants are PHP `null|bool|int|float|string`.
- Runtime errors are `LuaPhp\Runtime\LuaError` carrying the Lua error value.
- Details of the emitted code, CallInfo and calling convention are owned by the
  runtime; whoever builds them documents them in a "Runtime conventions" section
  below.

## Testing

Tests are the project's memory. Behavior is checked against the reference
interpreter `lua5.4` (5.4.9) and compiler `luac5.4`, both installed.

    php tests/run.php            our own tests (unit + differential); must stay green
    tests/bytecode.sh [file...]  diff `bin/luac -l -l -p` vs `luac5.4 -l -l -p` for official test files
    tests/official.sh [name...]  run official test files one at a time through bin/lua with
                                 -e"_U=true _soft=true _port=true _nomsg=true"; PASS/FAIL per file

- Differential cases live in `tests/diff/*.lua`: each runs under `lua5.4` and
  under `bin/lua`; stdout and exit status must match. Add one for every bug.
- A bug gets a failing test before it gets a fix.
- Never make a test pass by skipping, deleting, or loosening it.
- Run `php tests/run.php` before claiming anything is done.

## Phases

Each gate is verified by the coordinator before the box is ticked.

- [ ] Phase 0 — foundation: Proto, OpCodes, Undump, Dump, Listing, ChunkId,
      temporary luac bridge behind `Compiler::compile`, `bin/luac`, test
      harness. Gate: `tests/bytecode.sh` passes for every official test file;
      `Dump(Undump(b)) === b` for luac5.4 output with and without `-s`.
- [ ] Phase 1A — compiler front-end (llex/lparser/lcode) replaces the bridge.
      Gate: `tests/bytecode.sh` passes on every official test file and dumps are
      byte-identical to `luac5.4 -o`; syntax error messages match luac5.4.
- [ ] Phase 1B — emitter + runtime core + base library + `bin/lua` + `bin/lua2php`.
      Gate: official constructs, vararg, closure, goto, events, bitwise,
      literals pass; transpiled `out.php` runs standalone.
- [ ] Phase 2 — libraries: string/utf8 (strings, pm, tpack, utf8), math/table
      (math, sort, nextvar), io/os/package (files, attrib).
- [ ] Phase 3 — coroutines, errors, stack overflow, calls/string.dump,
      to-be-closed variables (coroutine, errors, cstack, calls, locals).
- [ ] Phase 4 — debug library + hooks, GC semantics (db, gc, gengc, big, verybig).
- [ ] Phase 5 — `all.lua` prints `final OK !!!` under `-e"_U=true"`.
