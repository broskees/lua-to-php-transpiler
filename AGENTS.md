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
    bin/lua2php        the transpiler deliverable, ahead of time: in.lua -> out.php, or a project
                       directory -> one .php per .lua (require/dofile load those; see Chunks)
    src/autoload.php   PSR-4 autoloader: namespace LuaPhp\ -> src/
    src/Compiler/      Proto, OpCodes, Lexer, Parser, CodeGen, Dump, Undump, Listing, Compiler
    src/Emitter/       Proto -> PHP source
    src/Runtime/       values, tables, calls, errors, coroutines, metamethods, debug info
    src/Lib/           standard libraries (one file per C lib)
    tests/             our own tests + harness scripts (see Testing)
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
  closeThread (as C). While a pcall in a coroutine runs the `__close`
  methods of frames an error abandoned (they may yield), those frames are
  listed in `pendingCloses`; closeThread splices them back where C's stack
  has them. A resume sets `$co->nCcalls` to the resumer's + 1
  (nested resumes hit "C stack overflow" at LUAI_MAXCCALLS as in C); the PHP
  frames of a suspended coroutine undo their own increments when they
  return, so a resume shifts nCcalls by the change of `nCcallsBase` and saved
  counts (protectedRun, closeProtected) are relative to it. Each fiber gets a
  C stack of `Coroutine::FIBER_STACK_BYTES` = 256 KB of address space (only
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
  coroutines at most. Freeing: a finished coroutine's fiber is freed at
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
  NativeFunction, Userdata, Coroutine (which also unlinks its CallInfo
  chain, as `Calls::unlinkAbandonedFrames` does for frames an error
  abandons). A LuaClosure leads only to UpVals; a LuaTable hands its
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
  cached by PHP-source hash (PHP never frees eval'd code).
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
  before it passes 20000 entries or 256 MB.
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
  literals (`$table<i>`, long strings from the Proto) and the final value
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
  calls, as C's precover/finishpcallk). `$L->errfunc` is the current message
  handler (load's parser uses it).
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
  all, else `Vm::concat`), table.concat, io reads ('a', lines, counts),
  os.date, package.searchpath, load's reader. preg_match copies the match
  and every capture: a search whose copies might not fit runs on the port.
  Memory that grows a little at a time (tables, closures) is not checked
  and still ends in PHP's fatal error; frames are bounded as above.
- **Upvalues.** An open `UpVal->v` is a PHP reference to `$R[$reg]`; one UpVal
  per register (`Upvalues::find`), closed by `Upvalues::close`/`closeUpvalues`
  (OP_CLOSE, OP_RETURN/OP_TAILCALL with k, error unwinding). `v` is UpVal's
  only property. `LuaClosure` is abstract: like C's LClosure, a closure is
  sized to its upvalue count, `LuaClosure1/2/3` in properties `u0..u2`,
  `LuaClosureN` (none or more than 3) in the list `upvals`;
  `LuaClosure::create` picks the class, other code uses
  `getUpval/setUpval` and `count($closure->proto->upvalues)`.
- **Tables.** `LuaTable`: `arr` (integer keys), `hash` (string keys; PHP may
  store "10" as 10 there, it is still a string), `metatable`, `sizearray`
  (the border hint, C's alimit) and `extra`: a `LuaTableExtra`, null until
  needed, with `otherValues/otherKeys` (float, boolean, object keys) and
  the cursor `next()` keeps so traversal is O(1) and survives clearing
  fields; it is dropped when a traversal ends with no such keys. Nil is
  never stored. Only `LuaTable::next` may use reset()/next() on a part:
  by-reference array functions leave the property a PHP reference (+32
  bytes), which `endTraversal` undoes. Light userdata:
  `LightUserdata::pointingTo($obj)`.
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
  `pendingCloses`, `tailCall*`, `errfunc`, `body`, `errorValue`. Liveness
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
  bytes, PHP memory growth) since. PHP's cycle collector is paused while
  tracing (every touched object becomes a root candidate). lua_close is
  `Collector::closeState` (bin/lua and lua2php scripts after the main chunk,
  `os.exit(x, true)`): close the main thread's tbc variables, then finalize
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
  the same for the instructions of a constructor run. Call hooks: `Hooks::hookCall`
  in the prologue (after OP_VARARGPREP for vararg functions) and in
  `Calls::callNative`; return hooks: `Hooks::retHook` in OP_RETURN* (before
  the results are collected), `Calls::callNative` (a native's results are
  appended to its `$ci->R`) and `Calls::tailCall` for a native callee.
  `Hooks::hook` (luaD_hook) marks the hooked CallInfo `CIST_HOOKED` (and
  `CIST_TRAN`, with the thread's `ftransfer`/`ntransfer`, for getinfo's
  'r': hooks do not nest, so they live on the Coroutine) and clears
  `allowhook` while the hook runs; `Calls::protectedRun` restores it.
- **PHP hygiene.** `Standalone::configurePhp()`: PHP warnings become
  exceptions (a crash, never output), exception traces drop arguments, stdout
  is buffered (PHP's output buffer is C's stdout buffer: `print` echoes and
  then flushes, like lua_writeline; `Standalone::flushStdout()` before
  stderr/exit). It leaves memory_limit alone: lua2php output and any PHP
  that embeds the runtime keep the limit PHP was started with. bin/lua,
  bin/lua2php (a build tool; in directory mode a file that fails is
  reported and the others are still transpiled) and test harnesses that
  stand in for bin/lua call `Standalone::raiseMemoryLimit()`: 4G, never
  lower, and at most PHP 8.5's max_memory_limit (ini_set above it would
  warn).
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
  (Lua can observe them through a second handle). Standard output writes go
  through PHP's output buffer like `print`. C's `errno` is `Lib\Io\Errno`
  (recovered from PHP's warning text); `Errno::fileResult/execResult` are
  luaL_fileresult/luaL_execresult. PHP's fopen and file_get_contents
  resolve paths themselves first and report any failure there as ENOENT
  (EINVAL from 4095 bytes): `CFile::open` then asks `Errno::lookupError`,
  which walks the components as the kernel does (ENOTDIR, ELOOP,
  ENAMETOOLONG, EACCES, EISDIR); loadfile opens with `CFile` too. PHP's
  rename copies across file systems: os.rename checks EXDEV first.
  Hosts may disable posix and pcntl
  (disable_functions: a disabled function is undefined, a fatal no pcall
  sees), so use them only behind `function_exists` with a fallback:
  `Errno::strerror` falls back to glibc's texts, `CFile::waitForProcess`
  polls proc_get_status (`tests/unit/DisabledFunctionsTest.php`).
  Never pass PHP's STDIN/STDOUT/STDERR to
  `proc_open`: PHP seeks the descriptor to that stream's cached position;
  leave the descriptors out so the child inherits them. Dates/times:
  `Lib\Os\CTime` (glibc gmtime/localtime/mktime/strftime, zone from TZ or
  /etc/localtime, never PHP's default zone).

## Testing

Tests are the project's memory. Behavior is checked against the reference
interpreter `lua5.4` (5.4.9) and compiler `luac5.4`, both installed.

    php tests/run.php            our own tests (unit + differential); must stay green
    tests/bytecode.sh [file...]  diff `bin/luac -l -l -p` vs `luac5.4 -l -l -p` for official test files
    tests/official.sh [name...]  run official test files one at a time through bin/lua with
                                 -e"_U=true _soft=true _port=true _nomsg=true"; PASS/FAIL per file
    php bench/run.php <label>    memory/speed/virtual-memory benchmarks vs lua5.4 (not a test;
                                 results in bench/results/<label>.md, see bench/README.md)

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
  files pass `OPCACHE_ON_NEW_FILES` (tests/run.php: `-d opcache.enable_cli=1
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
