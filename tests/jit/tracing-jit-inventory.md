# Tracing-JIT inventory (php-src#24186)

Command, from the repository root:

    php tests/jit/inventory.php 1254

It is a heavy run (373 inputs, each run twice, 4 PHP processes at a time,
about 10 minutes). On Joe's machine run it inside the memory cap and the
heavy-run lock:

    timeout -s KILL 7200 systemd-run --user --scope --quiet -p MemoryMax=3G -p MemorySwapMax=0 \
        flock -o -w 7200 /var/tmp/luaphp-heavy.lock bash -c 'ulimit -s 8192 && php tests/jit/inventory.php 1254'

What it does: it runs every input of the inventory input set
(tests/jit/inventory_inputs.php, classes (i) to (v)) under the JIT options
with `opcache.jit=1254` and `opcache.jit_debug`, once with each hot-counter
profile of tests/jit/detector.php (`default`: PHP's own; `eager`: loops hot
after 7 iterations, so that the loops of the short test programs get
compiled; each profile traces paths the other does not). The detector
(tests/jit/detector.php) judges every loop trace the JIT compiled. A
flagged loop trace jumps to the interrupt stub at its loop end while it
holds values in registers: after a VM interrupt (an async signal handler,
a sampling profiler) the loop runs again from stale values. Canaries (a),
(b) and (f) of tests/unit/TracingJitTest.php show such loops return wrong
results under the interrupt harness, tests/jit/interrupts.php.

The three counts are in the output: inputs run (373 = 3 x 110
tests/diff files + 11 speed workloads + 32 official files, each run once
per profile), loop traces examined (21037, also per class and per
profile) and flagged traces (1831, at 27 places).

How to read a flagged place:

- The line names the loop: our PHP file and the line of the loop's first
  opline, and its function. For a loop in emitted code it names the Lua
  file, the emitted `$function_...` with its Lua line range, and the pc
  and comment of the instruction whose code holds the loop (lines counted
  as PHP counts them: a lone "\r" ends one too). Under bin/lua the emitted
  code is eval'd and never JIT-compiled. In lua2php output one emitted
  loop is flagged on the phase-1 tree (commit 14622c6), though not in the
  output below (commit cd74903): the loop the emitter writes for
  OP_SETLIST with B = 0 (`{...}`, or a constructor that ends in a call).
  For a nil value it runs `unset($t->arr[...])`, which is FETCH_OBJ_UNSET
  and UNSET_DIM, two VM handler calls. Its two places are under "Entries
  that vary between runs". Phase 2 (commit ad0d07a) changed the loop to
  unset an element only when `isset` says it is there.
- operation: the first use, in trace order, of the IP the trace compiler
  keeps track of; that use sets `ZEND_JIT_TRACE_USES_INITIAL_IP`, which gives
  the loop the interrupt stub. It names the opcode and where it is: "at
  0047" is opline 0047 of the loop's own function (the place's file and
  function); "at 0010 in <method> (<file>:<line>)" is opline 0010 of a
  method the trace inlines, which starts at that file and line. PHP reports
  no source line per opline (no opcache dump prints one), so the opline
  number is the finest place. "VM handler call": an opcode without inline
  trace code, run by its handler (`??` is COALESCE, `?->` JMP_NULL, `@`
  BEGIN_SILENCE, a class constant read at run time FETCH_CLASS_CONSTANT).
  "EX(opline) from the IP": `foreach` (FE_FETCH_R) and other ops that store
  the opline. The summary counts flagged traces whose operation names no
  opline, and those with any use of the IP the detector could not place
  (one of those could come first): both are 0. (A run's report with such a
  trace is read again at the end with the VM handlers every run of the
  profile placed: tests/jit/detector.php, IpUses and handlerKey.)
- later uses of the IP in its traces: the other uses (some exist only
  because of the first). Removing the first use alone may leave the loop
  flagged.
- registers: the frame variables the loop keeps in registers, lost at the
  interrupt.
- traces: how many flagged traces, per input class and per profile, and
  the first input (sorted by name) that compiled one.

## Entries that vary between runs

The output below is run 4 of iteration 2, on commit cd74903: the phase-1
branch before it merged main. The phase-1 tree is the merge commit
14622c6. It adds main's phase 3, src/Runtime/JitSafety.php, which every
entry point calls before it runs Lua code. Its other PHP is cd74903's, and
lua2php emits the same code on both commits.

