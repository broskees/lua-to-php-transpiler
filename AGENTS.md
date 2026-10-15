# lua-to-php-transpiler

Lua 5.4 source goes in, PHP comes out, and the PHP behaves exactly like Lua 5.4.9
at runtime. The transpiler and its runtime are written in PHP (8.5 CLI; 8.4 and
newer run it). The package installs with Composer
(`broskees/lua-to-php-transpiler`) and has no runtime dependencies beyond PHP
and its ctype extension (opcache, posix and pcntl are optional: every use has a
fallback); Composer is used only for the dev-only code-style tool
(PHP_CodeSniffer). `src/autoload.php` loads the classes with or without
Composer.

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

The code follows PSR-12, and `composer lint` checks it (phpcs.xml.dist:
src/, bin/, tests/, bench/; 0 errors and 0 warnings). C names become
camelCase methods without their module prefix, the class being the module
(luaK_exp2anyreg is `CodeGen::exp2anyreg`, luaY_nvarstack
`Parser::nvarstack`, GETARG_sBx `OpCodes::getArgSBx`), and C's constants
upper case (MAXARG_Bx is `OpCodes::MAXARG_BX`); the comment keeps the C
name. A PHP file declares symbols or runs code, not both: bin/ scripts only
call their `LuaPhp\Cli` class. The PHP the emitter generates is data, not
code: restyling the emitter must not change a byte of it (compare bin/lua2php
output before and after).

## Layout

    bin/lua            standalone interpreter, mirrors lua.c (arg table, -e, -l, -v, -, script args)
    bin/luac           mirrors luac.c (-l, -l -l, -p, -o, -s)
    bin/lua2php        the transpiler deliverable, ahead of time: in.lua -> out.php, or a project
                       directory -> one .php per .lua (require/dofile load those; see Chunks)
    composer.json      the Composer package (bin, PSR-4 autoload, scripts test and lint)
    phpcs.xml.dist     the code style (PSR-12) for `composer lint`
    src/autoload.php   PSR-4 autoloader: namespace LuaPhp\ -> src/
    src/Cli/           the programs of bin/: LuaCommand (lua.c), LuacCommand (luac.c), Lua2PhpCommand
    src/Compiler/      Proto, OpCodes, Lexer, Parser, CodeGen, Dump, Undump, Listing, Compiler
    src/Emitter/       Proto -> PHP source
    src/Runtime/       values, tables, calls, errors, coroutines, metamethods, debug info
    src/Lib/           standard libraries (one file per C lib)
    tests/             our own tests + harness scripts (see Testing; shared helpers in tests/harness.php)
    bench/             memory/speed benchmarks against lua5.4 (see bench/README.md)
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
  code running with another `$L` (on its own Fiber). The main thread runs
  on the process's own C stack (no fiber); ask `$L->fiber`, never
  `Fiber::getCurrent()`, whether code runs in a coroutine (PHP's cycle
  collector runs destructors in a fiber of its own).
- **Coroutines.** `Coroutine::newThread($L, $body)` (lua_newthread),
  `$co->resume($from, $args)` (lua_resume: `[LUA_YIELD|LUA_OK|error status,
  values|error value]`), `$L->yield($values)` (lua_yieldk: `Fiber::suspend`),
  `$co->closeThread($from)` (lua_closethread); lcorolib.c is `Lib\CoroutineLib`.
  The first resume starts `$co->fiber`; the fiber keeps the coroutine's whole
  PHP stack across a yield, so the pcalls, metamethods and iterators between
  resume and yield just continue (no continuations, unroll or precover).
  Yields follow C's rule: allowed only when `$L->nny` (non-yieldable calls) is
  0; the main thread starts at 1 ("attempt to yield from outside a
  coroutine"), `Calls::callNoYield` and `Calls::call` from a native frame add
  1 ("attempt to yield across a C-call boundary"). State: `status` (LUA_OK,
  LUA_YIELD or the error that killed it), `ci !== baseCi` (running/normal),
  `body` (the function of a coroutine not started yet; LUA_OK at base level
  with no body = dead). While suspended, `$co->ci` is the CallInfo of the
  native `coroutine.yield`; a coroutine that died with an error keeps its
  CallInfo chain as it was at the raise point, and its `errorValue`, until
  closeThread (as C). While the `__close` methods of frames an error
  abandoned run, those frames are listed in `pendingCloses` (see Errors);
  when a pcall in a coroutine runs them they may yield, and closeThread
  splices the frames back where C's stack has them. A resume sets
  `$co->nCcalls` and `nCcallsBase` to the resumer's + 1 (nested resumes
  hit "C stack overflow" at LUAI_MAXCCALLS as in C), and an error a pcall
  in a coroutine catches (a yieldable one) sets nCcalls back to
  `nCcallsBase`: C unwinds its C calls there (the yield's longjmp, or
  lua_resume's luaD_rawrunprotected catching the error before precover
  finishes the pcall) and the continuations count no level, so a
  coroutine may nest pcalls and metamethods past LUAI_MAXCCALLS across
  yields. Our PHP frames from before survive; when one returns, every
  call made since has returned, so it finds the count at `nCcallsBase`
  and leaves it there (`Calls::callk`); other protected calls run with
  nny > 0 (no yield inside) and restore their saved count. Each fiber gets a
  C stack of `Coroutine::FIBER_STACK_BYTES` = 256 KB of address space
  (embedded states keep the host's fiber.stack_size: Embedding conventions) (only
  touched pages cost memory: a suspended coroutine costs about 10 KB RSS,
  8.4 KB of it PHP's Fiber itself: C stack pages and the first page of its
  16 KB VM stack; all fibers share one PHP closure, `wrap` closures one
  function). Lua depth never uses it (Lua calls,
  metamethods and library calls are PHP calls, on PHP's heap-allocated VM
  stack; deep data is freed by Teardown), so the size comes from what PHP
  itself needs, measured as the touched pages of fiber stacks: 36 KB for
  the official suite (also with every file run inside a coroutine), 40 KB
  for a first load() that autoloads the compiler, 60 KB compiling a
  190-operand concatenation (PHP's compiler recurses per operand of emitted
  expressions: never emit expressions nested deeper than a bounded amount),
  84 KB with opcache's JIT on; opcache's optimizer and JIT recurse per
  basic block of a function, bounded by segments (see Emitted function:
  never emit an unbounded amount of code a search can walk in one path).
  PHP keeps the last 48 KB in reserve; 256 KB
  leaves 2.5 times the worst case. Linux allows about 65530 mappings per
  process by default and each fiber stack takes 2, so about 32000 live
  coroutines at most. A fiber whose C stack cannot be mapped (address
  space used up, e.g. under ulimit -v) is a failed allocation: its first
  resume runs gc_collect_cycles and tries again once (lmem.c: tryagain),
  then fails with LUA_ERRMEM "not enough memory" (the coroutine is dead).
  Freeing: a finished coroutine's fiber is freed at
  once; a suspended one that becomes unreachable (a cycle: Coroutine ->
  Fiber -> its frames -> `$L`) is freed by PHP's cycle collector, and
  closeThread drops it explicitly. PHP destroys a suspended Fiber by
  unwinding it with a graceful exit: no catch block runs, so no Lua pcall or
  `__close` sees it, but `finally` blocks and `__destruct` methods do: never
  run Lua code from `finally` or `__destruct`.
- **Teardown.** PHP frees what an object refers to recursively on the C
  stack (a dropped list of a million tables would need about 150 MB of it,
  and PHP crashes without a guard). Every class whose objects can link to
  more Lua values has a destructor following `Teardown`'s protocol (hand
  the fields to `Teardown::$pending` when a release is running, else clear
  them and release the queue one value at a time): LuaTable, UpVal,
  NativeFunction, Userdata, Coroutine (which hands over every frame of
  its CallInfo chain and `pendingCloses` on its own, unlinked: a frame may
  hold another coroutine, so a chain of coroutines dead with the previous
  one in a register is freed one frame at a time; `Calls::unlinkAbandonedFrames`
  unlinks the frames an error abandons). A value a destructor's local
  still holds after the hand-over is freed after release(), with the
  guard off: unset such locals first. A LuaClosure leads only to UpVals; a LuaTable hands its
  `LuaTableExtra` over whole; CallInfo chains hang only off their
  Coroutine. A new class that can hold Lua values needs such a
  destructor unless it always leads to one of these within a few steps.
  Destructors never run Lua code nor change what Lua can see: they run
  only on objects nothing reaches (or at shutdown). Each freed table pays
  about 60 ns for it (a destructor call).
