# Tracing-JIT inventory (php-src#24186)

Command, from the repository root:

    php tests/jit/inventory.php 1254

It is a heavy run (373 PHP processes, 4 at a time, about 5 minutes). On
Joe's machine run it inside the memory cap and the heavy-run lock:

    timeout -s KILL 7200 systemd-run --user --scope --quiet -p MemoryMax=3G -p MemorySwapMax=0 \
        flock -o -w 7200 /var/tmp/luaphp-heavy.lock bash -c 'ulimit -s 8192 && php tests/jit/inventory.php 1254'

What it does: it runs every input of the inventory input set
(tests/jit/inventory_inputs.php, classes (i) to (v)) under the JIT options
with `opcache.jit=1254`, the hot counters of tests/jit/detector.php and
`opcache.jit_debug`, and the detector (tests/jit/detector.php) judges every
loop trace the JIT compiled. A flagged loop trace jumps to the interrupt
stub at its loop end while it holds values in registers: after a VM
interrupt (an async signal handler, a sampling profiler) the loop runs
again from stale values. Canaries (a), (b) and (f) of
tests/unit/TracingJitTest.php show such loops return wrong results under
the interrupt harness, tests/jit/interrupts.php.

The three counts are in the output: inputs run (373 = 3 x 110 tests/diff
files + 11 speed workloads + 32 official files), loop traces examined
(13922) and flagged traces (1065, at 26 places).

How to read a flagged place:

