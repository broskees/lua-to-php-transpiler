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
per profile), loop traces examined (21043, also per class and per
profile) and flagged traces (1831, at 28 places).

How to read a flagged place:

- The line names the loop: our PHP file and the line of the loop's first
  opline, and its function. No place is in emitted code: under bin/lua the
  emitted code is eval'd and never JIT-compiled, and in lua2php output no
  emitted loop was flagged (their state lives in `$R` elements, not in
  registers; for one, the command prints the Lua file, the emitted
  `$function_...` and the pc of the loop's instruction).
- operation: the first use, in trace order, of the IP the trace compiler
  keeps track of; that use sets `ZEND_JIT_TRACE_USES_INITIAL_IP`, which gives
  the loop the interrupt stub. "VM handler call": an opcode without inline
  trace code, run by its handler (`??` is COALESCE, `?->` JMP_NULL, `@`
  BEGIN_SILENCE, a class constant read at run time FETCH_CLASS_CONSTANT).
  "EX(opline) from the IP": `foreach` (FE_FETCH_R) and other ops that store
  the opline. "in <function>": in a function the trace inlines. "at an
  opline not found": the detector found the opcode (from the same handler
  elsewhere in the process) but not its opline; "an opline not found
  (VM handler call)": neither.
- later uses of the IP in its traces: the other uses (some exist only
  because of the first). Removing the first use alone may leave the loop
  flagged.
- registers: the frame variables the loop keeps in registers, lost at the
  interrupt.
- traces: how many flagged traces, per input class and per profile, and
  the first input (sorted by name) that compiled one.

## Entries that vary between runs

Runs 6 and 7 of this command, both on commit 73678f0 (the detector and
the command as committed here), agreed on 27 entries (place and
operation). Their counts differed by less than 0.1%: loop traces examined
21043 and 21055, flagged traces 1831 and 1833.

One entry varies:

- src/Lib/Io/Errno.php:229 LuaPhp\Lib\Io\Errno::fromMessage (FE_FETCH_R
  at 0021): run 6 (the output below) flagged it in one trace of one input,
  (v) diff/load_chunks.lua under the eager profile; run 7 flagged it in
  none. Its loop runs 133 iterations in a single call, and whether the JIT
  compiles it as a loop trace, and along which path, depends on hot
  counters it shares with other loops.

These entries also rest on one or two traces of the eager profile, so
they may vary the same way: FunctionEmitter::inlineWeights ("an opline
not found (VM handler call)", 1 trace), FunctionEmitter::emit (1 trace),
TableLib::remove (1 trace), TableLib::insert (2 traces); and
PatternRegex::translate rests on 2 traces of the default profile. Before
the second profile existed, 4 runs of the eager profile alone flagged the
same 25 places (file, line and function).

## Output (opcache.jit=1254)

Run 6, 2026-10-11, on commit 73678f0, PHP 8.5.11 NTS (x86-64):

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
- loop traces examined: 21043
  - (i) tests/diff as saved lua2php PHP: 5399
  - (ii) tests/diff under bin/lua: 8377
  - (iii) speed workloads as saved lua2php PHP: 201
  - (iv) official test files under bin/lua: 2112
  - (v) tests/diff as saved lua2php PHP in compact form: 4954
  - profile default: 7120
  - profile eager: 13923
- loop traces without an interrupt check (never flagged): 7
- flagged traces: 1831, at 28 places
- run time: 560 s

### Flagged places

- src/Compiler/CodeGen.php:236 LuaPhp\Compiler\CodeGen::concat
  - operation: INIT_STATIC_METHOD_CALL at 0013 (EX(opline) from the IP)
  - later uses of the IP in its traces: an opline not found (IP set from the IP)
  - registers: CV3($list) in %rbx
  - traces: 11 ((i) 0, (ii) 1, (iii) 0, (iv) 10, (v) 0; profiles (default) 2, (eager) 9) in 11 inputs, the first (ii) diff/next_traversal_stress.lua

- src/Compiler/CodeGen.php:311 LuaPhp\Compiler\CodeGen::patchlistaux
  - operation: FETCH_CLASS_CONSTANT (VM handler call at an opline not found)
  - later uses of the IP in its traces: BW_NOT (VM handler call at an opline not found); FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV1($list) in %rbx
  - traces: 53 ((i) 3, (ii) 28, (iii) 0, (iv) 21, (v) 1; profiles (default) 1, (eager) 52) in 53 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:187 LuaPhp\Emitter\FunctionEmitter::inlineWeights
  - operation: FETCH_CLASS_CONSTANT (VM handler call at an opline not found)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV4($pc) in %r12
  - traces: 265 ((i) 26, (ii) 170, (iii) 0, (iv) 42, (v) 27; profiles (default) 108, (eager) 157) in 169 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:187 LuaPhp\Emitter\FunctionEmitter::inlineWeights
  - operation: an opline not found (VM handler call)
  - later uses of the IP in its traces: an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV4($pc) in %r12
  - traces: 1 ((i) 0, (ii) 1, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (ii) diff/toplevel_error_table.lua

- src/Emitter/FunctionEmitter.php:226 LuaPhp\Emitter\FunctionEmitter::emit
  - operation: FETCH_CLASS_CONSTANT (VM handler call at an opline not found)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (EX(opline) from the IP); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV4($pc) in %r12
  - traces: 1 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 0; profiles (default) 0, (eager) 1) in 1 inputs, the first (i) diff/debug_locals.lua

- src/Emitter/FunctionEmitter.php:384 LuaPhp\Emitter\FunctionEmitter::planSegments
  - operation: COALESCE at 0080 (VM handler call)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (IP set from the IP)
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
  - later uses of the IP in its traces: BEGIN_SILENCE (VM handler call at an opline not found); an opline not found (EX(opline) from the IP); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV2($links) in %rbx
  - traces: 6 ((i) 2, (ii) 2, (iii) 0, (iv) 0, (v) 2; profiles (default) 3, (eager) 3) in 3 inputs, the first (i) diff/io_errno.lua

- src/Lib/Io/Errno.php:229 LuaPhp\Lib\Io\Errno::fromMessage
  - operation: FE_FETCH_R at 0021 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV3($foundLength) in %rbx
  - traces: 1 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 1; profiles (default) 0, (eager) 1) in 1 inputs, the first (v) diff/load_chunks.lua

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
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (EX(opline) from the IP); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV3($arg) in %r12
  - traces: 20 ((i) 6, (ii) 5, (iii) 2, (iv) 1, (v) 6; profiles (default) 7, (eager) 13) in 14 inputs, the first (i) diff/compact_forms.lua