- **Chunks.** `ChunkLoader::load($L, $chunk, $chunkname, $mode)` (lua_load) ->
  `LoadCache` lookup -> on a miss `Compiler::compile`/`Undump::undump` ->
  `Emitter::emitChunk($proto)` -> eval -> a factory `static function (Proto
  $proto): \Closure` returning the main function's code. Factories are also
  cached by PHP-source hash (PHP never frees eval'd code), and the emitted
  PHP never contains the chunk name (it reaches Lua in the Proto): one
  text loaded under many names compiles one factory.
  Ahead of time: lua2php output never compiles or emits a file at run time.
  `bin/lua2php <dir> [-o <outdir>]` transpiles every `*.lua` of a project
  (x.lua -> x.php, other names get ".php": `ChunkLoader::precompiledFileName`).
  Each generated file runs as a script, and when the runtime includes it
  (`ChunkLoader::$includingPrecompiled`) returns its chunk instead: factory,
  Protos as a binary chunk, parser nesting, or the load error of a file that
  did not compile. In its scripts (`GlobalState::$filesArePrecompiled`)
  require/dofile/loadfile resolve names as Lua does (a file counts as found
  when its .php exists and was generated from it: the header records the
  Lua file's name, since "x" and "x.lua" share x.php) and
  `ChunkLoader::loadPrecompiled` sets the chunk name at load time; a Lua
  file without its .php (written at run time, stdin) is a LuaError "not
  transpiled ahead of time". Only `load()` compiles there, and so do the
  other luaL_loadbuffer calls on code that exists only at run time: the
  commands of debug.debug() and a string LUA_INIT go through `load()` and
  its cache; `LUA_INIT=@file` loads the precompiled file.
  `bin/lua` still compiles files on the fly.
  load(reader) is `ChunkLoader::loadFromReader` (lua_load with a ZIO):
  the first piece decides binary or text (ldo.c f_parser), then the Lexer
  or Undump asks for the next piece (`$readMore`) only when it needs the
  next byte (lzio.c luaZ_fill), so the reader is called exactly as often
  and in the same order as in C: a syntax error stops it, a binary chunk
  is read to its last byte and no further, '' or nil ends it for good,
  and a reader called while the parser is nested runs at the parser's
  C-call depth (enterlevel counts in L->nCcalls). The Lexer holds only the
  piece in hand (`fill()` runs only at the end of a piece or a memory
  check: the per-byte path is one comparison). Its key known only then,
  a reader's chunk is looked up in the cache after it compiled (a hit
  skips emitting), a string's before.
  The load cache (`LoadCache`): key = chunk name + exact bytes. A hit undumps
  fresh Protos sharing equal strings like the lexer (not the source name;
  two loads look like two compiles to '%p') and is used only where
  compiling at the current C-call
  depth would succeed (`ChunkLoader::nestingError`); failed compiles are not
  cached. In memory (1000 entries), and on disk when a directory is set: one
  PHP file per chunk named by sha256(fingerprint of src/ + PHP version, key),
  loaded with include so opcache and the JIT apply. lua2php scripts use
  `LUAPHP_CACHE_DIR`, else `sys_get_temp_dir()/luaphp-<euid>`; bin/lua only
  `LUAPHP_CACHE_DIR` (the uid comes from a file's owner: no posix needed).
  As ssh's StrictModes, once per process: the directory must be ours, have
  no group/other bits and not be a symlink, and every directory above it
  must be root's or ours and not group/other-writable unless sticky; else no
  disk cache. It is then used by its real path. Writes are temp file +
  rename under the flock of its `usage` counter, which empties the directory
  before it passes 20000 entries or 256 MB (`CacheDirectory`, shared with
  the embedding API's compile cache).
- **Emitted function** (one per Proto): `static function (Coroutine $L,
  LuaClosure $cl, array $R, int $callstatus = 0) use ($proto_<path>,
  $function_<path>...): ?array`. `$R` is the register file; the incoming
  argument list becomes registers 0..n-1. The prologue pushes a CallInfo
  (inline `CallInfo::push`) and binds `$ci->R = &$R`. Each instruction is
  `// [pc] OPNAME args ; line N` plus code; jump targets are labels `L<pc>`;
  arithmetic carries its OP_MMBIN* fallback in its `else`. Constants are PHP
  literals, except long strings (over 40 bytes), read as `$cl->proto->k[i]`:
  '%p' tells long strings apart by PHP string identity, the Proto holds one
  PHP string per Lua string object (the Lexer and `Undump(..., true)` for
  lua2php share equal strings like llex.c), and opcache interns literals or
  not. A closure's class fits its Proto's upvalue count (see Upvalues), so
  the function's `$cl` is typed with it, upvalue `i` is `$cl->u<i>` or
  `$cl->upvals[i]`, and `OP_CLOSURE` is `new LuaClosure1($proto_X,
  $function_X, $upval)` ... or `new LuaClosureN($proto_X, $function_X,
  [upvals])`.
  A constant table constructor is one piece of code, commented
  `// [first..last] constant table constructor`: a straight run (nothing
  jumps into it) of 3 or more instructions from an OP_NEWTABLE, made of
  OP_NEWTABLE, OP_SETFIELD/SETI/SETTABLE, OP_SETLIST with a count and the
  OP_LOAD* that fill their registers, reading only registers the run wrote
  (nested constructors and the constant statements after it included;
  `FunctionEmitter::constructorRunEnd`). `TableConstructor::run($L, $ci,
  $R, first, last)` steps through those instructions from the Proto
  exactly as their inline code: the `$trap` check before each, the GC
  check after each OP_NEWTABLE, `Vm::setTable` when a hook or finalizer
  changed a register or gave the table a metatable. In a function whose
  runs total at most 4096 instructions, a fast path comes first, taken
  when `!$trap` and the run's tables leave `gcDebt` <= 0 (so nothing can
  run or look in between): the run's final tables built from PHP array
  literals (`$table<i>`, long strings from the Proto, unset after the
  run: a local would keep a table Lua dropped alive) and the final value
  of every register it writes, charging the same debt. A bigger function
  (a data module) gets only the calls: its literals would need more memory
  to compile than PHP's default limit, while the Proto is there anyway.
  `FunctionEmitter::$inlineConstructors` (tests only) emits them inline.
  A big function is segments: opcache's optimizer and the JIT number a
  function's basic blocks with a recursive depth-first search
  (zend_cfg.c: compute_postnum_recursive, about 40 bytes of C stack per
  block on its deepest path), which overflowed a coroutine's 256 KB fiber
  stack (segfault) at about 2,300 instructions in one PHP function. So
  when a function's weight (`FunctionEmitter::instructionWeight`: 1 per
  instruction, 1 more per value of a call's results, OP_VARARG,
  OP_SETLIST, OP_CONCAT, OP_TFORCALL; a constructor run is 1) exceeds
  `FunctionEmitter::$maximumSegmentWeight` (256), its body after the
  prologue is `$entry = 'L0'; while (true) { switch ($entry) { case 'L0':
  ...segment... $entry = 'L<next>'; continue 2; case 'L<next>': ... } }`.
  A segment is at most 256 weight (measured at most 108 bytes of C stack
  per unit, so 27 KB), cut only between pieces of code (never before a
  consumed instruction nor inside a constructor run), at the last place
  in its reach inside the fewest loops. A jump within a segment is a
  goto; a jump to another segment is `{ $entry = 'L<pc>'; continue 2; }`
  (`FunctionEmitter::jump`), with a `case 'L<pc>':` (or `case 'T<pc>':`
  for OP_TFORPREP's jump past OP_TFORCALL's hook check) at its target:
  no path of the graph leads from one segment into another except
  through the switch, so the search goes no deeper than one segment.
  Every emitted jump goes through `jump()`: a plain goto between segments
  brings the crash back. Instruction code, hook checks and savedpc are the
  same; only the transfers differ. Measured: require, a warm load() cache
  and the tracing JIT in a coroutine need 58-77 KB of fiber stack (48 KB
  of it PHP's reserve); a crossing costs 6-7 ns without the JIT. Tests
  run the diff corpus with every function cut into one-instruction
  segments (`tests/unit/tiny_segments.php`, `$maximumSegmentWeight = 1`;
  for official files, `OFFICIAL_PHP_OPTIONS` in tests/official.sh).
  Compact forms: PHP needs 3 to 8 KB of memory to compile one
  instruction's inline code (a function of 32,500 instructions needed
  146 MB with opcache, over PHP's default memory_limit of 128M), about
  1 KB for a call. So when a chunk's inline code weighs more than
  `Emitter::INLINE_WEIGHT_MAXIMUM` (4096, `FunctionEmitter::inlineWeights`:
  instructionWeight, a constructor run 1 plus 1 per 16 of its literal
  instructions), the code outside loops (backward jumps) of its heaviest
  functions, heaviest first until the rest fits, is compact
  (`FunctionEmitter::compactCodeOutsideLoops`): code that runs once per
  call, typically a module's main chunk; loops keep their inline fast
  paths, and so does every chunk that fits (all the speed workloads). A
  compact instruction is its comment and one call to a `Runtime\Op`
  method with `$L`, its pc and its operands as literals (registers as
  numbers, constants as values, long strings from the Proto), e.g.
  `Op::setField($L, 8, 1, 'fmt', 2);`, `if (Op::lt($L, 5, 1, 2)) goto L7;`,
  `$top = Op::call($L, 9, 3, 2, 0);`. The method does exactly what the
  inline code does, in the same order: hook check (with `$top` for isIT),
  operands read after it, the same fast paths, savedpc before anything that
  can raise, call or look, the GC check; it takes the frame from `$L->ci`
  (always the emitted function's `$ci` between instructions) and writes
  `$L->ci->R` (a reference to `$R`). Jumps stay in emitted code: a test's
  method returns its condition, OP_JMP's only checks the hook. Arithmetic
  takes its consumed OP_MMBIN*'s flip (lcode.c repeats the operands; x - n
  is OP_ADDI -n with `__sub` n, compact as a subtraction). Inline: RETURN*,
  TAILCALL, the for/generic-for loop instructions, CLOSURE (they use the
  function's locals), VARARGPREP, OP_SELF with a register key, and what
  luac never produces (an OP_MMBIN* that is a jump target or does not
  repeat the operands, a non-string constant key). A change to an
  instruction's inline code changes its Op method too. A function with
  compact code is kept from PHP's tracing JIT (`opcache_jit_blacklist`,
  when defined): the JIT starts a trace after each hot return from an Op
  method, thousands for a big function that runs often (3,000 calls of a
  32,500-instruction function: 6.8 s with the JIT, 1.2 s without tracing
  it); its Op methods are JIT-compiled anyway, and the JIT did not speed
  up such a function's loops before either. The OP_JMP after a test is
  emitted only as its comment unless something jumps to it (the test's
  code ends with its jumps). Tests run the diff corpus and the official
  files with every instruction that has a compact form compact, loops too
  (`FunctionEmitter::$compactEverywhere`, `tests/unit/compact_forms.php`;
  with tiny segments, `compact_tiny_segments.php`).
  Never use `array_slice`/array functions on `$R`: open upvalues make its
  elements PHP references.
- **Calls.** Lua function: `($f->code)($L, $f, $args)` returns the result list,
  or `null` for a pending tail call (`$L->tailCallFunction/Arguments`; finish
  with `Calls::finishTailCall($L)`). Native: `($native->function)($L, $args):
  array`, arguments 0-based, run via `Calls::callNative` (pushes a C
  CallInfo). PHP code (libraries, metamethods) calls any value with
  `Calls::call` / `Calls::callk` / `Calls::callNoYield` (luaD_call: counts
  `$L->nCcalls`, handles `__call` and tail calls). Lua->Lua calls do not count
  as C calls. `Calls::call` is yieldable only when the running frame is Lua
  code (the VM calling a metamethod or iterator); from a native frame it is
  lua_call without a continuation, so the callee cannot yield. `callk`
  (lua_callk with a continuation) stays yieldable: in C only pcall/xpcall
  (`Calls::protectedCallk`), dofile and pairs' `__pairs` have one, so only
  they use it.
- **CallInfo** chain: `$L->ci -> previous -> ... -> $L->baseCi`. Fields:
  `func`, `callstatus` (`Lua::CIST_*`; `CIST_TAIL` for tail-called frames,
  `CIST_C` for natives), `savedpc` (index of the current instruction = C's
  `currentpc`; emitted code stores it before anything that can raise, call or
  run a metamethod), `R` (live registers by reference: `debug.getlocal/setlocal`
  use `$ci->R[$reg]`; natives get their argument list), `varargs`,
  `openupval` (register => UpVal), `tbclist` (registers of pending `<close>`
  variables), `top`/`frameBytes` (stack limits). Return pops: `$L->ci = $ci->previous`.
  debug.getlocal's "(temporary)" slots reach C's stack top
  (`DebugInfo::frameSlots`, luaG_findlocal's limit: the next frame's
  function, a vararg callee's moved frame, a finalizer's A + 1, the hook
  table above a hooked frame). What C's shared stack holds beyond what a
  frame wrote (earlier callees' leftovers, how a native uses its stack:
  pcall's pushed `true`, select's results) would need an emulated stack.
- **Errors.** `LuaError($value, $status)`. Raise with `DebugInfo::runError($L,
  $msg)` (luaG_runerror: adds "chunk:line:" of the running Lua function) or
  `Auxiliary::error($L, $msg)` (luaL_error: position of level 1). Natives
  that raise a Lua value (C: lua_error, as error, assert, coroutine.wrap and
  dofile do) use `LuaError::raise($value)`: the string "not enough memory"
  is a memory error there (LUA_ERRMEM: no message handler), as in lapi.c.
  Throwing does
  not pop CallInfos: the catcher, `Calls::protectedCall/protectedRun`
  (luaD_pcall), runs the message handler on top of the chain as it was at the
  raise point (like luaG_errormsg), then resets `$L->ci`/`nCcalls`, closes
  upvalues and pending `__close` variables of the abandoned frames and unlinks
  them (`Calls::closeProtected`: an error in a `__close` also goes through the
  handler; a pcall/xpcall in a yieldable coroutine closes with yieldable
  calls, as C's precover/finishpcallk). While they close, in every unwind
  (protected calls, closeThread, closeState), `$L->pendingCloses` lists
  the abandoned frames with the frame and register being closed: C keeps
  its stack up to that variable below each `__close` call (lfunc.c:
  prepcallclosemth), so the collector marks the variables still to close
  and the registers below them, and not what was above (see GC).
  `$L->errfunc` is the current message handler (load's parser uses it).
  Each new LuaError copies PHP's whole stack trace (PHP builds it when the
  exception is created), so an error that n nested pcalls each catch and
  raise again costs O(n²): 10,000 levels across yields take 31 s (lua5.4:
  0.1 s).
  Variable names in messages come from ldebug.c's symbolic execution; helpers
  take "slots": register >= 0, `DebugInfo::upvalueSlot($i)`, or `NO_SLOT`.
  PHP's C-stack guard (`\Error` "Maximum call stack size of N bytes
  reached", when PHP code recursing through internal functions nears the
  end of a stack) becomes Lua's "C stack overflow" where Lua errors are
  caught (`Calls::cStackOverflowError` in protectedRun, the message
  handler loop, closeProtected, `Coroutine::resume`); load() compiles the
  emitted PHP inside its protected parser. Running out while PHP compiles
  is a fatal error that cannot be caught (see Coroutines).
- **Limits.** "stack overflow" when a frame's approximate slot top exceeds
  LUAI_MAXSTACK, or when the estimated PHP memory of the frames
  (`FunctionEmitter::frameBytes`: PHP frames of eval'd code grow with the
  function's size) exceeds the thread's frame budget: `Calls::frameBudget()`
  when the state is created (GlobalState::$frameBudget), half the memory
  left below memory_limit once a quarter (at least 16 MB) is kept aside,
  at most `Calls::MAX_FRAME_BYTES` (256 MB, about 100000 small Lua frames,
  bin/lua's 4G), so deep recursion stays a catchable error at any limit
  (frames really take up to about twice their estimate without opcache).
  Both limits grow while the overflow is handled (frames: 1/32 of the
  budget, 1 to 8 MB), and a second overflow is "error in error handling".
  C calls: LUAI_MAXCCALLS (200) -> "C stack overflow". Memory: PHP's
  memory_limit is a fatal error no
  pcall sees, so every library function that can build a large result in
  one call checks its size first (`MemoryLimit::reserve`, or `grow` for a
  string built piece by piece like a luaL_Buffer: room for twice its
  length) and raises `LuaError('not enough memory', LUA_ERRMEM)` as C's
  failing allocator does (no position, no message handler). Room =
  memory_limit - memory_get_usage(true) - 4 MB, checked again after
  gc_collect_cycles + gc_mem_caches; results up to 64 KB are never
  checked. Lua's own limits come first ("resulting string too large").
  Covered: string.rep/sub/upper/lower/reverse/format/gsub/pack, pattern
  captures, `..` (emitted code joins strings inline only up to 64 KB in
  all, else `Vm::concat`), table.concat (pieces joined
  `TableLib::PIECES_PER_PART` (1024) at a time, the parts checked as they
  grow like a luaL_Buffer: one implode of all pieces needed 64 bytes per
  piece besides the result), string.format('%p') of a long string equal
  to one it numbered (reading reference counts dumps the whole string,
  twice its length: `StringFormat::PROBE_BYTES_PER_BYTE`, so a huge one is
  "not enough memory" where lua5.4 prints a pointer; the collector keeps
  a numbered string it has no room to look at), io reads ('a', lines,
  counts), os.date, package.searchpath, load's reader (the chunk it
  gave, kept for the cache's key), string.unpack's strings, output
  (print, io.write, warn: sinks copy it), messages that copy a
  string (a position prefix: luaL_error, luaG_runerror, error,
  coroutine.wrap; a `__name` in a type name or tostring; a module's name
  from package.loaded in a traceback or argument error; require's
  not-found message; tracebacks grow like a luaL_Buffer), and lists of
  results whose count an argument sets (table.unpack, string.byte,
  utf8.codepoint, string.unpack, select: `MemoryLimit::RESULT_BYTES`, 112
  each, measured with the copy the next call makes). Compiling
  (`ChunkLoader::load`) checks as it goes: the Lexer every 16 KB of
  source (`Lexer::COMPILE_BYTES_PER_SOURCE_BYTE`, 64: measured 14 to 62),
  also inside a token, plus the token's length (PHP moves a growing
  string and copies the token once read: a single 24 MB token under 64M
  is "not enough memory", as C's growing token buffer, llex.c save); with
  a reader, the first piece's window covers that piece and every later
  one a whole 16 KB. Undump reserves each long string before reading it,
  as C allocates it first (one of 2^63 bytes or more is "not enough
  memory" at once: sizes are unsigned in C, negative in PHP);
  `Emitter::emitChunk` before each function (`EMIT_BYTES_*`: its PHP
  about 4 times over, plus the code emitted so far, which appending may
  copy), and before PHP compiles emitted code
  `ChunkLoader::PHP_COMPILE_BYTES_PER_BYTE` (20: measured 9.6 to 19.6,
  eval or include, opcache on or off) times its length (factoryForSource;
  `CacheDirectory::read` for an entry opcache does not hold yet): a chunk
  too big for the memory left is "not enough memory" (load returns fail),
  never PHP's fatal error. preg_match copies the match
  and every capture: a search whose copies might not fit runs on the port.
  Memory that grows a little at a time (tables, closures) is not checked
  and still ends in PHP's fatal error; frames are bounded as above.
  '__call' chains (ldo.c tryfuncTM): each hop makes the called object the
  first argument; "stack overflow" once the arguments reach the stack
  limit; the objects are prepended once, after the hops (lua5.4 moves its
  whole stack at each hop: a cycle takes it hours, us a fraction of a
  second), and with a budget each hop charges 1 + its arguments in steps
  (`Calls::checkStackForCallMetamethod`).
- **Upvalues.** An open `UpVal->v` is a PHP reference to `$R[$reg]`; one UpVal
  per register (`Upvalues::find`), closed by `Upvalues::close`/`closeUpvalues`
  (OP_CLOSE, OP_RETURN/OP_TAILCALL with k, error unwinding). Only OP_TBC
  and OP_TFORPREP (a generic for's closing value) create to-be-closed
  variables: a function with neither calls `closeUpvalues` directly for
  OP_CLOSE and OP_RETURN (`Op::closeUpvalues` compact). `v` is UpVal's
  only property. `LuaClosure` is abstract: like C's LClosure, a closure is
  sized to its upvalue count, `LuaClosure1/2/3` in properties `u0..u2`,
  `LuaClosureN` (none or more than 3) in the list `upvals`;
  `LuaClosure::create` picks the class, other code uses
  `getUpval/setUpval` and `count($closure->proto->upvalues)`.
- **Tables.** `LuaTable`: `arr` (integer keys), `hash` (string keys; PHP may
  store "10" as 10 there, it is still a string), `metatable`, `sizearray`
  (the border hint, C's alimit) and `extra`: a `LuaTableExtra`, null until
  needed, with `otherValues/otherKeys` (float, boolean, object keys) and
  `traversal`, the `TableTraversal` of the next() calls in progress; it is
  dropped when no such key and no traversal is left. Nil is never stored.
  Traversal order is each part's PHP order (arr, hash, other keys), and
  every traversal goes on from its own key, as C's from its key's slot:
  whatever other next() calls ran in between, also after its key was
  deleted (C keeps a dead key in its node; a PHP array cannot say where a
  deleted key was, so its place must be kept before). One traversal, the
  holder (`cursorKey`), owns the PHP internal pointers, kept past its key
  (PHP does not move a pointer off a deleted entry; key() looks past it):
  a pairs() step is O(1), also when it clears every field;
  `BaseLib::next` has that fast path inline. Traversals are told apart by
  where they call next from (the caller's savedpc, code, frameBytes, which
  grows with each frame of a thread, and thread: `LuaTable::isCaller`), so
  'for k2 in next, t, k' inside the loop over k, recursion and coroutines
  keep their places. next(t) while another traversal holds the pointer
  moves nothing (an emptiness check is O(1)) and remembers the first key
  given. A traversal at a part's first key takes the pointer (one at
  another key when the pointer is free: O(position)); the holder it
  replaces is suspended into a `TraversalLog` owned by its call site: its
  part's keys up to its key, O(position), extended in batches as it goes
  on (O(1) a step), with counts of the suspended traversals at each key
  (only the owner moves its count away, so a peek does not take a
  suspended traversal's place; a log goes at count 0). Calls from one
  place (iterator objects stepping through one function, tail calls)
  look alike: the counts keep a place for each. Logs are bounded (4 keys
  per table entry + 256, 16 logs, least recently used out) and not used
  once keys were added (count grown, last key changed). A traversal at a
  deleted key nothing remembers (past the bounds, or one started by
  next(t) beside another whose first key is deleted after all others
  ended) gets "invalid key to 'next'", as a key never in the table does.
  `tests/diff/next_traversal_stress.lua` checks next's contract over
  random interleavings. Only the traversal code may use reset()/next() on
  a part: by-reference array functions leave the property a PHP reference
  (+32 bytes), which `unreferencePart` undoes when a traversal leaves the
  part. Light userdata: `LightUserdata::pointingTo($obj)`.
- **String order.** `Vm::lessThan/lessEqual` (all string `<`/`<=`, so also
  table.sort and math.min/max) follow lvm.c l_strcmp: strcoll per segment
  between embedded '\0's (`Vm::compareStrings`), or strcmp while
  `Vm::$collationIsByteOrder` (LC_COLLATE "C" or "POSIX": a C program
  starts in "C"). os.setlocale sets it when it changes LC_COLLATE (or
  LC_ALL); a host that changes the locale with PHP's setlocale() is not
  seen, and without strcoll (disabled) strings compare as bytes.
- **Patterns.** `Lib\String\MatchState` ports lstrlib.c's matcher; the
  drivers (str_find_aux, gmatch_aux, str_gsub) stay C's and ask it only
  `nextCandidate`/`matchAt`, which run the pattern's exactly equivalent
  PCRE translation (`Lib\String\PatternRegex`: from a pattern's second use,
  or at once on a subject of 64+ bytes) when there is one. Patterns that
  could make match() raise, and any PCRE failure at run time, run the port.
  `tests/fuzz/patterns.php` checks lua5.4 vs translation vs port
  (`MatchState::$forcePort`); its fixed-seed corpus is the diff case
  `string_pattern_corpus.lua`.
- **Object sizes.** Every instance pays 16 bytes per declared property
  (40 + 16n, rounded up to Zend MM's bins: ..., 96, 112, 128, 160, 192,
  224, 256, ...), and a non-empty PHP array at least 216 bytes (list) or
  376 (string keys). A table is 128 bytes plus its arrays, a closure 96-128
  plus 56 per closed UpVal, a CallInfo 224; `tests/unit/ObjectMemoryTest.php`
  pins these budgets, so a new property on a hot class needs a reason (and
  may move it to a bigger bin). Keep what few objects use in side objects.
- **GC.** PHP frees memory (refcounting + its cycle collector); what Lua can
  observe of its collector is `Gc\Collector`, lgc.c's atomic phase run in one
  go: mark from C's roots (registry, `typeMetatables`, main thread, running
  thread, pending finalizers, plus `$G->libraryState`), clear weak tables
  (`__mode` read at every cycle; ephemerons; values cleared before
  finalizers are separated, keys after resurrection), then call the `__gc`
  of unreachable objects marked for finalization (`$G->finobj`, strongly
  held; `$G->tobefnz`), last marked first, via protectedRun + callNoYield with
  `CIST_FIN`, hooks off and `$G->gcstp |= GCSTPGC`; errors become "error in
  __gc" warnings. Weak tables are ordinary tables (strong PHP references)
  until a cycle removes entries, so emitted fast paths stay valid. Marking an
  object for finalization happens only in `Collector::setMetatable` (every
  lua_setmetatable site must use it: base/debug setmetatable, io handles,
  CLIBS). Collectable = LuaTable, LuaClosure, Coroutine, Userdata, and
  NativeFunction with `$upvalues` (a C closure); natives without upvalues
  are light C functions. A native's Lua values must be in its `$upvalues`:
  PHP `use` captures are invisible to the GC. A thread is traced through
  its CallInfo chain (`func`, `R`, `varargs`), the abandoned frames in
  `pendingCloses` from the one whose variable is being closed down (its
  registers up to that variable: C's top is right above it while its
  `__close` runs; the frames above are garbage), `tailCall*`, `errfunc`,
  `body`, `errorValue`. Liveness
  is C's stack top: a Lua frame below the top whose `savedpc` is at
  OP_CALL/OP_TAILCALL (OP_TFORCALL) owns registers below A (A+4), unless
  `CIST_HOOKED`; the frame that triggered an automatic
  cycle owns registers up to A of its allocation instruction; anything else
  keeps all its registers. So `savedpc` must be exact before every call.
  Every cycle is complete (atomic + all finalizers): `collectgarbage()` and
  "step" (true in incremental mode, false in generational, like C), and
  automatic cycles when emitted OP_NEWTABLE/OP_CONCAT/OP_CLOSURE push
  `$G->gcDebt` (bytes C would allocate) above 0 (`Collector::step($L, A+1)`,
  lvm.c checkGC). Pacing is lgc.c's setpause with a pause of at least 400%
  and 1 MB (a full trace costs about twice the allocation it pays for).
  "count" = C-size estimate of what the last cycle reached + max(charged
  bytes, PHP memory growth) since (an embedded state: charged bytes only,
  see Embedding conventions, Isolation). PHP's cycle collector is paused while
  tracing (every touched object becomes a root candidate). lua_close is
  `Collector::closeState` (bin/lua and lua2php scripts after the main chunk,
  `os.exit(x, true)`): reset the main thread to its base frame and close
  its tbc variables (lstate.c: close_state), then finalize
  everything still marked; objects marked while closing are not.
- **Hooks.** Per thread, on `Coroutine`: `hook` (C's lua_Hook: a closure
  `($L, $event, $line, $ci, $top)`, `$top` = C's L->top - base of the
  hooked frame; debug.sethook installs `DebugLib::hookf`, which calls
  registry `_HOOKKEY[thread]` and, as C pushes the hook table at `$top`,
  lists it in `DebugInfo::hookedFrames` for getlocal), `hookmask`, `basehookcount`,
  `hookcount`, `allowhook`, `oldpc`, and `trap` (true while a line or count
  hook is set). Change them only with `Hooks::setHook` (lua_sethook). Every
  emitted function binds `$trap = &$L->trap` in its prologue and emits
  `if ($trap) { Hooks::traceExec($L, $ci, pc); }` (luaG_traceexec; `$top`
  as a fourth argument before an instruction using the previous one's top) before
  exactly the instructions C's vmfetch fetches: not OP_VARARGPREP or
  OP_TFORLOOP, not an OP_EXTRAARG, the OP_MMBIN* only on the metamethod
  path, not the OP_JMP after a test (the test jumps to its target
  directly: donextjump), not OP_TFORCALL when entered from OP_TFORPREP
  (`goto T<pc>`, a label after its check); `TableConstructor::run` does
  the same for the instructions of a constructor run, and each `Op`
  method for its compact instruction. A loop of UNCHECKED_REGION_MINIMUM
  (12) or more instructions (a backward jump's range with one entry, no
  generic 'for', in a function of one segment) is emitted twice
  (FunctionEmitter::findUncheckedRegions): the copy after its `L<last>`
  (labels `U<pc>`) has no hook checks and is entered at the loop head
  with `if (!$trap) { goto U<head>; }`. `$trap` can become true only
  while Lua code runs (a call, a metamethod, a finalizer, a hook), so
  every path of the copy that can run any ends with
  `if ($trap) { goto L<next>; }` (afterLuaRun) — or, for a test's slow
  path, the checked jumps on its condition (checkedJumpOn) — and the
  copy then continues in the checked one at the next instruction, which
  runs its check as C's vmfetch would. With no hook set, a hot loop pays
  no check at all. Call hooks: `Hooks::hookCall`
  in the prologue (after OP_VARARGPREP for vararg functions) and in
  `Calls::callNative`; return hooks: `Hooks::retHook` in OP_RETURN* (before
  the results are collected), `Calls::callNative` (a native's results are
  appended to its `$ci->R`) and `Calls::tailCall` for a native callee.
  Hooks run on the live frame, as C's on its stack: a native gets its
  arguments from `$ci->R` after its call hook, and a return hook's results
  go on from the frame's slots, so debug.setlocal changes them
  (`Calls::callNonLua` inlines callNative: keep them alike).
  `Hooks::hook` (luaD_hook) marks the hooked CallInfo `CIST_HOOKED` (and
  `CIST_TRAN`, with the thread's `ftransfer`/`ntransfer`, for getinfo's
  'r': hooks do not nest, so they live on the Coroutine) and clears
  `allowhook` while the hook runs; `Calls::protectedRun` restores it.
- **PHP hygiene.** `Standalone::configurePhp()`: PHP warnings become
  exceptions (a crash, never output; the handler is
  `PhpErrors::throwAsException`), exception traces drop arguments,
  SIGPIPE gets its default disposition back (`Lib\Io\PipeSignal`, see
  Files and processes), and PHP's shutdown (also after a fatal error)
  flushes C's stdout. No PHP
  output buffer and no echo: C's stdout is `CFile::standardOutput()` (see
  Files and processes), which `print` (`StandardOutput`, then a flush, like
  lua_writeline), io.stdout and lua.c's own output (`Standalone::
  writeStdout/flushStdout`) share; PHP's own output would end the process
  (exit status 255) at a write error Lua must see. lua.c's messages go to
  stderr without flushing stdout, as l_message does. Embedded code gets none
  of this process-wide (see Embedding conventions). It leaves memory_limit alone: lua2php output and any PHP
  that embeds the runtime keep the limit PHP was started with. bin/lua,
  bin/lua2php (a build tool; in directory mode a file that fails is
  reported and the others are still transpiled) and test harnesses that
  stand in for bin/lua call `Standalone::raiseMemoryLimit()`: 4G, never
  lower, and at most PHP 8.5's max_memory_limit (ini_set above it would
  warn). Hosts may disable ini_set (and getmypid, set_time_limit, putenv,
  ...; see Files and processes): every ini_set is behind
  `function_exists('ini_set')`, and without it the host's values stay.
  lua2php scripts turn opcache's JIT on (`Standalone::switchJitOn`) only
  when the host left it off (opcache.jit=off) with a buffer, and as mode
  1054 (tracing without register allocation), never 'tracing' (1254): in
  PHP 8.4 and 8.5, a VM interrupt (an async signal handler, a sampling
  profiler such as Excimer) that hits a 1254 loop trace running ?? or ?:
  resumes the loop with stale register-held locals, so iterations repeat
  (php-src#24186; our own PHP avoids it, see Loops and the tracing JIT);
  1054 gave no wrong run in 44 and costs about 1% more CPU. Never profile
  under 1254.
  Lua cannot observe two of them: traces with arguments hold values until
  the pcall that caught the error returns (about 10% more peak memory at
  a stack overflow), and float literals are exact at any
  serialize_precision (`PhpLiteral` checks the round trip). The others
  change limits and speed only: bin/lua keeps the host's memory_limit,
  the JIT stays as configured, and fibers get the host's fiber.stack_size
  (PHP's default 2 MB of address space instead of 256 KB: under all.lua's
  1.5 GB ulimit -v, gc.lua's 1000 live coroutines then end in "not enough
  memory").
  `DisabledFunctionsTest` runs bin/lua, bin/lua2php and its output, the
  whole diff corpus (Lua2PhpTest too) and a host of the embedding API,
  under a typical shared host's list.
  PHP's function JIT with register allocation (opcache.jit T digit 0-3
  with R digit 1 or 2: function, 1205, 1105, 1215, 1225, ...) repeats loop
  iterations after a VM interrupt in any JIT-compiled loop (php-src#23983;
  no fix released, no per-function opt-out), and the setting is the
  host's, so every entry point refuses it before it compiles or runs any
  Lua code (decided 2026-10-10; never switch it with ini_set): the check
  is `Runtime\JitSafety::refusal()` (opcache_get_status when the host
  allows it, else the ini settings; never warns; kept per opcache.jit
  value, the one setting that changes at run time, so a call costs one
  ini_get). The first thing `LuaCommand::main`, `LuacCommand::main`,
  `Lua2PhpCommand::main` and `Standalone::runCompiledScript` do is
  `Standalone::refusesJit` (one line on stderr naming the value and the
  settings that work, exit status 1, nothing written); in the embedding
  API, `Embed\Internal\State`'s constructor (every sandbox) and
  `Environment::compile` throw `Embed\UnsafeJitSetting`, and the check
  also runs on every call into Lua (`State::enter`: Sandbox::load and
  run, a LuaFunction's call, a PHP function calling back), as a host may
  switch opcache.jit with ini_set after it made a sandbox (decided
  2026-10-11); it throws before the `try`, so the sandbox stays open and
  works again once a safe mode is back (a PHP function that lets the
  refusal through stops its run, as any PHP exception). A new entry
  point must call it too. `tests/unit/JitRefusalTest.php` runs every
  entry point under 14 JIT settings, with opcache_get_status disabled
  or restricted, and a host that switches the mode under a sandbox.
- **Loops and the tracing JIT.** php-src#24186 (PHP 8.4 and newer, tracing
  JIT with register allocation: opcache.jit=tracing or 1254, a host's
  setting we must run under): a loop trace that uses the instruction
  pointer the trace compiler keeps track of ends its loop with an
  interrupt check that jumps to a stub writing nothing back, so after a VM
  interrupt (an async signal handler, a sampling profiler,
  max_execution_time) the loop runs again from the values its frame held
  before the trace kept them in CPU registers: iterations repeat, with no
  error (table.unpack, table.concat and string.format printed wrong
  results). The trace uses that pointer at each call of an opcode's VM
  handler (an opcode the trace compiler has no inline code for), and where
  an operation stores the opline right after one or as the loop's first.
  So inside a loop, and in every function a loop calls (the trace inlines
  them), our PHP (src/, the emitted code, the Op methods) avoids these,
  each with what we write instead: `??` and `??=` (`isset($a[$k]) ?
  $a[$k] : null`; for a string offset, a test of the length), `?:` (`$x ?
  $x : $y`), `?->` (`if ($x !== null)`), `@` (`set_error_handler` around
  the loop), `instanceof` (`\is_a($x, C::class)`), `~` (`^ -1`), `unset`
  of an array element (when it is usually absent, `isset` first keeps the
  call off the common path), a cast to another type, and
  `.`, `.=` or `"$x"` with an integer (unqualified `strval($x)`, a function
  call: `(string)`, `\strval` and `\sprintf('%d', ...)` compile to a cast),
  a class constant of another class (`OpCodes::OP_CALL`: a local before
  the loop, or in a method the loop calls a static property such as
  `self::$opCall = OpCodes::OP_CALL`; a constant of the class itself is
  compiled in), an array literal holding variables (return a second value
  through a reference parameter, as C does), `new`. And a loop does not
  start with an operation that may store the opline: no `foreach` whose
  values may be arrays or objects (`for` over the list), no `while` whose
  condition starts with a static method call. A loop that must run such
  operations (TableConstructor::run creates tables, FunctionEmitter::emit's
  loop runs the whole emitter) keeps its counters in memory: a reference to
  each (`$pcInMemory = &$pc;`) keeps the JIT from holding it in a register,
  so the stub's restart goes on from the right values. The detector flags
  no loop of ours over the inventory's inputs, but a token scan on
  2026-10-11 still found about 110 loops in src/ with such an operation
  in their own body (compiling, the collector, unwinding, rare paths)
  whose traces kept no value in a register there or were never compiled:
  change one when you touch it, and any loop the detector flags.
  `tests/jit/detector.php` decides from the JIT's own report
  (opcache.jit_debug) which loop traces can lose values ("flagged");
  `tests/unit/TracingJitImmunityTest.php` asserts that the inputs (i),
  (iii) and (v) of `tests/jit/inventory_inputs.php` (lua2php output of the
  diff corpus, plain and compact, and the speed workloads) compile no
  flagged loop trace under 1254 and at least 80% as many loop traces as
  phase 1's inventory examined
  (`test_no_loop_trace_of_our_code_can_lose_values_at_an_interrupt`), and
  runs strings, string_library and table_library as lua2php output under
  the interrupt harness (`tests/jit/interrupts_after_compiling.php`: 3,000
  SIGUSR1 a second), 10 times each, against lua5.4
  (`test_the_workloads_that_went_wrong_stay_right_under_interrupts`).
  `php tests/jit/inventory.php 1254` runs the detector over the whole input
  set, bin/lua and the official files too (about 10 minutes); its 1154 run
  (local register allocation) flagged nothing even before the fix.
  `tests/jit/tracing-jit-inventory.md` is the record of the 27 places it
  flagged on 2026-10-11, before our code was fixed.
- **Reference build.** lua5.4 is built with LUA_COMPAT_5_3: `__le` falls back
  to `not __lt(b, a)`, and math has pow, ldexp, frexp, cosh, sinh, tanh, log10,
  atan2. Its package.path/cpath defaults include the distribution's `/usr/`
  directories (PackageLib uses the same); it has dlopen, we do not
  (package.loadlib returns fail, DLMSG, "absent").
- **Fast paths.** Hot library functions (and `Calls::callNonLua`/`call`,
  which inline `callNative`/`callk`) start with a short fast path for the
  common case (e.g. a table without a metatable) that falls through to the
  unchanged C port. It must compute exactly what the port would (results,
  errors, metamethods, hooks), so a change to the port updates it too; each
  has cases for both paths in `tests/diff/fastpath_*.lua`.
- **Files and processes.** A file handle is a `Userdata` (metatable
  registry["FILE*"]) whose payload is `Lib\Io\LuaStream` (luaL_Stream) holding
  a `Lib\Io\CFile`, the emulated C `FILE *` with glibc's buffering rules
  (Lua can observe them through a second handle): `_IO_file_doallocate`'s
  st_blksize buffer, line buffering on a terminal (isatty), setvbuf, a
  read flushing what was written first, and a line buffered stdout flushed
  when a line buffered stdin refills its buffer (`_IO_file_xsgetn`: getc,
  lines and counts smaller than the buffer; a bigger count reads directly,
  "a" refills from its second piece on; PHP's stream buffer stands for
  glibc's). fflush(NULL) (`CFile::flushAll`: exit, os.exit, io.popen)
  walks glibc's list of streams: the newest first, stdout last. The
  process has one C stdout, `CFile::standardOutput()` on descriptor 1
  (PHP's STDOUT stream), with glibc's own idea of its position (`_offset`:
  unknown until a seek, then moved by its writes, not by other writers of
  the descriptor; seeks use the descriptor itself); a state's io.stdout
  acts on it while the state's output is `StandardOutput`, else it writes
  to the state's sink like `print` (see Embedding conventions). C's stdin
  is one CFile too (`CFile::standardInput()`): every state's io.stdin, and
  debug.debug's fgets (`getLine`). PHP's fopen cannot open
  /proc/self/fd/N links to pipes or sockets (/dev/stdout on a pipe), which
  Linux opens itself: `CFile::open` opens them as php://fd/N. A failed
  write or flush is the Lua error C reports (ENOSPC, EPIPE, EBADF...;
  `StandardOutputTest`); print ignores it, and the exit status stays the
  script's. SIGPIPE (decided 2026-10-02): bin/lua and lua2php scripts are C
  programs run from a shell, whose SIGPIPE has its default disposition
  (Python, Node, Go and shell parents all give children that), so a write
  to a pipe or FIFO nobody reads kills them at the write: `... | head -1`
  ends with status 141, nothing more is written, no __close or __gc runs.
  PHP's CLI ignores SIGPIPE (php_cli.c), so configurePhp restores the
  default with `pcntl_signal(SIGPIPE, SIG_DFL)`; without pcntl,
  `PipeSignal` emulates it: a write failing with EPIPE (CFile::writeOut,
  `StandardError::writeBytes` for stderr) ends the process with exit status
  141 and PHP's shutdown writes nothing. Embedded states never do this (the
  host's process keeps its disposition; EPIPE is an error Lua sees). Tests
  compare with lua5.4 started as from a shell (`env --default-signal=PIPE`):
  tests/run.php runs lua5.4 with SIGPIPE ignored (inherited from PHP), so a
  diff case that wrote to a closed pipe would differ (none does).
  Lua-supplied file names are plain paths, never PHP
  stream wrappers: every PHP file function on one goes through
  `CFile::plainPath` ("php://memory" is a file in a directory "php:";
  fopen, package.searchpath's readable, loadfile and lua2php's precompiled
  files, bin/luac's file arguments; `file_names_are_plain_paths.lua`). C's `errno` is `Lib\Io\Errno`
  (recovered from PHP's warning text); `Errno::fileResult/execResult` are
  luaL_fileresult/luaL_execresult. PHP's fopen and file_get_contents
  resolve paths themselves first and report any failure there as ENOENT
  (EINVAL from 4095 bytes): `CFile::open` then asks `Errno::lookupError`,
  which walks the components as the kernel does (ENOTDIR, ELOOP,
  ENAMETOOLONG, EACCES, EISDIR); loadfile opens with `CFile` too. PHP's
  rename copies across file systems: os.rename checks EXDEV first.
  Hosts may disable posix, pcntl and the
  process functions (disable_functions: a disabled function is undefined,
  a fatal no pcall sees), so use them only behind `function_exists` with a
  fallback: `Errno::strerror` falls back to glibc's texts,
  `CFile::waitForProcess` polls proc_get_status, and without proc_open,
  proc_get_status or proc_close (`CFile::canStartProcesses`) popen and
  system fail with ENOSYS: `os.execute(cmd)` returns fail, "Function not
  implemented", 38, `io.popen(cmd)` fail, "cmd: Function not implemented",
  38 (after their argument checks), `os.execute()` false
  (`tests/unit/DisabledFunctionsTest.php`: lua5.4 with its
  `PROCESSES_FAIL_INIT` is the reference there).
  Never pass PHP's STDIN/STDOUT/STDERR to
  `proc_open`: PHP seeks the descriptor to that stream's cached position;
  leave the descriptors out so the child inherits them. Dates/times:
  `Lib\Os\CTime` (glibc gmtime/localtime/mktime: mktime.c's
  `__mktime_internal`, with its offset guess kept between calls; strftime:
  strftime_l.c, done directly for loslib.c's conversions in the "C" locale,
  else by `Lib\Os\Strftime`, which only other locales load), never PHP's
  default zone or zone database. The zone is
  `Lib\Os\TimeZone`, glibc's tzset.c and tzfile.c with their process-wide
  state: TZ (or /etc/localtime) as a TZif file (":" ignored, absolute or
  under TZDIR or /usr/share/zoneinfo; leap seconds, the POSIX TZ string
  after the last transition), else a POSIX TZ string (no rule: posixrules'
  transitions, read again at every mktime), else UTC under the name given;
  C's int arithmetic wraps where glibc's does (`TimeZoneTest` compares
  lua5.4 over many TZ values). Until 2026-10-02 every zone came from PHP's
  bundled database (DateTimeZone), which works where a host's open_basedir
  forbids PHP to read /usr/share/zoneinfo; now, when open_basedir forbids
  reading the zone file and PHP's database has a zone of exactly that name,
  that zone stands in (`TimeZone::useBundledZone`: PHP's transitions, no
  leap seconds); a file it does not name stays missing, as for glibc, and
  TZ unset is UTC there (readlink of /etc/localtime is forbidden too, as it
  was before). Speed (within 5% of PHP's DateTimeZone before, or faster):
  `TimeZone::convert` keeps the last local zone with the times it holds for
  (between two transitions, or a POSIX rule's part of a year; none with
  leap seconds), localtime breaks a time down once, os.date asks for the
  LC_TIME locale once per call, strftime formats loslib.c's conversions
  in the "C" locale directly (`os_date_conversions.lua` pins them all
  against lua5.4), and os.time/os.date("*t") read and write a table
  without a metatable directly (`fastpath_os_time_fields.lua`). strftime
  takes names and the %c %x %X %r
  formats of the LC_TIME locale (os.setlocale) from nl_langinfo ("C" and
  "POSIX", or no nl_langinfo: the C locale's); PHP's nl_langinfo gives
  only the first era and no alternative digits (a list in C, cut at the
  first NUL), so %E conversions outside the first era and %O conversions
  of locales with alternative digits (ja_JP, fa_IR) differ from lua5.4
  (`OsDateLocaleTest` builds locales with localedef under LOCPATH).

## Embedding conventions

The runtime half of embedding Lua in PHP applications: the internal
contract the public `LuaPhp\Embed` layer (src/Embed/) builds on. Only
`LuaPhp\Embed` is public: every other class is marked `@internal`.
Nothing here costs bin/lua or lua2php output anything:
their emitted code is byte-identical to code compiled before step
counting existed, and a state without a budget charges nothing.

- **Output.** `OutputSink::write($bytes)`; `GlobalState::$output`
  (stdout) and `$errorOutput` (stderr) default to `StandardOutput` (C's
  stdout, `CFile::standardOutput()`, flushed like lua_writeline) and
  `StandardError`, which is the command line's behavior. Every write the runtime does for Lua
  (print, io.write, io.stdout, io.stderr, warn, debug.debug) goes through
  `$G->writeOutput()` / `writeErrorOutput()`, which charge the budget
  first (over the limit nothing is written), and flushes through
  `$G->flushOutput()`, which flushes only `StandardOutput` (command line
  only: it writes the process's descriptor 1). io.stdout/io.stderr are CFiles
  bound to their state; on another sink their seek fails with ESPIPE,
  while io.stderr on `StandardError` still writes its own stream (write
  errors are seen). Never echo or write to STDOUT/STDERR for Lua directly.
- **PHP errors.** `PhpErrors::call($body)` installs the warning-to-
  exception handler, runs `$body` and restores the previous handler in
  `finally`; calls nest (a host function may run another state).
  `configurePhp` installs the same handler for the whole process (CLI).
- **Libraries.** `StandardLibraries::openSelected($L, $names, $sandboxed,
  $allowLoad)`: `base package string table math utf8 coroutine io os
  debug`, or `lib.func` for single functions (`base.print`); a library of
  which only some functions are named keeps just those (same module
  table, so the string metatable's `__index` follows); an unknown name is
  an `\InvalidArgumentException`. `$sandboxed`: no dofile/loadfile/
  string.dump; load only with `$allowLoad`, then with 'b' taken out of
  every mode (binary chunks get mode "t"'s error) and compiled with step
  counting; collectgarbage accepts only collect/count/step
  (luaL_checkoption's "invalid option"); package is just package.loaded
  and `require` comes from `PackageLib::openSandboxRequire($L,
  $searchers)`: ll_require (package.loaded first, the loader called with
  the name and its data, the result stored, both returned) with PHP
  searchers `fn (Coroutine $L, string $name): array` returning `[loader,
  data]` or `[null, message|null]`; not found is findloader's "module 'x'
  not found:" plus "\n\t" and each message.
- **Budget.** `GlobalState::setBudget(?Budget)` sets the limits (null:
  none) and starts a run; `Budget::start()` starts the next one. Limits:
  steps, memoryBytes, seconds, outputBytes, coroutines (alive at once),
  callDepth, sourceBytes (a chunk `ChunkLoader::load` may compile: a
  bigger one is LimitReached('source') before anything else, a reader's as
  soon as what it gave passes it; compiling's memory is checked as it
  goes, see Limits). Reaching one throws `LimitReached` (`->limit`), an
  `\Exception` that is neither LuaError nor `\Error`: no pcall, message
  handler, resume, `__close` or `__gc` sees it, so no Lua code runs after
  it and the state is unusable (abandon it). The step counter is the
  state's (`GlobalState::$stepsLeft`, untyped: a typed reference costs a
  check per decrement), bound by reference in emitted frames;
  `Budget::$stepsLeft` refers to it, so budgets may be replaced between
  runs even with coroutines suspended. Below 0 it calls
  `Budget::stepsUsedUp` -> `refill()`: the steps limit, then time and
  memory, every `STEPS_PER_CHECK` (10000) steps. Memory: growth of
  memory_get_usage() since start (a lower mark when garbage from before is
  freed) over memoryBytes, or the host's memory_limit less MemoryLimit's
  reserve, after gc_collect_cycles (C's emergency collection: no
  finalizers) -> 'memory'. `MemoryLimit::reserve/grow/fits($bytes,
  $budget)`: over memoryBytes LimitReached('memory'), over the host's room
  Lua's "not enough memory" as before, unless `Budget::$hostMemoryIsLimit`
  (embedded sandboxes): then the host's room is part of the memory limit,
  checked as it is (so it follows the room as garbage is freed), and over
  it is LimitReached('memory') too. `checkLimits()` checks time and
  memory now (host code, after a host function returns). `chargeSteps`,
  `chargeOutput`, `usage()` (steps, peakMemoryBytes, milliseconds,
  outputBytes).
- **Step counting.** `Emitter::emitChunk($proto, true)`: the prologue
  charges the function's instruction count (`$steps =
  &$L->globalState->stepsLeft` in a function with loops) and every backward
  jump (`FunctionEmitter::jump`, target <= source pc) charges the loop's
  size before it jumps, so steps >= instructions run (an OP_MMBIN* counts);
  an OP_VARARG that copies all varargs charges a step per value (a loop of
  `{...}` copies a million values per instruction; `Op::vararg` does the
  same when `$G->countSteps`), and a '__call' hop 1 + its arguments.
  `GlobalState::$countSteps` makes load() compile so, and LoadCache keys
  include the flag. Libraries charge in proportion to their work, a step
  per element or per `Budget::BYTES_PER_STEP` (64) bytes: rep, upper,
  lower, reverse, byte, char, format (and the dumps of '%p' of a long
  string), pack, find/match/gmatch/gsub
  (MatchState: bytes scanned per search, the port's match() calls in
  batches of 256), table concat, insert/remove shifts, move, unpack, sort
  (per partition), utf8 char/codepoint/len/offset/codes, load (a step per
  byte compiled; a reader's chunk as it is read, also when it then hits
  the cache). Loops over ranges an argument sets charge as they go;
  others charge what they are about to do, after `MemoryLimit::reserve`
  (so a huge result is 'memory', not 'steps'). A fast path charges what
  its port charges. Every charge is `$L->globalState->budget?->...`: with
  no budget it evaluates nothing.
- **Call depth.** `CallInfo::$depth`: Lua levels, set by step-counting
  code's prologue from the frame below (through native frames, which
  store none: `Calls::luaDepth`, so native calls cost nothing more); over
  `GlobalState::$callDepthLimit`
  -> `Calls::callDepthOverflow`: Lua's "stack overflow", with
  `Calls::ERROR_EXTRA_DEPTH` (50) more levels while it is handled, then
  "error in error handling"; `shrinkStack` restores. Each thread counts
  its own, as each C thread has its own stack.
- **Coroutines.** `Coroutine::newThread` -> `Budget::countCoroutine` (a
  WeakMap). At the limit, dead ones (`Coroutine::isDead`) stop counting,
  then gc_collect_cycles frees unreachable suspended ones, then
  LimitReached('coroutines'). One kept only by a weak table counts until a
  Lua collection clears it. A first resume reserves `Budget::FIBER_BYTES`
  (`reserveMemory`): a fiber's VM stack comes from PHP's heap, and a loop
  can start a thousand fibers between two step checks. Embedded states set
  `GlobalState::$setsFiberStackSize` false: no ini_set of fiber.stack_size
  (it would change the host's own later fibers), so their fibers get the
  host's size (PHP's default 2 MB of address space each: the coroutines
  limit bounds it, 2 GB at 1000; touched pages are what cost memory).
- **Isolation.** Per state: globals, registry, type metatables,
  package.loaded, math's random state, the '%p' long-string table
  (`GlobalState::$addressedLongStrings`), warnings, budget. Process-wide
  and harmless: code caches (LoadCache, ChunkLoader's factories, pattern
  tables and translations: pure functions of their keys), LightUserdata's
  WeakMap and `DebugInfo::$hookedFrames` (keyed by one state's objects),
  `Errno::$errno` (set and read within one library call, with no Lua or
  host code in between), Teardown's queue, the time zone (TZ is the
  process's; `Lib\Os\TimeZone` and mktime's offset guess keep glibc's
  process-wide state). Process-wide and observable, so
  never in a sandbox profile: os.setlocale (the PHP process's locale, and
  with it string order: `Vm::$collationIsByteOrder`),
  CFile's open files (io.popen and os.execute flush every state's files,
  as C's fflush(NULL) does), os.exit (ends PHP), io.read/debug.debug
  (the process's stdin). collectgarbage("count") of an embedded state is
  its own charged bytes only (`GlobalState::$countsOnlyChargedBytes`:
  never the process's memory growth, which other states move); bin/lua
  and lua2php add PHP's memory growth (gc.lua needs the big strings it
  counts). The collector's pacing is each state's own.
- **Closing.** `Collector::abandonState($G)`: pending finalizers are
  dropped, never called, and the collector stops, so no `__gc` and no
  `__close` runs any more; PHP frees the rest (destructors never run Lua).
  A sandbox that is thrown away skips its pending finalizers.
- **Chunk cache.** `GlobalState::$chunkCache` (a `ChunkCache`: find/store
  with LoadCache's keys and entries): load() of an embedded state compiles
  into its Environment's cache instead of LoadCache.

**The public layer.** `LuaPhp\Embed` (src/Embed/) is the only public
API: hosts run Lua inside a PHP app (a web request) through it. Nothing
public takes or returns a Coroutine, CallInfo, Proto or runtime LuaTable:
handles wrap them. Its internals are `LuaPhp\Embed\Internal`.

- **Shape.** `Environment` (once per process: loader, compile cache,
  libraries, host modules and globals, limits, output) -> `Sandbox` (one
  Lua state per run; `Embed\Internal\State` is its inside) -> `Result`
  (values, output, usage). `Environment::run` makes a sandbox and drops
  it (handles in the result keep it alive); `Lua::run` uses a default
  SAFE environment. Never call `Standalone::configurePhp` on this path:
  no ini_set, no handler or output buffer left behind, no echo.
- **The state** (`State::__construct`): `openSelected` with the
  Environment's `Libraries` (sandboxed unless exactly `Libraries::ALL`),
  `openSandboxRequire` with the three searchers (with ALL: in front of
  package.searchers), `$G->chunkCache` = the Environment's CompileCache,
  `$G->countSteps` when `Limits::needStepCounting()` (steps, memory,
  seconds or call depth set), `setsFiberStackSize` false, one `Sink` as
  both `$output` and `$errorOutput` (collects per run for
  `Result::$output`, or the host's callable/`StdoutSink`, host code like
  a PHP function: `State::checkAfterHostCode` after each write, which
  checks only during a run of the state, not when another state's
  io.popen or a destructor flushes its buffered io.stderr), and a Budget
  from the Limits with `hostMemoryIsLimit` (none for `Limits::none()`:
  nothing charged, Usage then has only time and output).
- **Calls into Lua** (`State::compileSource`, `callLua`) go through
  `State::enter`: `PhpErrors::call`, a `LimitReached` becomes
  `LimitExceeded` with the Budget's usage, and any other exception but a
  LuaError stops the sandbox (`stop`: closed, `abandonState`, `stoppedBy`)
  and goes on unchanged: protectedRun and resume catch only LuaError and
  PHP's C-stack \Error, and no emitted `finally` runs Lua, so no pcall,
  handler or `__close` sees it. Later uses are `SandboxClosed` (previous:
  that exception). A call from PHP outside any run (`State::$depth` 0)
  starts one (`startRun`: `Budget::start`, the output collected so far
  dropped) before it converts its arguments; calls from a PHP function the
  run called are part of it. Lua
  code runs on `State::$runningThread` (the thread whose Lua code called
  the PHP function running now) or the main thread, as protected calls
  whose message handler takes the traceback. A Lua error is a
  `RuntimeError` (luaMessage: string, number or tostring; value converted;
  traceback: luaL_traceback at level 1); the sandbox stays usable. A
  LimitReached anywhere in State (enter, chargeSteps,
  reserveConversionMemory) becomes LimitExceeded at once and stops the
  sandbox (`State::limitExceeded`), so a PHP function that catches it
  cannot let Lua code go on. Reading or writing values from PHP outside
  Lua code (getGlobal, setGlobal, a handle's toArray or set:
  `State::enterFromPhp`) starts a run outside any, whose limits apply.
- **PHP functions** (`Internal\HostFunction`): a Closure's signature is
  read once (a WeakMap by Closure) into luaL_check* rules per parameter;
  `bind` makes the NativeFunction of one sandbox. Its body runs through
  `State::callHost`: whatever it returned or threw, first
  `State::checkAfterHostCode` (a closed sandbox rethrows what stopped it,
  so catching that hides nothing; then `Budget::checkLimits`: its time
  counts), and only then ScriptError ->
  Lua error (message with luaL_error's position, or a value) and a
  RuntimeError of the same sandbox the PHP code let through -> the Lua
  error it was (as lua_call); another sandbox's stops the run. Each call charges a step
  (`State::chargeSteps`, as `RunContext::chargeSteps`). A result or a
  ScriptError value that cannot convert is a host bug: it stops the run.
- **Values** (`Internal\Convert`): see its class comment for the rules
  (lists 1..n <-> 0-based, raw contents, depth 100, paths). Paths use the
  keys of the side the value comes from, made only for an error from the
  keys on the way (long keys shortened). Conversions count what they
  build (a table repeated in a value becomes copies: 20 levels of
  `t = {t, t}` are a million arrays) in `State::$conversionBytes`, which
  goes on across values and calls (a list of 8000 results of 16 KB each
  adds up as one value does), and every 64 KB of it asks
  `State::reserveConversionMemory` (the budget, or without one the
  host's room): over it LimitExceeded('memory'). A handle registers its value in
  a table under `$G->libraryState['embed.handles']` (luaL_ref), so Lua's
  collector sees what PHP holds; its destructor only unsets that entry.
- **Chunks** (`Internal\CompileCache`, the states' `ChunkCache`): scripts,
  modules and load() all go through `ChunkLoader::load` (mode "t"), keyed
  by LoadCache::key (chunk name, bytes, whether the code counts steps); in
  memory per Environment, on disk in a `Runtime\CacheDirectory` (the same
  trust rules, entry format and bounds as load()'s LoadCache). Chunk names
  are "@" + the loader's name; `Sandbox::load` defaults to the code
  itself (luaL_loadstring). Compiling counts against the run's memory
  (a cold cache pays; `Environment::compile` runs without limits). `require`: host modules, then loader
  "a/b.lua", then "a/b/init.lua" (searchers returning [loader, data] or
  [null, message]; loader errors as loadlib.c's checkload).
- **Loaders** never read outside their root: `FilesystemLoader` refuses
  absolute names and ".." components and checks the real path is under
  the root's real path.
- **Closing** runs no Lua code: pending `__gc` finalizers are skipped
  (close(), a stopped run, a dropped sandbox).
- Tests: `tests/unit/EmbedRuntimeTest.php` (the runtime contract) and
  the other `tests/unit/Embed*Test.php` (the public layer), compared with
  lua5.4 where Lua decides (argument errors, tracebacks, syntax errors,
  what a table holds); `EmbedHostSafetyTest` runs each way around the
  limits a review found in its own 128M process, which must end in
  LimitExceeded, a result or a normal exception (never a fatal or a hang).

## Testing

Tests are the project's memory. Behavior is checked against the reference
interpreter `lua5.4` (5.4.9) and compiler `luac5.4`, both installed.

    php tests/run.php            our own tests (unit + differential); must stay green (composer test)
    composer lint                PSR-12 check of src/, bin/, tests/, bench/ (after composer install)
    tests/bytecode.sh [file...]  diff `bin/luac -l -l -p` vs `luac5.4 -l -l -p` for official test files
    tests/official.sh [name...]  run official test files one at a time through bin/lua with
                                 -e"_U=true _soft=true _port=true _nomsg=true"; PASS/FAIL per file
    php bench/run.php <label>    benchmarks vs lua5.4 (not a test; results in bench/results/<label>.md,
                                 see bench/README.md): speed of saved lua2php output with a warm
                                 opcache (transpile time apart), memory and virtual memory under bin/lua
    bench/score/score.sh         the speed score (execute-score's scorer): CPU time of transpiling and of
                                 lua2php output vs the commit that added it, under a shared-host profile;
                                 lower is better, 100 = that commit (see bench/README.md)

- Differential cases live in `tests/diff/*.lua`: each runs under `lua5.4` and
  under `bin/lua` from `tests/diff`; exit status, stdout and stderr must match
  (0x... addresses and the program name normalized). `tests/unit/Lua2PhpTest.php`
  also runs every case through `bin/lua2php`. Add one for every bug.
- C stack: `tests/run.php` runs itself (so every process it starts) with an
  8 MB stack, the usual default, running itself again under `ulimit -s 8192`
  when the limit differs. A bigger one (this machine's shells have 16 MB)
  hides PHP crashing in its own recursion: opcache's optimizer recurses
  about once per basic block, and segfaulted (exit 139) on huge generated
  functions at 8 MB. Run other PHP by hand with `ulimit -s 8192` too.
- opcache: this machine's php.ini enables it for the CLI, but it skips files
  younger than `opcache.file_update_protection` (2 s), which is every file
  a test has just generated or cached. Tests that run generated or cached
  files pass `OPCACHE_ON_NEW_FILES` (tests/harness.php: `-d opcache.enable_cli=1
  -d opcache.file_update_protection=0`) so opcache really compiles and
  optimizes them (e.g. Lua2PhpTest's cold and warm passes, and its data
  modules at 8 MB and 1 MB stacks).
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
- [x] Phase 2 — libraries in parallel lanes: string/utf8, math/table,
      io/os/package, coroutine (Fiber). Gate: strings, pm, tpack, utf8, math,
      sort, nextvar, files, attrib, constructs, bitwise, goto, closure,
      literals, events.
- [x] Phase 3 — errors, stack overflow, calls/string.dump, to-be-closed
      variables. Gate: errors, cstack, calls, locals, coroutine.
- [x] Phase 4 — debug library + hooks, GC semantics. Gate: db, gc, gengc,
      big, verybig.
- [x] Phase 5 — `all.lua` prints `final OK !!!` under `-e"_U=true"`.