- The line names the loop: our PHP file and the line of the loop's first
  opline, and its function. No place is in emitted code: under bin/lua the
  emitted code is eval'd and never JIT-compiled, and in lua2php output no
  emitted loop was flagged (for one, the command prints the Lua file, the
  emitted `$function_...` and the pc of the loop's instruction).
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
- traces: how many flagged traces, per input class, and the first input
  (sorted by name) that compiled one.

## Entries that vary between runs

None found. Runs 2 to 5 of the command on this machine flagged the same
25 places (file, line and function). Runs 4 and 5, on commit b531482 (the
detector and the command as committed here), gave the same 26 entries
(place and operation); their counts differed by 0.1% (loop traces
examined 13932 and 13922, flagged traces 1060 and 1065). Entries flagged
in a single trace (the "an opline not found" entry of
FunctionEmitter::inlineWeights, FunctionEmitter::emit, TableLib::remove)
depend on one process compiling one trace and are the most likely to
vary.

## Output (opcache.jit=1254)

Run 5, 2026-10-11, on commit b531482, PHP 8.5.11 NTS (x86-64):

php options of every run: `-d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.file_update_protection=0 -d opcache.jit=1254 -d opcache.jit_hot_loop=7 -d opcache.jit_hot_func=127 -d opcache.jit_hot_return=8 -d opcache.jit_hot_side_exit=8 -d opcache.jit_max_root_traces=1024 -d opcache.jit_max_side_traces=128 -d opcache.jit_debug=33845248`

- inputs run: 373 (the tree's count: 110 (i) + 110 (ii) + 11 (iii) + 32 (iv) + 110 (v) = 373)
  - (i) tests/diff as saved lua2php PHP: 110
  - (ii) tests/diff under bin/lua: 110
  - (iii) speed workloads as saved lua2php PHP: 11
  - (iv) official test files under bin/lua: 32
  - (v) tests/diff as saved lua2php PHP in compact form: 110
- exit statuses: 343 with 0, 24 with 1, 3 with 3, 3 with 7
- inputs with another exit status than 0 (most diff cases that end in an error expect it): (i) diff/accounts_program.lua: 1, (i) diff/coroutine_toplevel_error.lua: 1, (i) diff/fastpath_table_library.lua: 1, (i) diff/gc_os_exit.lua: 3, (i) diff/os_exit_close.lua: 7, (i) diff/os_exit_false.lua: 1, (i) diff/toplevel_deep_traceback.lua: 1, (i) diff/toplevel_error.lua: 1, (i) diff/toplevel_error_object.lua: 1, (i) diff/toplevel_error_table.lua: 1, (ii) diff/accounts_program.lua: 1, (ii) diff/coroutine_toplevel_error.lua: 1, (ii) diff/fastpath_table_library.lua: 1, (ii) diff/gc_os_exit.lua: 3, (ii) diff/os_exit_close.lua: 7, (ii) diff/os_exit_false.lua: 1, (ii) diff/toplevel_deep_traceback.lua: 1, (ii) diff/toplevel_error.lua: 1, (ii) diff/toplevel_error_object.lua: 1, (ii) diff/toplevel_error_table.lua: 1, (v) diff/accounts_program.lua: 1, (v) diff/coroutine_toplevel_error.lua: 1, (v) diff/fastpath_table_library.lua: 1, (v) diff/gc_os_exit.lua: 3, (v) diff/os_exit_close.lua: 7, (v) diff/os_exit_false.lua: 1, (v) diff/toplevel_deep_traceback.lua: 1, (v) diff/toplevel_error.lua: 1, (v) diff/toplevel_error_object.lua: 1, (v) diff/toplevel_error_table.lua: 1
- loop traces examined: 13922
  - (i) tests/diff as saved lua2php PHP: 3787
  - (ii) tests/diff under bin/lua: 5405
  - (iii) speed workloads as saved lua2php PHP: 134
  - (iv) official test files under bin/lua: 1291
  - (v) tests/diff as saved lua2php PHP in compact form: 3305
- loop traces without an interrupt check (never flagged): 3
- flagged traces: 1065, at 26 places
- run time: 319 s

### Flagged places

- src/Compiler/CodeGen.php:236 LuaPhp\Compiler\CodeGen::concat
  - operation: INIT_STATIC_METHOD_CALL at 0013 (EX(opline) from the IP)
  - later uses of the IP in its traces: an opline not found (IP set from the IP)
  - registers: CV3($list) in %rbx
  - traces: 10 ((i) 0, (ii) 1, (iii) 0, (iv) 9, (v) 0) in 10 inputs, the first (ii) diff/next_traversal_stress.lua

- src/Compiler/CodeGen.php:311 LuaPhp\Compiler\CodeGen::patchlistaux
  - operation: FETCH_CLASS_CONSTANT (VM handler call at an opline not found)
  - later uses of the IP in its traces: BW_NOT (VM handler call at an opline not found); FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV1($list) in %rbx
  - traces: 54 ((i) 3, (ii) 30, (iii) 0, (iv) 20, (v) 1) in 54 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:187 LuaPhp\Emitter\FunctionEmitter::inlineWeights
  - operation: FETCH_CLASS_CONSTANT (VM handler call at an opline not found)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV4($pc) in %r12
  - traces: 154 ((i) 19, (ii) 94, (iii) 0, (iv) 21, (v) 20) in 154 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:187 LuaPhp\Emitter\FunctionEmitter::inlineWeights
  - operation: an opline not found (VM handler call)
  - later uses of the IP in its traces: an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV4($pc) in %r12
  - traces: 1 ((i) 0, (ii) 1, (iii) 0, (iv) 0, (v) 0) in 1 inputs, the first (ii) diff/toplevel_error_table.lua

- src/Emitter/FunctionEmitter.php:226 LuaPhp\Emitter\FunctionEmitter::emit
  - operation: FETCH_CLASS_CONSTANT (VM handler call at an opline not found)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (EX(opline) from the IP); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV4($pc) in %r12
  - traces: 1 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 0) in 1 inputs, the first (i) diff/debug_locals.lua

- src/Emitter/FunctionEmitter.php:384 LuaPhp\Emitter\FunctionEmitter::planSegments
  - operation: COALESCE at 0080 (VM handler call)
  - later uses of the IP in its traces: FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV10($runLast) in %rbx, CV2($pc) in %rbx
  - traces: 21 ((i) 3, (ii) 14, (iii) 0, (iv) 1, (v) 3) in 21 inputs, the first (i) diff/load_cache_depth.lua

- src/Emitter/FunctionEmitter.php:632 LuaPhp\Emitter\FunctionEmitter::findLoops
  - operation: COALESCE at 0060 (VM handler call)
  - later uses of the IP in its traces: none
  - registers: CV3($pc) in %rbx, CV5($loops) in %r12
  - traces: 184 ((i) 21, (ii) 110, (iii) 0, (iv) 32, (v) 21) in 184 inputs, the first (i) diff/compact_forms.lua

- src/Emitter/FunctionEmitter.php:653 LuaPhp\Emitter\FunctionEmitter::findConstructorRuns
  - operation: FETCH_CLASS_CONSTANT at 0011 (VM handler call)
  - later uses of the IP in its traces: none
  - registers: CV3($pc) in %r12
  - traces: 181 ((i) 20, (ii) 108, (iii) 0, (iv) 32, (v) 21) in 181 inputs, the first (i) diff/compact_forms.lua

- src/Lib/Io/CFile.php:173 LuaPhp\Lib\Io\CFile::ownDescriptorLink
  - operation: BEGIN_SILENCE at 0004 (VM handler call)
  - later uses of the IP in its traces: BEGIN_SILENCE (VM handler call at an opline not found); an opline not found (EX(opline) from the IP); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV2($links) in %rbx
  - traces: 3 ((i) 1, (ii) 1, (iii) 0, (iv) 0, (v) 1) in 3 inputs, the first (i) diff/io_errno.lua

- src/Lib/IoLib.php:609 LuaPhp\Lib\IoLib::readLineIterator
  - operation: COALESCE at 0046 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV7($i) in %r12
  - traces: 2 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 1) in 2 inputs, the first (i) diff/io_files.lua