On cd74903, runs 3 and 4 of this command (the detector and the command
as committed here) agreed on all 27 entries (place and operation). Their
counts differed by less than 0.4%: loop traces examined 21034 and 21037,
flagged traces 1825 and 1831. In both, 0 flagged traces had an operation
without an opline, and 0 had a use of the IP not placed.

On 14622c6, seven runs of this command on 2026-10-11 all ran 373 inputs
and flagged the 27 places of the output and the two emitted places below;
three of them also flagged src/Lib/Utf8Lib.php:286 (below). Six ran
phase 1's tests/jit/ (five on a copy of the tree, one by the final
verification); the seventh ran 14622c6's src/, bin/ and tests/diff/ with
the tests/jit/ of the repair after it, whose places for emitted code count
lines as PHP does (the six named the two emitted places by the RETURN
after the loop: `[5] RETURN 0 2 1` and `[6] RETURN 0 1 1`). Loop traces
examined: 20916 to 20959; flagged traces: 1867 to 1881, at 29 or 30
places. In all seven, 0 flagged traces had an operation without an
opline, and 0 had a use of the IP not placed.

Emitted code, flagged in every run on 14622c6 and in no run on cd74903:

- table_semantics.lua $function_0_3 <41,41> [4] SETLIST 0 0 0 ; line 41
  - operation: FETCH_OBJ_UNSET at 0117 (VM handler call)
  - later uses of the IP in its traces: UNSET_DIM (VM handler call)
  - registers: CV9($i) in %r12
  - traces: 1 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (i) diff/table_semantics.lua

- tables.lua $function_0_0 <3,3> [4] SETLIST 0 0 0 ; line 3
  - operation: FETCH_OBJ_UNSET at 0117 (VM handler call)
  - later uses of the IP in its traces: UNSET_DIM (VM handler call)
  - registers: CV9($i) in %r12
  - traces: 1 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (i) diff/tables.lua

Both are the loop the emitter writes for `[4] SETLIST 0 0 0` in `local
function pack(...) return {...} end` (table_semantics.lua line 41) and
`local function values(...) return {...} end` (tables.lua line 3). The
loop, the same on both commits:

    for ($i = 1; $i <= $n; $i++) {
        $v = $R[0 + $i];
        if ($v !== null) { $t->arr[0 + $i] = $v; } else { unset($t->arr[0 + $i]); }
    }

Under the eager profile its loop trace keeps `$i` in a register on both
commits. On 14622c6 the trace records an iteration that stores nil (as
`pack(nil)` and `values(nil, nil)` do), so it runs the two VM handler
calls of `unset` and is flagged. On cd74903 it records an iteration that
stores a value, which uses no IP, so it is not flagged. The iteration it
records is the one at which the loop's hot counter runs out, and code
that runs before moves that point (here JitSafety's, the only PHP that
differs between the two commits): on cd74903, the
lua2php file of tables.lua run alone flags the loop with
`opcache.jit_hot_loop` 5, 6 and 9, and not with 7 (the eager profile), 8
or 10. How often: on 14622c6, in 7 of 7 full runs (1 trace each, eager
profile); each file's lua2php output run alone, 10 of 10 runs under
the eager profile and 0 of 10 under the default profile. Before the
merge: in none of the 11 full runs of phase 1's two iterations (runs 3
and 4 of iteration 2 on cd74903), and on cd74903 in 0 of 5 runs of each
file alone under the eager profile.

Our PHP, flagged in some runs on 14622c6 only:

- src/Lib/Utf8Lib.php:286 LuaPhp\Lib\Utf8Lib::iterAux
  - operation: INIT_STATIC_METHOD_CALL at 0027 (EX(opline) from the IP)
  - later uses of the IP in its traces: none
  - registers: CV5($n) in %rbx
  - traces: 1 ((i) 0, (ii) 1, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (ii) diff/utf8_library.lua

Its loop `while (self::isContinuation($s, $n)) { $n++; }` starts with a
static method call, which stores the opline from the IP. How often: on
14622c6, in 3 of 7 full runs (1 trace each, eager profile); (ii)
diff/utf8_library.lua run alone under the eager profile, 3 of 10 runs
(each one that compiled 85 loop traces; the other 7 compiled 74 or 80),
under the default profile 0 of 10; its (i) and (v) inputs under the
eager profile, 0 of 10 each. Before the merge: in none of the 11 full
runs of phase 1. The loop was the same after phase 2; the repair after
final verification moved the call into the loop's body, and
tests/diff/utf8_codes_continuation_bytes.lua makes the old loop flagged
in every eager run of its (i) input.

Before the merge two entries varied between runs; on 14622c6 every run
flagged both (under the eager profile):

- src/Emitter/FunctionEmitter.php:226 LuaPhp\Emitter\FunctionEmitter::emit:
  flagged in one trace of one input, (i) diff/debug_locals.lua under the
  eager profile; run 2 (same flagging code) flagged it in none. On
  14622c6: 2 traces in each run.
- src/Lib/Io/Errno.php:229 LuaPhp\Lib\Io\Errno::fromMessage: flagged in
  one trace of one input under the eager profile; a run of iteration 1
  flagged it in none. Its loop runs 133 iterations in a single call. On
  14622c6: 1 or 2 traces in each run.

Whether the JIT compiles such a loop as a loop trace, and along which
path, depends on hot counters it shares with other loops. Other entries
resting on a few traces may vary the same way (traces in the output
below; in the runs on 14622c6): TableLib::remove (1; 2 or 3),
TableLib::insert (2; 3 or 4), PatternRegex::translate (2, default
profile; 1), Op::vararg (3; 2), Utf8Lib::codepoint (4; 3 or 4).

## Output (opcache.jit=1254)

Run 4 of iteration 2, 2026-10-11, on commit cd74903 (the phase-1 branch
before it merged main; for the merge commit 14622c6 see "Entries that
vary between runs"), PHP 8.5.11 NTS (x86-64):

php options of the runs of profile default: `-d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.file_update_protection=0 -d opcache.jit=1254 -d opcache.jit_hot_loop=61 -d opcache.jit_hot_func=127 -d opcache.jit_hot_return=8 -d opcache.jit_hot_side_exit=8 -d opcache.jit_max_root_traces=1024 -d opcache.jit_max_side_traces=128 -d opcache.jit_debug=33845248`

php options of the runs of profile eager: `-d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.file_update_protection=0 -d opcache.jit=1254 -d opcache.jit_hot_loop=7 -d opcache.jit_hot_func=127 -d opcache.jit_hot_return=8 -d opcache.jit_hot_side_exit=8 -d opcache.jit_max_root_traces=1024 -d opcache.jit_max_side_traces=128 -d opcache.jit_debug=33845248`

- inputs run: 373 (the tree's count: 110 (i) + 110 (ii) + 11 (iii) + 32 (iv) + 110 (v) = 373), each once per hot-counter profile (default, eager): 746 runs
  - (i) tests/diff as saved lua2php PHP: 110
  - (ii) tests/diff under bin/lua: 110
  - (iii) speed workloads as saved lua2php PHP: 11
  - (iv) official test files under bin/lua: 32
  - (v) tests/diff as saved lua2php PHP in compact form: 110
- exit statuses of the runs: 686 with 0, 48 with 1, 6 with 3, 6 with 7
- inputs with another exit status than 0 (most diff cases that end in an error expect it): (i) diff/accounts_program.lua: 1 (default), 1 (eager), (i) diff/coroutine_toplevel_error.lua: 1 (default), 1 (eager), (i) diff/fastpath_table_library.lua: 1 (default), 1 (eager), (i) diff/gc_os_exit.lua: 3 (default), 3 (eager), (i) diff/os_exit_close.lua: 7 (default), 7 (eager), (i) diff/os_exit_false.lua: 1 (default), 1 (eager), (i) diff/toplevel_deep_traceback.lua: 1 (default), 1 (eager), (i) diff/toplevel_error.lua: 1 (default), 1 (eager), (i) diff/toplevel_error_object.lua: 1 (default), 1 (eager), (i) diff/toplevel_error_table.lua: 1 (default), 1 (eager), (ii) diff/accounts_program.lua: 1 (default), 1 (eager), (ii) diff/coroutine_toplevel_error.lua: 1 (default), 1 (eager), (ii) diff/fastpath_table_library.lua: 1 (default), 1 (eager), (ii) diff/gc_os_exit.lua: 3 (default), 3 (eager), (ii) diff/os_exit_close.lua: 7 (default), 7 (eager), (ii) diff/os_exit_false.lua: 1 (default), 1 (eager), (ii) diff/toplevel_deep_traceback.lua: 1 (default), 1 (eager), (ii) diff/toplevel_error.lua: 1 (default), 1 (eager), (ii) diff/toplevel_error_object.lua: 1 (default), 1 (eager), (ii) diff/toplevel_error_table.lua: 1 (default), 1 (eager), (v) diff/accounts_program.lua: 1 (default), 1 (eager), (v) diff/coroutine_toplevel_error.lua: 1 (default), 1 (eager), (v) diff/fastpath_table_library.lua: 1 (default), 1 (eager), (v) diff/gc_os_exit.lua: 3 (default), 3 (eager), (v) diff/os_exit_close.lua: 7 (default), 7 (eager), (v) diff/os_exit_false.lua: 1 (default), 1 (eager), (v) diff/toplevel_deep_traceback.lua: 1 (default), 1 (eager), (v) diff/toplevel_error.lua: 1 (default), 1 (eager), (v) diff/toplevel_error_object.lua: 1 (default), 1 (eager), (v) diff/toplevel_error_table.lua: 1 (default), 1 (eager)
- loop traces examined: 21037
  - (i) tests/diff as saved lua2php PHP: 5399
  - (ii) tests/diff under bin/lua: 8370
  - (iii) speed workloads as saved lua2php PHP: 201
  - (iv) official test files under bin/lua: 2107
  - (v) tests/diff as saved lua2php PHP in compact form: 4960
  - profile default: 7117
  - profile eager: 13920
- loop traces without an interrupt check (never flagged): 6
- flagged traces: 1831, at 27 places
  - whose operation names no opline: 0
  - with a use of the IP not placed (the operation could be another): 0
- run time: 609 s

### Flagged places

- src/Compiler/CodeGen.php:236 LuaPhp\Compiler\CodeGen::concat
  - operation: INIT_STATIC_METHOD_CALL at 0013 (EX(opline) from the IP)
  - later uses of the IP in its traces: none
  - registers: CV3($list) in %rbx
  - traces: 12 ((i) 0, (ii) 1, (iii) 0, (iv) 11, (v) 0; profiles (default) 2, (eager) 10) in 12 inputs, the first (ii) diff/next_traversal_stress.lua

- src/Compiler/CodeGen.php:311 LuaPhp\Compiler\CodeGen::patchlistaux
  - operation: FETCH_CLASS_CONSTANT at 0013 in LuaPhp\Compiler\CodeGen::patchtestreg (src/Compiler/CodeGen.php:283) (VM handler call)
  - later uses of the IP in its traces: BW_NOT in LuaPhp\Compiler\OpCodes::mask0 (src/Compiler/OpCodes.php:434) (VM handler call); FETCH_CLASS_CONSTANT in LuaPhp\Compiler\CodeGen::fixjump (src/Compiler/CodeGen.php:216) (VM handler call); FETCH_CLASS_CONSTANT in LuaPhp\Compiler\CodeGen::patchtestreg (src/Compiler/CodeGen.php:283) (VM handler call)
  - registers: CV1($list) in %rbx
  - traces: 55 ((i) 3, (ii) 30, (iii) 0, (iv) 21, (v) 1; profiles (default) 1, (eager) 54) in 55 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:187 LuaPhp\Emitter\FunctionEmitter::inlineWeights
  - operation: FETCH_CLASS_CONSTANT at 0006 in LuaPhp\Emitter\FunctionEmitter::instructionWeight (src/Emitter/FunctionEmitter.php:437) (VM handler call)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT in LuaPhp\Emitter\FunctionEmitter::instructionWeight (src/Emitter/FunctionEmitter.php:437) (VM handler call)
  - registers: CV4($pc) in %r12
  - traces: 258 ((i) 27, (ii) 164, (iii) 0, (iv) 41, (v) 26; profiles (default) 108, (eager) 150) in 167 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:226 LuaPhp\Emitter\FunctionEmitter::emit
  - operation: FETCH_CLASS_CONSTANT at 0006 in LuaPhp\Emitter\FunctionEmitter::comment (src/Emitter/FunctionEmitter.php:951) (VM handler call)
  - later uses of the IP in its traces: CONCAT in LuaPhp\Emitter\FunctionEmitter::comment (src/Emitter/FunctionEmitter.php:951) (VM handler call); FETCH_CLASS_CONSTANT in LuaPhp\Emitter\FunctionEmitter::comment (src/Emitter/FunctionEmitter.php:951) (VM handler call); FETCH_DIM_R in LuaPhp\Emitter\FunctionEmitter::comment (src/Emitter/FunctionEmitter.php:951) (EX(opline) from the IP); INIT_STATIC_METHOD_CALL in LuaPhp\Emitter\FunctionEmitter::comment (src/Emitter/FunctionEmitter.php:951) (EX(opline) from the IP); ROPE_ADD in LuaPhp\Emitter\FunctionEmitter::comment (src/Emitter/FunctionEmitter.php:951) (VM handler call)
  - registers: CV4($pc) in %r12
  - traces: 1 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (i) diff/debug_locals.lua

- src/Emitter/FunctionEmitter.php:384 LuaPhp\Emitter\FunctionEmitter::planSegments
  - operation: COALESCE at 0080 (VM handler call)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT in LuaPhp\Emitter\FunctionEmitter::instructionWeight (src/Emitter/FunctionEmitter.php:437) (VM handler call)
  - registers: CV10($runLast) in %rbx, CV2($pc) in %rbx
  - traces: 37 ((i) 6, (ii) 22, (iii) 0, (iv) 3, (v) 6; profiles (default) 16, (eager) 21) in 26 inputs, the first (i) diff/load_cache_depth.lua

- src/Emitter/FunctionEmitter.php:632 LuaPhp\Emitter\FunctionEmitter::findLoops
  - operation: COALESCE at 0060 (VM handler call)
  - later uses of the IP in its traces: none
  - registers: CV3($pc) in %rbx, CV5($loops) in %r12
  - traces: 340 ((i) 33, (ii) 210, (iii) 0, (iv) 63, (v) 34; profiles (default) 156, (eager) 184) in 184 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:653 LuaPhp\Emitter\FunctionEmitter::findConstructorRuns
  - operation: FETCH_CLASS_CONSTANT at 0011 (VM handler call)
  - later uses of the IP in its traces: none
  - registers: CV3($pc) in %r12
  - traces: 331 ((i) 29, (ii) 207, (iii) 0, (iv) 63, (v) 32; profiles (default) 150, (eager) 181) in 181 inputs, the first (i) diff/compact_forms.lua

- src/Lib/Io/CFile.php:173 LuaPhp\Lib\Io\CFile::ownDescriptorLink
  - operation: BEGIN_SILENCE at 0004 (VM handler call)
  - later uses of the IP in its traces: ASSIGN (EX(opline) from the IP); BEGIN_SILENCE (VM handler call); END_SILENCE (VM handler call)
  - registers: CV2($links) in %rbx
  - traces: 6 ((i) 2, (ii) 2, (iii) 0, (iv) 0, (v) 2; profiles (default) 3, (eager) 3) in 3 inputs, the first (i) diff/io_errno.lua

- src/Lib/Io/Errno.php:229 LuaPhp\Lib\Io\Errno::fromMessage
  - operation: FE_FETCH_R at 0021 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV3($foundLength) in %rbx
  - traces: 1 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (i) diff/load_chunks.lua

- src/Lib/IoLib.php:609 LuaPhp\Lib\IoLib::readLineIterator
  - operation: COALESCE at 0046 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV7($i) in %r12
  - traces: 5 ((i) 2, (ii) 1, (iii) 0, (iv) 0, (v) 2; profiles (default) 3, (eager) 2) in 3 inputs, the first (i) diff/io_files.lua

- src/Lib/String/PatternRegex.php:142 LuaPhp\Lib\String\PatternRegex::translate
  - operation: FETCH_CLASS_CONSTANT at 0016 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call)
  - registers: CV7($recursiveItems) in %r12
  - traces: 2 ((i) 0, (ii) 2, (iii) 0, (iv) 0, (v) 0; profiles (default) 2, (eager) 0) in 2 inputs, the first (ii) diff/string_pattern_corpus.lua

- src/Lib/String/StringFormat.php:68 LuaPhp\Lib\String\StringFormat::format
  - operation: COALESCE at 0071 (VM handler call)
  - later uses of the IP in its traces: ASSIGN (EX(opline) from the IP); ASSIGN_OP (VM handler call); COALESCE (VM handler call); FETCH_CLASS_CONSTANT (VM handler call)
  - registers: CV3($arg) in %r12
  - traces: 20 ((i) 6, (ii) 5, (iii) 2, (iv) 1, (v) 6; profiles (default) 7, (eager) 13) in 14 inputs, the first (i) diff/compact_forms.lua

- src/Lib/TableLib.php:126 LuaPhp\Lib\TableLib::insert
  - operation: JMP_NULL at 0075 (VM handler call)
  - later uses of the IP in its traces: COALESCE in LuaPhp\Lib\TableLib::getIndex (src/Lib/TableLib.php:77) (VM handler call); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF in LuaPhp\Lib\TableLib::getIndex (src/Lib/TableLib.php:77) (VM handler call); INSTANCEOF in LuaPhp\Lib\TableLib::setIndex (src/Lib/TableLib.php:89) (VM handler call)
  - registers: CV9($i) in %r12
  - traces: 2 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 1; profiles (default) 0, (eager) 2) in 2 inputs, the first (i) diff/tables.lua

- src/Lib/TableLib.php:161 LuaPhp\Lib\TableLib::remove
  - operation: JMP_NULL at 0069 (VM handler call)
  - later uses of the IP in its traces: COALESCE in LuaPhp\Lib\TableLib::getIndex (src/Lib/TableLib.php:77) (VM handler call); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF in LuaPhp\Lib\TableLib::getIndex (src/Lib/TableLib.php:77) (VM handler call); INSTANCEOF in LuaPhp\Lib\TableLib::setIndex (src/Lib/TableLib.php:89) (VM handler call)
  - registers: CV6($position) in %r12
  - traces: 1 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 1; profiles (default) 0, (eager) 1) in 1 inputs, the first (v) diff/fastpath_table_library.lua

