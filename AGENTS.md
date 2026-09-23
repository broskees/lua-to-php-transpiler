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
| light userdata | `LuaPhp\Runtime\LightUserdata` (e.g. `debug.upvalueid`)  |

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

## Runtime conventions

- **Threads.** Every function receives the thread it runs on as `$L`
  (`Coroutine` = C's `lua_State`; the main thread is one too). Shared state is
  `$L->globalState` (registry, globals, `typeMetatables[Lua::LUA_T*]` such as
  the string metatable). There is no global "current thread": a coroutine is
  code running with another `$L` (on its own Fiber). bin/lua and lua2php
  scripts run the whole session inside a Fiber with a large C stack
  (`Standalone::runOnLargeStack`: PHP frees nested data recursively), so ask
  `$L->fiber`, never `Fiber::getCurrent()`, whether code runs in a coroutine.
- **Chunks.** `ChunkLoader::load($L, $chunk, $chunkname, $mode)` (lua_load) ->
  `Compiler::compile`/`Undump::undump` -> `Emitter::emitChunk($proto)` -> eval ->
  a factory `static function (Proto $proto): \Closure` returning the main
  function's code. Factories are cached by PHP-source hash (PHP never frees
  eval'd code). `bin/lua2php` writes the same factory into a file.
- **Emitted function** (one per Proto): `static function (Coroutine $L,
  LuaClosure $cl, array $R, int $callstatus = 0) use ($proto_<path>,
  $function_<path>...): ?array`. `$R` is the register file; the incoming
  argument list becomes registers 0..n-1. The prologue pushes a CallInfo
  (inline `CallInfo::push`) and binds `$ci->R = &$R`. Each instruction is
  `// [pc] OPNAME args ; line N` plus code; jump targets are labels `L<pc>`;
  arithmetic carries its OP_MMBIN* fallback in its `else`. Constants are PHP
  literals. `OP_CLOSURE` is `new LuaClosure($proto_X, $function_X, [upvals])`.
  Never use `array_slice`/array functions on `$R`: open upvalues make its
  elements PHP references.
- **Calls.** Lua function: `($f->code)($L, $f, $args)` returns the result list,
  or `null` for a pending tail call (`$L->tailCallFunction/Arguments`; finish
  with `Calls::finishTailCall($L)`). Native: `($native->function)($L, $args):
  array`, arguments 0-based, run via `Calls::callNative` (pushes a C
  CallInfo). PHP code (libraries, metamethods) calls any value with
  `Calls::call` / `Calls::callNoYield` (luaD_call: counts `$L->nCcalls`,
  handles `__call` and tail calls). Lua->Lua calls do not count as C calls.
- **CallInfo** chain: `$L->ci -> previous -> ... -> $L->baseCi`. Fields:
  `func`, `callstatus` (`Lua::CIST_*`; `CIST_TAIL` for tail-called frames,
  `CIST_C` for natives), `savedpc` (index of the current instruction = C's
  `currentpc`; emitted code stores it before anything that can raise, call or
  run a metamethod), `R` (live registers by reference: `debug.getlocal/setlocal`
  use `$ci->R[$reg]`; natives get their argument list), `varargs`,
  `openupval` (register => UpVal), `tbclist` (registers of pending `<close>`
  variables), `top`/`frameBytes` (stack limits). Return pops: `$L->ci = $ci->previous`.
- **Errors.** `LuaError($value, $status)`. Raise with `DebugInfo::runError($L,
  $msg)` (luaG_runerror: adds "chunk:line:" of the running Lua function) or
  `Auxiliary::error($L, $msg)` (luaL_error: position of level 1). Throwing does
  not pop CallInfos: the catcher, `Calls::protectedCall/protectedRun`
  (luaD_pcall), runs the message handler on top of the chain as it was at the
  raise point (like luaG_errormsg), then resets `$L->ci`/`nCcalls`, closes
  upvalues and pending `__close` variables of the abandoned frames and unlinks
  them. `$L->errfunc` is the current message handler (load's parser uses it).
  Variable names in messages come from ldebug.c's symbolic execution; helpers
  take "slots": register >= 0, `DebugInfo::upvalueSlot($i)`, or `NO_SLOT`.
- **Limits.** "stack overflow" when a frame's approximate slot top exceeds
  LUAI_MAXSTACK, or when the estimated PHP memory of the frames
  (`FunctionEmitter::frameBytes`: PHP frames of eval'd code grow with the
  function's size) exceeds `Calls::MAX_FRAME_BYTES` (256 MB, about 120000
  small Lua frames); both limits grow while the overflow is handled, and a
  second overflow is "error in error handling". C calls: LUAI_MAXCCALLS (200)
  -> "C stack overflow".
- **Upvalues.** An open `UpVal->v` is a PHP reference to `$R[$reg]`; one UpVal
  per register (`Upvalues::find`), closed by `Upvalues::close`/`closeUpvalues`
  (OP_CLOSE, OP_RETURN/OP_TAILCALL with k, error unwinding).
- **Tables.** `LuaTable`: `arr` (integer keys), `hash` (string keys; PHP may
  store "10" as 10 there, it is still a string), `otherValues/otherKeys`
  (float, boolean, object keys); nil is never stored; `sizearray` is the
  border hint (C's alimit); `next()` keeps a cursor so traversal is O(1) and
  survives clearing fields. Light userdata: `LightUserdata::pointingTo($obj)`.
- **Hooks (Phase 4).** `FunctionEmitter::hookCheck($pc)` returns code emitted
  before every instruction (empty now); hook state lives on `Coroutine`
  (`hook`, `hookmask`, `basehookcount`, `hookcount`, `allowhook`, `oldpc`).
- **PHP hygiene.** `Standalone::configurePhp()`: PHP warnings become
  exceptions (a crash, never output), exception traces drop arguments, stdout
  is buffered (`print` echoes; `Standalone::flushStdout()` before stderr/exit).
- **Reference build.** lua5.4 is built with LUA_COMPAT_5_3: `__le` falls back
  to `not __lt(b, a)`, and math has pow, ldexp, frexp, cosh, sinh, tanh, log10,
  atan2.

## Testing

Tests are the project's memory. Behavior is checked against the reference
interpreter `lua5.4` (5.4.9) and compiler `luac5.4`, both installed.

    php tests/run.php            our own tests (unit + differential); must stay green
    tests/bytecode.sh [file...]  diff `bin/luac -l -l -p` vs `luac5.4 -l -l -p` for official test files
    tests/official.sh [name...]  run official test files one at a time through bin/lua with
                                 -e"_U=true _soft=true _port=true _nomsg=true"; PASS/FAIL per file

- Differential cases live in `tests/diff/*.lua`: each runs under `lua5.4` and
  under `bin/lua` from `tests/diff`; exit status, stdout and stderr must match
  (0x... addresses and the program name normalized). `tests/unit/Lua2PhpTest.php`
  also runs every case through `bin/lua2php`. Add one for every bug.
- A bug gets a failing test before it gets a fix.
- Never make a test pass by skipping, deleting, or loosening it.
- Run `php tests/run.php` before claiming anything is done.

## Phases

Each gate is verified by the coordinator before the box is ticked. Official
files are run with `tests/official.sh`; once a file passes it must keep passing.

- [x] Phase 0 — foundation: Proto, OpCodes, Undump, Dump, Listing, ChunkId,
      NumberFormat, temporary luac bridge behind `Compiler::compile`,
      `bin/luac`, test harness. Gate met: `tests/bytecode.sh` passes on all 33
      official files; `Dump(Undump(b)) === b` with and without `-s`.
- [x] Phase 1A — compiler front-end (llex/lparser/lcode) plus
      `src/Runtime/StringToNumber.php` replaces the bridge. Gate:
      `tests/bytecode.sh` passes on every official file and our corpus; dumps
      byte-identical to `luac5.4 -o`; syntax error messages match lua5.4.
- [x] Phase 1B — emitter + runtime core + base library + upvalue debug
      functions + `bin/lua` + `bin/lua2php` + `tests/official.sh`. Gate:
      differential corpus green; official vararg passes; a transpiled
      `out.php` runs standalone and matches lua5.4.
- [ ] Phase 2 — libraries in parallel lanes: string/utf8, math/table,
      io/os/package, coroutine (Fiber). Gate: strings, pm, tpack, utf8, math,
      sort, nextvar, files, attrib, constructs, bitwise, goto, closure,
      literals, events.
- [ ] Phase 3 — errors, stack overflow, calls/string.dump, to-be-closed
      variables. Gate: errors, cstack, calls, locals, coroutine.
- [ ] Phase 4 — debug library + hooks, GC semantics. Gate: db, gc, gengc,
      big, verybig.
- [ ] Phase 5 — `all.lua` prints `final OK !!!` under `-e"_U=true"`.