- src/Lib/String/StringFormat.php:68 LuaPhp\Lib\String\StringFormat::format
  - operation: COALESCE at 0071 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); FETCH_CLASS_CONSTANT (VM handler call at an opline not found); an opline not found (EX(opline) from the IP); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV3($arg) in %r12
  - traces: 14 ((i) 4, (ii) 3, (iii) 1, (iv) 2, (v) 4) in 14 inputs, the first (i) diff/compact_forms.lua

- src/Lib/TableLib.php:126 LuaPhp\Lib\TableLib::insert
  - operation: JMP_NULL at 0075 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV9($i) in %r12
  - traces: 2 ((i) 1, (ii) 0, (iii) 0, (iv) 0, (v) 1) in 2 inputs, the first (i) diff/tables.lua

- src/Lib/TableLib.php:161 LuaPhp\Lib\TableLib::remove
  - operation: JMP_NULL at 0069 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF (VM handler call at an opline not found); an opline not found (IP set from the IP)
  - registers: CV6($position) in %r12
  - traces: 1 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 1) in 1 inputs, the first (v) diff/fastpath_table_library.lua

- src/Lib/TableLib.php:244 LuaPhp\Lib\TableLib::concat
  - operation: COALESCE at 0047 (VM handler call)
  - later uses of the IP in its traces: CAST (VM handler call)
  - registers: CV12($index) in %r13, CV8($length) in %rbx
  - traces: 124 ((i) 42, (ii) 37, (iii) 2, (iv) 1, (v) 42) in 124 inputs, the first (i) diff/arith_random.lua

- src/Lib/TableLib.php:282 LuaPhp\Lib\TableLib::concat
  - operation: JMP_NULL at 0189 (VM handler call)
  - later uses of the IP in its traces: COALESCE (VM handler call at an opline not found); INIT_STATIC_METHOD_CALL (EX(opline) from the IP); INSTANCEOF (VM handler call at an opline not found); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV12($index) in %r12
  - traces: 14 ((i) 5, (ii) 3, (iii) 0, (iv) 0, (v) 6) in 14 inputs, the first (i) diff/fastpath_table_library.lua

- src/Lib/TableLib.php:327 LuaPhp\Lib\TableLib::unpack
  - operation: COALESCE at 0051 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV6($index) in %r12
  - traces: 15 ((i) 6, (ii) 2, (iii) 1, (iv) 2, (v) 4) in 15 inputs, the first (i) diff/coroutine_close.lua

- src/Lib/TableLib.php:347 LuaPhp\Lib\TableLib::unpack
  - operation: COALESCE at 0133 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV6($index) in %r12
  - traces: 16 ((i) 6, (ii) 4, (iii) 0, (iv) 1, (v) 5) in 16 inputs, the first (i) diff/call_metamethod_cycle.lua