- src/Lib/TableLib.php:244 LuaPhp\Lib\TableLib::concat
  - operation: COALESCE at 0047 (VM handler call)
  - later uses of the IP in its traces: CAST (VM handler call)
  - registers: CV12($index) in %r13, CV8($length) in %rbx
  - traces: 221 ((i) 70, (ii) 71, (iii) 4, (iv) 2, (v) 74; profiles (default) 96, (eager) 125) in 127 inputs, the first (i) diff/arith_random.lua

- src/Lib/TableLib.php:282 LuaPhp\Lib\TableLib::concat
  - operation: JMP_NULL at 0189 (VM handler call)
  - later uses of the IP in its traces: CAST in LuaPhp\Runtime\LuaObject::numberToString (src/Runtime/LuaObject.php:58) (VM handler call); COALESCE in LuaPhp\Lib\TableLib::getIndex (src/Lib/TableLib.php:77) (VM handler call); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF in LuaPhp\Lib\TableLib::getIndex (src/Lib/TableLib.php:77) (VM handler call)
  - registers: CV12($index) in %r12
  - traces: 16 ((i) 6, (ii) 4, (iii) 0, (iv) 0, (v) 6; profiles (default) 3, (eager) 13) in 14 inputs, the first (i) diff/fastpath_table_library.lua