- src/Lib/TableLib.php:126 LuaPhp\Lib\TableLib::insert
  - operation: JMP_NULL at 0075 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV9($i) in %r12
  - traces: 2 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 1; profiles (default) 0, (eager) 2) in 2 inputs, the first (i) diff/tables.lua

- src/Lib/TableLib.php:161 LuaPhp\Lib\TableLib::remove
  - operation: JMP_NULL at 0069 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV6($position) in %r12
  - traces: 1 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 1; profiles (default) 0, (eager) 1) in 1 inputs, the first (v) diff/fastpath_table_library.lua

- src/Lib/TableLib.php:244 LuaPhp\Lib\TableLib::concat
  - operation: COALESCE at 0047 (VM handler call)
  - later uses of the IP in its traces: CAST (VM handler call)
  - registers: CV12($index) in %r13, CV8($length) in %rbx
  - traces: 219 ((i) 70, (ii) 69, (iii) 4, (iv) 2, (v) 74; profiles (default) 96, (eager) 123) in 128 inputs, the first (i) diff/arith_random.lua

- src/Lib/TableLib.php:282 LuaPhp\Lib\TableLib::concat
  - operation: JMP_NULL at 0189 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF (VM handler call at an opline not found); an opline not found (IP set from the IP); an opline not found (VM handler call)
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
  - operation: INIT_ARRAY (VM handler call at an opline not found)
  - later uses of the IP in its traces: ADD_ARRAY_ELEMENT (VM handler call at an opline not found); an opline not found (IP set from the IP); an opline not found (VM handler call)
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
  - traces: 30 ((i) 11, (ii) 9, (iii) 0, (iv) 0, (v) 10; profiles (default) 16, (eager) 14) in 17 inputs, the first (i) diff/close_upvalues_and_variables.lua

- src/Runtime/LuaTable.php:136 LuaPhp\Runtime\LuaTable::fromList
  - operation: FE_FETCH_R at 0008 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV2($key) in %rbx
  - traces: 88 ((i) 31, (ii) 27, (iii) 0, (iv) 0, (v) 30; profiles (default) 37, (eager) 51) in 51 inputs, the first (i) diff/coroutine_c_boundary.lua

- src/Runtime/LuaTable.php:475 LuaPhp\Runtime\LuaTable::firstEntryFrom
  - operation: COALESCE at 0010 in LuaPhp\Runtime\LuaTable::partCount (VM handler call)
  - later uses of the IP in its traces: COUNT in LuaPhp\Runtime\LuaTable::partCount (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV0($part) in %rbx
  - traces: 15 ((i) 4, (ii) 5, (iii) 2, (iv) 0, (v) 4; profiles (default) 3, (eager) 12) in 12 inputs, the first (i) diff/debug_getinfo.lua

- src/Runtime/NumberFormat.php:380 LuaPhp\Runtime\NumberFormat::multiplyByPower
  - operation: FE_FETCH_R at 0034 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV7($carry) in %r12
  - traces: 123 ((i) 39, (ii) 37, (iii) 0, (iv) 3, (v) 44; profiles (default) 52, (eager) 71) in 78 inputs, the first (i) diff/accounts_program.lua

- src/Runtime/Op.php:764 LuaPhp\Runtime\Op::call
  - operation: COALESCE at 0082 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV9($i) in %r13
  - traces: 83 ((i) 0, (ii) 1, (iii) 0, (iv) 0, (v) 82; profiles (default) 43, (eager) 40) in 66 inputs, the first (ii) diff/compact_forms.lua

- src/Runtime/Op.php:815 LuaPhp\Runtime\Op::vararg
  - operation: COALESCE at 0046 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV9($i) in %r13
  - traces: 3 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 3; profiles (default) 0, (eager) 3) in 3 inputs, the first (v) diff/load_reader.lua

- src/Runtime/TableConstructor.php:47 LuaPhp\Runtime\TableConstructor::run
  - operation: FETCH_CLASS_CONSTANT at 0019 (VM handler call)
  - later uses of the IP in its traces: COALESCE in LuaPhp\Runtime\LuaTable::set (VM handler call); FETCH_CLASS_CONSTANT (VM handler call); INSTANCEOF (VM handler call at an opline not found); INSTANCEOF (VM handler call); SL (EX(opline) from the IP); SR (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV3($pc) in %rbx
  - traces: 29 ((i) 11, (ii) 7, (iii) 0, (iv) 0, (v) 11; profiles (default) 9, (eager) 20) in 20 inputs, the first (i) diff/fastpath_iterators.lua