- src/Lib/Utf8Lib.php:180 LuaPhp\Lib\Utf8Lib::codepoint
  - operation: INIT_ARRAY (VM handler call at an opline not found)
  - later uses of the IP in its traces: ADD_ARRAY_ELEMENT (VM handler call at an opline not found); an opline not found (IP set from the IP); an opline not found (VM handler call)
  - registers: CV8($position) in %r12
  - traces: 4 ((i) 1, (ii) 1, (iii) 0, (iv) 1, (v) 1) in 4 inputs, the first (i) diff/utf8_library.lua

- src/Runtime/DebugInfo.php:92 LuaPhp\Runtime\DebugInfo::getLocalName
  - operation: FE_FETCH_R at 0005 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV1($localNumber) in %rbx
  - traces: 54 ((i) 18, (ii) 17, (iii) 0, (iv) 1, (v) 18) in 54 inputs, the first (i) diff/accounts_program.lua

- src/Runtime/Hooks.php:175 LuaPhp\Runtime\Hooks::changedLine
  - operation: FETCH_CLASS_CONSTANT at 0021 (VM handler call)
  - later uses of the IP in its traces: none
  - registers: CV4($pc) in %r12
  - traces: 14 ((i) 5, (ii) 4, (iii) 0, (iv) 0, (v) 5) in 14 inputs, the first (i) diff/close_upvalues_and_variables.lua

- src/Runtime/LuaTable.php:136 LuaPhp\Runtime\LuaTable::fromList
  - operation: FE_FETCH_R at 0008 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP)
  - registers: CV2($key) in %rbx
  - traces: 51 ((i) 18, (ii) 15, (iii) 0, (iv) 0, (v) 18) in 51 inputs, the first (i) diff/coroutine_c_boundary.lua

- src/Runtime/LuaTable.php:475 LuaPhp\Runtime\LuaTable::firstEntryFrom
  - operation: COALESCE at 0010 in LuaPhp\Runtime\LuaTable::partCount (VM handler call)
  - later uses of the IP in its traces: COUNT in LuaPhp\Runtime\LuaTable::partCount (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV0($part) in %rbx
  - traces: 12 ((i) 4, (ii) 3, (iii) 1, (iv) 0, (v) 4) in 12 inputs, the first (i) diff/debug_getinfo.lua

- src/Runtime/NumberFormat.php:380 LuaPhp\Runtime\NumberFormat::multiplyByPower
  - operation: FE_FETCH_R at 0034 (EX(opline) from the IP)
  - later uses of the IP in its traces: FE_FETCH_R (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV7($carry) in %r12
  - traces: 70 ((i) 24, (ii) 19, (iii) 0, (iv) 1, (v) 26) in 70 inputs, the first (i) diff/accounts_program.lua

- src/Runtime/Op.php:764 LuaPhp\Runtime\Op::call
  - operation: COALESCE at 0082 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV9($i) in %r13
  - traces: 42 ((i) 1, (ii) 1, (iii) 0, (iv) 0, (v) 40) in 42 inputs, the first (i) diff/compact_forms.lua

- src/Runtime/Op.php:815 LuaPhp\Runtime\Op::vararg
  - operation: COALESCE at 0046 (VM handler call)
  - later uses of the IP in its traces: ASSIGN_DIM (EX(opline) from the IP)
  - registers: CV9($i) in %r13
  - traces: 2 ((i) 0, (ii) 0, (iii) 0, (iv) 0, (v) 2) in 2 inputs, the first (v) diff/load_reader.lua

- src/Runtime/TableConstructor.php:47 LuaPhp\Runtime\TableConstructor::run
  - operation: FETCH_CLASS_CONSTANT at 0019 (VM handler call)
  - later uses of the IP in its traces: COALESCE in LuaPhp\Runtime\LuaTable::set (VM handler call); FETCH_CLASS_CONSTANT (VM handler call); INSTANCEOF (VM handler call at an opline not found); INSTANCEOF (VM handler call); SL (EX(opline) from the IP); SR (EX(opline) from the IP); an opline not found (IP set from the IP)
  - registers: CV3($pc) in %rbx
  - traces: 19 ((i) 7, (ii) 4, (iii) 0, (iv) 0, (v) 8) in 19 inputs, the first (i) diff/fastpath_iterators.lua