- src/Lib/TableLib.php:327 LuaPhp\Lib\TableLib::unpack
  - operation: COALESCE at 0051 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV6($index) in %r12
  - traces: 28 ((i) 9, (ii) 6, (iii) 2, (iv) 4, (v) 7; profiles (default) 13, (eager) 15) in 18 inputs, the first (i) diff/coroutine_close.lua

- src/Lib/TableLib.php:347 LuaPhp\Lib\TableLib::unpack
  - operation: COALESCE at 0133 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV6($index) in %r12
  - traces: 29 ((i) 10, (ii) 8, (iii) 0, (iv) 2, (v) 9; profiles (default) 13, (eager) 16) in 19 inputs, the first (i) diff/call_metamethod_cycle.lua

- src/Lib/Utf8Lib.php:180 LuaPhp\Lib\Utf8Lib::codepoint
  - operation: INIT_ARRAY at 0065 in LuaPhp\Lib\Utf8Lib::decode (src/Lib/Utf8Lib.php:94) (VM handler call)
  - later uses of the IP in its traces: ADD_ARRAY_ELEMENT in LuaPhp\Lib\Utf8Lib::decode (src/Lib/Utf8Lib.php:94) (VM handler call)
  - registers: CV8($position) in %r12
  - traces: 4 ((i) 1, (ii) 1, (iii) 0, (iv) 1, (v) 1; profiles (default) 0, (eager) 4) in 4 inputs, the first (i) diff/utf8_library.lua

- src/Runtime/DebugInfo.php:92 LuaPhp\Runtime\DebugInfo::getLocalName
  - operation: FE_FETCH_R at 0005 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV1($localNumber) in %rbx
  - traces: 88 ((i) 28, (ii) 28, (iii) 0, (iv) 3, (v) 29; profiles (default) 35, (eager) 53) in 58 inputs, the first (i) diff/accounts_program.lua

- src/Runtime/Hooks.php:175 LuaPhp\Runtime\Hooks::changedLine
  - operation: FETCH_CLASS_CONSTANT at 0021 (VM handler call)
  - later uses of the IP in its traces: none
  - registers: CV4($pc) in %r12
  - traces: 31 ((i) 11, (ii) 10, (iii) 0, (iv) 0, (v) 10; profiles (default) 16, (eager) 15) in 17 inputs, the first (i) diff/close_upvalues_and_variables.lua

- src/Runtime/LuaTable.php:136 LuaPhp\Runtime\LuaTable::fromList
  - operation: FE_FETCH_R at 0008 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV2($key) in %rbx
  - traces: 88 ((i) 31, (ii) 27, (iii) 0, (iv) 0, (v) 30; profiles (default) 37, (eager) 51) in 51 inputs, the first (i) diff/coroutine_c_boundary.lua

- src/Runtime/LuaTable.php:475 LuaPhp\Runtime\LuaTable::firstEntryFrom
  - operation: COALESCE at 0010 in LuaPhp\Runtime\LuaTable::partCount (src/Runtime/LuaTable.php:987) (VM handler call)
  - later uses of the IP in its traces: COUNT in LuaPhp\Runtime\LuaTable::partCount (src/Runtime/LuaTable.php:987) (EX(opline) from the IP)
  - registers: CV0($part) in %rbx
  - traces: 15 ((i) 4, (ii) 5, (iii) 2, (iv) 0, (v) 4; profiles (default) 3, (eager) 12) in 12 inputs, the first (i) diff/debug_getinfo.lua

- src/Runtime/NumberFormat.php:380 LuaPhp\Runtime\NumberFormat::multiplyByPower
  - operation: FE_FETCH_R at 0034 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV7($carry) in %r12
  - traces: 124 ((i) 39, (ii) 38, (iii) 0, (iv) 3, (v) 44; profiles (default) 52, (eager) 72) in 79 inputs, the first (i) diff/accounts_program.lua

- src/Runtime/Op.php:764 LuaPhp\Runtime\Op::call
  - operation: COALESCE at 0082 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV9($i) in %r13
  - traces: 84 ((i) 1, (ii) 1, (iii) 0, (iv) 0, (v) 82; profiles (default) 43, (eager) 41) in 67 inputs, the first (i) diff/compact_forms.lua

- src/Runtime/Op.php:815 LuaPhp\Runtime\Op::vararg
  - operation: COALESCE at 0046 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV9($i) in %r13
  - traces: 3 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 3; profiles (default) 0, (eager) 3) in 3 inputs, the first (v) diff/load_reader.lua

- src/Runtime/TableConstructor.php:47 LuaPhp\Runtime\TableConstructor::run
  - operation: FETCH_CLASS_CONSTANT at 0019 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_OBJ in LuaPhp\Runtime\LuaTable::set (src/Runtime/LuaTable.php:207) (EX(opline) from the IP); CAST in LuaPhp\Runtime\LuaTable::floatToInteger (src/Runtime/LuaTable.php:167) (VM handler call); COALESCE in LuaPhp\Runtime\LuaTable::set (src/Runtime/LuaTable.php:207) (VM handler call); FETCH_CLASS_CONSTANT (VM handler call); INSTANCEOF (VM handler call); INSTANCEOF in LuaPhp\Runtime\Vm::setTable (src/Runtime/Vm.php:384) (VM handler call); NEW in LuaPhp\Runtime\LuaTable::set (src/Runtime/LuaTable.php:207) (VM handler call); SL (EX(opline) from the IP); SR (EX(opline) from the IP)
  - registers: CV3($pc) in %rbx
  - traces: 29 ((i) 11, (ii) 7, (iii) 0, (iv) 0, (v) 11; profiles (default) 9, (eager) 20) in 20 inputs, the first (i) diff/fastpath_iterators.lua
