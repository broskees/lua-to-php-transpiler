<?php

/**
 * The detector for php-src#24186 (tracing JIT, PHP 8.4 and newer): a VM
 * interrupt (EG(vm_interrupt): an async signal handler, a sampling
 * profiler) taken at the end of a JIT-compiled loop trace makes the loop
 * run again from values its frame held before the trace kept them in CPU
 * registers. It happens to a loop trace whose interrupt check jumps to
 * the shared interrupt stub instead of a deoptimizing exit, which the
 * trace compiler chooses when the trace's code uses the IP the compiler
 * keeps track of (ZEND_JIT_TRACE_USES_INITIAL_IP: a call to the VM handler
 * of an opcode without inline code, such as ?? and ?:, or EX(opline) set
 * from the IP, as foreach's FE_FETCH_R does) and its stack holds no
 * register in the old sense (zend_jit_trace.c:7226-7238 and 3493-3505 in
 * php-8.5.11; the analysis is in the issue).
 *
 * The verdicts come only from what the JIT reports about the code it
 * compiled (opcache.jit_debug, see JIT_DEBUG): per compiled trace, its
 * start line, its bytecode, its stop reason and its final IR. A loop
 * trace (stopped at "loop", "recursive call" or "recursive return") is
 * FLAGGED when
 *   - its interrupt check, the GUARD_NOT on the byte EG(vm_interrupt) that
 *     ends the trace's loop (the input of the LOOP_END its LOOP_BEGIN
 *     closes with), has no SNAPSHOT: jit_SNAPSHOT (zend_jit_ir.c:646-669)
 *     leaves it out exactly when the guard goes to a stub, which writes
 *     nothing back; with a SNAPSHOT the guard goes to an exit that writes
 *     the registers back to the frame (a deoptimizing exit), and
 *   - the loop carries a value in a register: a PHI of the loop header
 *     with a register whose value from the back edge is not stored to its
 *     frame slot (spilled, ":store" = IR_REG_SPILL_STORE or SPILL_SPECIAL,
 *     to the slot both are bound to, "BIND"; a spill to the C stack is
 *     lost too).
 * The operations that caused it are reported too: those whose code uses
 * the IP the trace keeps track of (see IpUses).
 *
 * Used by tests/unit/TracingJitTest.php (the canaries) and
 * tests/jit/inventory.php (our code).
 */

declare(strict_types=1);

namespace Tests\TracingJit;

/** what opcache.jit_debug prints for the detector (php-src ext/opcache/jit/zend_jit.h) */
const JIT_DEBUG_TRACE_START = 1 << 12;
const JIT_DEBUG_TRACE_STOP = 1 << 13;
const JIT_DEBUG_TRACE_COMPILED = 1 << 14;
const JIT_DEBUG_TRACE_BYTECODE = 1 << 18;
const JIT_DEBUG_IR_FINAL = 1 << 25;
const JIT_DEBUG = JIT_DEBUG_TRACE_START | JIT_DEBUG_TRACE_STOP | JIT_DEBUG_TRACE_COMPILED
    | JIT_DEBUG_TRACE_BYTECODE | JIT_DEBUG_IR_FINAL;

/**
 * The hot counters of detector runs, as named profiles, fixed so that two
 * runs (phase 1's inventory, later checks) compile the same loops:
 * 'default' is PHP 8.5's defaults, written out (what a host runs); 'eager'
 * makes loops hot after 7 iterations instead of 61, so that the loops of
 * the tests' short programs get compiled. A different threshold traces
 * other paths, and each profile flags loops the other does not
 * (tests/jit/tracing-jit-inventory.md): the inventory runs both.
 */
const HOT_COUNTER_PROFILES = [
    'default' => [
        'opcache.jit_hot_loop' => '61',
        'opcache.jit_hot_func' => '127',
        'opcache.jit_hot_return' => '8',
        'opcache.jit_hot_side_exit' => '8',
        'opcache.jit_max_root_traces' => '1024',
        'opcache.jit_max_side_traces' => '128',
    ],
    'eager' => [
        'opcache.jit_hot_loop' => '7',
        'opcache.jit_hot_func' => '127',
        'opcache.jit_hot_return' => '8',
        'opcache.jit_hot_side_exit' => '8',
        'opcache.jit_max_root_traces' => '1024',
        'opcache.jit_max_side_traces' => '128',
    ],
];

/** sizeof(zend_op) on 64-bit PHP: the IP moves in steps of it */
const ZEND_OP_BYTES = 32;

/** the first frame slot of a CV: ZEND_CALL_FRAME_SLOT (sizeof(zend_execute_data) / 16) * 16 */
const FIRST_SLOT_OFFSET = 0x50;

/**
 * php options of the acceptance criteria's "JIT options" with
 * opcache.jit=$mode
 *
 * @return list<string>
 */
function jitOptions(string $mode): array
{
    return [
        '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=64M',
        '-d', 'opcache.file_update_protection=0', '-d', "opcache.jit=$mode",
    ];
}

/**
 * php options of a detector run: the JIT options, the hot counters of
 * $profile (HOT_COUNTER_PROFILES) and the jit_debug report on stderr
 *
 * @return list<string>
 */
function detectorOptions(string $mode, string $profile = 'default'): array
{
    $options = jitOptions($mode);
    foreach (HOT_COUNTER_PROFILES[$profile] as $name => $value) {
        array_push($options, '-d', "$name=$value");
    }
    array_push($options, '-d', 'opcache.jit_debug=' . JIT_DEBUG);
    return $options;
}

/**
 * The traces the JIT compiled, from the jit_debug report in the file
 * $dumpFile (one PHP process's stderr; the program's own output may be in
 * between). A trace counts once "---- TRACE n compiled" follows its IR.
 *
 * @return \Generator<array{number: int, kind: string, function: string, file: string, line: int,
 *     stop: string, bytecode: list<string>, ir: list<string>}>
 */
function compiledTraces(string $dumpFile): \Generator
{
    $handle = fopen($dumpFile, 'r');
    $trace = null;
    $phase = '';
    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\n");
        if (preg_match('/^---- TRACE (\d+) start \(([^)]*)\) (.*)\(\) (.*):(\d+)$/', $line, $match) === 1) {
            // a new recording replaces one that was aborted
            $trace = [
                'number' => (int) $match[1], 'kind' => $match[2], 'function' => $match[3],
                'file' => $match[4], 'line' => (int) $match[5], 'stop' => '', 'bytecode' => [], 'ir' => [],
            ];
            $phase = 'recording';
            continue;
        }
        if ($trace === null) {
            continue;
        }
        if ($phase === 'recording' && preg_match('/^\d{4,} |^ +[<>;]/', $line) === 1) {
            $trace['bytecode'][] = $line;
        } elseif (preg_match('/^---- TRACE (\d+) stop \((.*)\)$/', $line, $match) === 1) {
            $trace['stop'] = $match[2];
            $phase = (int) $match[1] === $trace['number'] ? 'stopped' : '';
        } elseif ($phase === 'stopped' && preg_match('/^TRACE-(\d+)\$.*: ; final$/', $line, $match) === 1) {
            $phase = (int) $match[1] === $trace['number'] ? 'ir' : '';
        } elseif ($phase === 'ir') {
            if ($line === '}') {
                $phase = 'compiling';
            } elseif ($line !== '{') {
                $trace['ir'][] = $line;
            }
        } elseif ($phase === 'compiling' && $line === "---- TRACE {$trace['number']} compiled") {
            yield $trace;
            $trace = null;
            $phase = '';
        }
    }
    fclose($handle);
}

/** the stop reasons of a loop trace (zend_jit_internal.h ZEND_JIT_TRACE_STOP) */
function isLoopTrace(array $trace): bool
{
    return \in_array($trace['stop'], ['loop', 'recursive call', 'recursive return'], true);
}

/**
 * The final IR of a trace: instructions by ref (dump order kept) and
 * constants.
 *
 * @param list<string> $lines
 * @return array{list<array{ref: int, op: string, operands: list<array{kind: string, ref: int, annotations: string}>,
 *     number: ?int, annotations: string, bind: ?int, type: string}>, array<int, string>}
 */
function parseIr(array $lines): array
{
    $instructions = [];
    $constants = [];
    foreach ($lines as $line) {
        if (preg_match('/^\t\w+ c_(\d+) = (.*);$/', $line, $match) === 1) {
            $constants[(int) $match[1]] = $match[2];
            continue;
        }
        $pattern = '/^\t(?:(\w+) d_(\d+)((?: \{[^}]*\})*)(?:, l_\d+)? = |l_(\d+) = )([A-Z_0-9]+)(?:\/\d+)?'
            . '(?:\((.*?)\))?;(.*)$/';
        if (preg_match($pattern, $line, $match) !== 1) {
            continue;
        }
        $operands = [];
        $number = null;
        foreach (explode(', ', $match[6]) as $operand) {
            if (preg_match('/^([ldc])_(\d+)(.*)$/', $operand, $operandMatch) === 1) {
                $operands[] = ['kind' => $operandMatch[1], 'ref' => (int) $operandMatch[2],
                    'annotations' => $operandMatch[3]];
            } elseif (preg_match('/^\d+$/', $operand) === 1) {
                $number = (int) $operand;
            }
        }
        $instructions[] = [
            'ref' => (int) ($match[2] !== '' ? $match[2] : $match[4]),
            'op' => $match[5],
            'operands' => $operands,
            'number' => $number,
            'annotations' => $match[3],
            'bind' => preg_match('/# BIND\(0x([0-9a-f]+)\);/', $match[7], $bindMatch) === 1
                ? (int) hexdec($bindMatch[1]) : null,
            'type' => $match[1],
        ];
    }
    return [$instructions, $constants];
}

/**
 * The detector's verdict on a compiled trace, or null when it is not a
 * loop trace (see the file comment). $handlerOpcodes, $knownHandlers: see
 * IpUses::of.
 *
 * @return array{flagged: bool, interruptCheck: bool, snapshot: bool, registers: list<string>,
 *     ipUses: list<array>}|null
 */
function loopTraceVerdict(
    array $trace,
    array $process,
    ?array $handlerOpcodes = null,
    array $knownHandlers = [],
): ?array {
    if (!isLoopTrace($trace)) {
        return null;
    }
    [$instructions, $constants] = parseIr($trace['ir']);
    $byRef = [];
    foreach ($instructions as $instruction) {
        $byRef[$instruction['ref']] = $instruction;
    }
    $verdict = ['flagged' => false, 'interruptCheck' => false, 'snapshot' => false, 'registers' => [],
        'ipUses' => []];
    // the trace's loop: its first LOOP_BEGIN (zend_jit_trace_begin_loop, before any loop of its body)
    $loopBegin = null;
    foreach ($instructions as $instruction) {
        if ($instruction['op'] === 'LOOP_BEGIN') {
            $loopBegin = $instruction;
            break;
        }
    }
    $backEdge = $byRef[$loopBegin['operands'][1]['ref'] ?? 0] ?? null;
    if ($backEdge === null || $backEdge['op'] !== 'LOOP_END') {
        return $verdict;
    }
    // the interrupt check (zend_jit_check_timeout): GUARD_NOT(LOAD_U8(&EG(vm_interrupt)), exit)
    $guard = $byRef[$backEdge['operands'][0]['ref']] ?? null;
    $condition = $guard === null ? null : ($byRef[$guard['operands'][1]['ref'] ?? 0] ?? null);
    if ($guard['op'] !== 'GUARD_NOT' || $condition['op'] !== 'LOAD' || $condition['type'] !== 'uint8_t') {
        return $verdict;
    }
    $verdict['interruptCheck'] = true;
    $verdict['snapshot'] = $byRef[$guard['operands'][0]['ref']]['op'] === 'SNAPSHOT';
    // the values the loop carries in registers: PHIs of its header
    foreach ($instructions as $instruction) {
        if (
            $instruction['op'] !== 'PHI' || $instruction['operands'][0]['ref'] !== $loopBegin['ref']
            || preg_match('/\{%(\w+)\}/', $instruction['annotations'], $register) !== 1
        ) {
            continue;
        }
        // stored to its frame slot: spilled (":store") to the slot it is bound to
        $fromBackEdge = $byRef[end($instruction['operands'])['ref']] ?? null;
        if (
            $fromBackEdge !== null && $instruction['bind'] !== null && $fromBackEdge['bind'] === $instruction['bind']
            && str_contains($fromBackEdge['annotations'], ':store}')
        ) {
            continue;
        }
        $verdict['registers'][] = slotName($instruction['bind'], $trace['bytecode']) . " in %{$register[1]}";
    }
    $verdict['flagged'] = !$verdict['snapshot'] && $verdict['registers'] !== [];
    $verdict['ipUses'] = IpUses::of(
        $instructions,
        $constants,
        $trace,
        $process,
        $condition,
        $handlerOpcodes,
        $knownHandlers,
    );
    return $verdict;
}

/**
 * What the JIT report $dumpFile tells about its process as a whole:
 *   - the IP register: the one its traces set from itself plus or minus an
 *     offset (zend_jit_set_ip; r15 under x86-64's hybrid VM, r13 under its
 *     tailcall VM);
 *   - the VM handlers: the functions its traces call with no argument and
 *     no result right after setting the IP (zend_jit_trace_handler:
 *     zend_jit_set_ip, then the handler), at a root trace's entry, or right
 *     after another handler call and its exception check, where the IP
 *     already points at the opline and zend_jit_set_ip emits nothing. The
 *     JIT's other calls without arguments (zend_jit_leave_func_helper and
 *     the like, and calls into a new frame) come after a branch, a store or
 *     a set of the frame register, never in those places.
 *
 * @return array{ipRegister: ?int, handlers: array<string, true>} handlers
 *     as the IR prints them ("func *0x55d0d28bd410(): int32_t")
 */
function processFacts(string $dumpFile): array
{
    $calls = [];
    $relativeSets = [];
    foreach (compiledTraces($dumpFile) as $trace) {
        [$instructions, $constants] = parseIr($trace['ir']);
        $byRef = [];
        foreach ($instructions as $instruction) {
            $byRef[$instruction['ref']] = $instruction;
        }
        $callIndexes = [];
        foreach ($instructions as $instruction) {
            if ($instruction['op'] === 'RSTORE' && ($instruction['operands'][1]['kind'] ?? '') === 'd') {
                $sum = $byRef[$instruction['operands'][1]['ref']] ?? null;
                $base = $byRef[$sum['operands'][0]['ref'] ?? 0] ?? null;
                if (
                    \in_array($sum['op'] ?? '', ['ADD', 'SUB'], true) && ($base['op'] ?? '') === 'RLOAD'
                    && $base['number'] === $instruction['number']
                ) {
                    $relativeSets[$instruction['number']] = ($relativeSets[$instruction['number']] ?? 0) + 1;
                }
            }
            $callee = $instruction['operands'][1] ?? null;
            if (
                $instruction['op'] !== 'CALL' || $instruction['type'] !== '' || \count($instruction['operands']) !== 2
                || $callee['kind'] !== 'c' || !str_starts_with($constants[$callee['ref']] ?? '', 'func ')
            ) {
                continue;
            }
            // what comes before it, past an exception check (LOAD and GUARD_NOT) after a call
            $before = $byRef[$instruction['operands'][0]['ref']] ?? ['op' => 'START', 'operands' => []];
            if ($before['op'] === 'GUARD_NOT') {
                $load = $byRef[$before['operands'][0]['ref']] ?? null;
                $call = $byRef[$load['operands'][0]['ref'] ?? 0] ?? null;
                $before = ($load['op'] ?? '') === 'LOAD' && ($call['op'] ?? '') === 'CALL' ? $call : $before;
            }
            $atEntry = $before['op'] === 'START' || ($before['op'] === 'STORE'
                && ($byRef[$before['operands'][0]['ref']]['op'] ?? 'START') === 'START');
            $calls[] = [
                $constants[$callee['ref']],
                match (true) {
                    $atEntry && !str_starts_with($trace['kind'], 'side trace') => ['entry'],
                    $before['op'] === 'RSTORE' => ['set', $before['number']],
                    isset($callIndexes[$before['ref'] ?? 0]) => ['call', $callIndexes[$before['ref']]],
                    default => ['other'],
                },
            ];
            $callIndexes[$instruction['ref']] = \count($calls) - 1;
        }
    }
    arsort($relativeSets);
    $ipRegister = array_key_first($relativeSets);
    $handlers = [];
    do {
        $added = false;
        foreach ($calls as [$function, $before]) {
            $isHandler = $before[0] === 'entry' || ($before[0] === 'set' && $before[1] === $ipRegister)
                || ($before[0] === 'call' && isset($handlers[$calls[$before[1]][0]]));
            if ($isHandler && !isset($handlers[$function])) {
                $handlers[$function] = true;
                $added = true;
            }
        }
    } while ($added);
    return ['ipRegister' => $ipRegister, 'handlers' => $handlers];
}

/**
 * The detector's verdicts on the loop traces of the JIT report $dumpFile:
 * each its trace (without IR and bytecode) and verdict (loopTraceVerdict),
 * with 'operations' for its uses of the IP (IpUses) as text, the first the
 * one that set the flag: "COALESCE at 0005 (VM handler call)", "FE_FETCH_R
 * at 0004 (EX(opline) from the IP)", "COALESCE at 0010 in
 * LuaPhp\Runtime\LuaTable::partCount (VM handler call)"; after the first,
 * the same opcode, function and kind in one item. The uses of a loop
 * trace that are not placed at first are placed in search mode, with the
 * handlers' opcodes all the process's traces placed (one handler function
 * per opcode and operand types). A handler call still not placed is named
 * by that opcode if they agree: "FETCH_CLASS_CONSTANT (VM handler call at
 * an opline not found)"; else "an opline not found (VM handler call)". A
 * use at an opline the trace does not run (an exit's) reads "opline 0012,
 * not in the trace (IP set from the IP)". 'unplacedUses' counts the uses
 * not placed: they sort last, so with any the first could be another.
 * $knownHandlers: the opcodes other processes placed handlers at, by
 * handlerKey (IpUses::of); $placedHandlers receives this process's.
 *
 * @return list<array{trace: array, verdict: array}>
 */
function loopTraceVerdicts(
    string $dumpFile,
    array $knownHandlers = [],
    ?array &$placedHandlers = null,
): array {
    $process = processFacts($dumpFile);
    $results = [];
    $handlerOpcodes = [];
    $unplacedTraces = [];
    foreach (compiledTraces($dumpFile) as $trace) {
        $verdict = loopTraceVerdict($trace, $process);
        if ($verdict === null) {
            [$instructions, $constants] = parseIr($trace['ir']);
            $uses = IpUses::of($instructions, $constants, $trace, $process, []);
        } else {
            $uses = $verdict['ipUses'];
        }
        foreach ($uses as $use) {
            if ($use['handler'] !== null && $use['opcode'] !== null) {
                $handlerOpcodes[$use['handler']][$use['opcode']] = true;
            }
        }
        if ($verdict === null) {
            continue;
        }
        if (\in_array(null, array_column($verdict['ipUses'], 'number'), true)) {
            $unplacedTraces[\count($results)] = true;
        }
        unset($trace['ir'], $trace['bytecode']);
        $results[] = ['trace' => $trace, 'verdict' => $verdict];
    }
    if ($unplacedTraces !== []) {
        // search mode, once the process's handlers are known; the report is read again to keep memory low
        $loopIndex = 0;
        foreach (compiledTraces($dumpFile) as $trace) {
            if (!isLoopTrace($trace)) {
                continue;
            }
            if (isset($unplacedTraces[$loopIndex])) {
                $verdict = loopTraceVerdict($trace, $process, $handlerOpcodes, $knownHandlers);
                $results[$loopIndex]['verdict']['ipUses'] = $verdict['ipUses'];
            }
            $loopIndex++;
        }
    }
    foreach ($results as $result) {
        foreach ($result['verdict']['ipUses'] as $use) {
            if ($use['handler'] !== null && $use['opcode'] !== null) {
                $handlerOpcodes[$use['handler']][$use['opcode']] = true;
            }
        }
    }
    $placedHandlers = [];
    foreach ($handlerOpcodes as $handler => $opcodes) {
        $placedHandlers[handlerKey($handler)] = ($placedHandlers[handlerKey($handler)] ?? []) + $opcodes;
    }
    foreach ($results as $index => $result) {
        $groups = [];
        foreach ($result['verdict']['ipUses'] as $useIndex => $use) {
            $opcodes = $handlerOpcodes[$use['handler'] ?? ''] ?? [];
            $opcodes = \count($opcodes) === 1 ? $opcodes : ($knownHandlers[handlerKey($use['handler'] ?? '')] ?? []);
            // the first use alone: it set the flag
            $group = $useIndex === 0 ? 'first: ' : '';
            $in = $use['function'] === null ? '' : " in {$use['function']}";
            if ($use['opcode'] !== null) {
                $groups["$group{$use['opcode']} at %s$in ({$use['kind']})"][] = sprintf('%04d', $use['number']);
            } elseif ($use['number'] !== null) {
                $groups["{$group}opline %s$in, not in the trace ({$use['kind']})"][] = sprintf('%04d', $use['number']);
            } elseif ($use['handler'] !== null && \count($opcodes) === 1) {
                $opcode = array_key_first($opcodes);
                $groups["$group$opcode ({$use['kind']} at an opline not found)"] = [];
            } else {
                $groups["{$group}an opline not found ({$use['kind']})"] = [];
            }
        }
        $operations = [];
        foreach ($groups as $format => $numbers) {
            $operations[] = sprintf(preg_replace('/^first: /', '', $format), implode(', ', array_unique($numbers)));
        }
        $results[$index]['verdict']['operations'] = $operations;
        // uses not placed sort last: one of them may come before the first one placed
        $results[$index]['verdict']['unplacedUses'] = \count(array_keys(
            array_column($result['verdict']['ipUses'], 'number'),
            null,
            true,
        ));
        unset($results[$index]['verdict']['ipUses']);
    }
    return $results;
}

/** "CV2($index)" for the frame slot at byte $offset (BIND), from the names in the trace's bytecode */
function slotName(?int $offset, array $bytecode): string
{
    if ($offset === null) {
        return 'an unbound value';
    }
    $variable = intdiv($offset - FIRST_SLOT_OFFSET, 16);
    foreach ($bytecode as $line) {
        if (preg_match('/\bCV' . $variable . '\((\$[^)]*)\)/', $line, $match) === 1) {
            return "CV$variable({$match[1]})";
        }
    }
    return sprintf('slot 0x%x', $offset);
}

/**
 * The operations that made a trace keep track of its IP. php-src's
 * zend_jit_use_last_valid_opline sets ZEND_JIT_TRACE_USES_INITIAL_IP
 * whenever the trace's code uses the IP it knows (the trace's first
 * opline, or the last one it set), and a loop trace with that flag gets the
 * interrupt stub. Those uses show in the final IR as
 *   - a call to a VM handler (zend_jit_trace_handler, for an opcode without
 *     inline code; the process's handlers come from processFacts): a CALL
 *     of it with no argument, after an RSTORE of the IP unless the IP
 *     already points at the opline;
 *   - EX(opline) stored from the IP (jit_SET_EX_OPLINE): a STORE to the
 *     frame register itself of an RLOAD of the IP;
 *   - the IP set from itself plus or minus an offset (zend_jit_set_ip),
 *     other than for a handler call or the loop's own set back to its first
 *     opline before its interrupt check (there because of the flag).
 * Each is named by the opline the IP points to there, followed through the
 * trace's bytecode: a root trace starts at its first opline, a side trace
 * at an IP not known; a set from the IP moves it by whole zend_ops in the
 * same function (to an opline of the trace, or one it does not run, as an
 * exit's); a handler leaves it at the next opline of the trace; at a MERGE
 * every path must agree. An address (where the IP was set to a constant)
 * is placed by an anchor, the address of a function's opline 0 (the IP
 * the loop sets before its interrupt check gives the loop's own function's;
 * an opline in several calls of a function goes by rank: the n-th set of
 * the IP to it to the n-th such opline of the trace); for a handler call,
 * by the check of the next opline after it when one opline of the trace
 * fits; else in search mode, given the opcodes the handlers were placed at
 * (by the process's traces, else by other processes', handlerKey), by
 * trying each opline of the trace for it: a placement may add no
 * inconsistency (a handler call at an opline the trace does not run or at
 * one with another handler call, one handler function at two opcodes, or
 * at another opcode than it was placed at elsewhere), and the one that
 * places the most handler calls (ties: the one between the oplines of the
 * placed handler calls before and after it in the IR) is taken and anchors
 * its function; addresses not placed are tried again after each placement.
 * Where the IR does not show a use (a set to the opline the IP already
 * holds emits nothing), it is not listed; where it cannot be placed, it
 * says so.
 */
final class IpUses
{
    /** @var array<int, array> */
    private array $byRef = [];
    /** @var array<int, int> ref => the instruction's place in the IR */
    private array $irOrder = [];
    /** @var array<int, array<string, true>> by handlerKey: the opcodes other processes placed handlers at */
    private array $knownHandlers = [];
    /** @var list<array{number: int, level: int, opcode: string, function: string}> */
    private array $entries = [];
    private ?int $ipRegister = null;
    /**
     * Positions of the IP: ['entry', index into $entries], ['untraced',
     * index, number] (opline number of the call entry index is in, which
     * the trace does not run), ['address', address], or null (not known).
     *
     * @var array<int, ?array> by RSTORE
     */
    private array $ipAfter = [];
    /** @var array<int, ?array> by control instruction */
    private array $ipAt = [];
    private bool $ipKnownAtStart;
    /**
     * Where a function's oplines are: [the function, the address of its
     * opline 0]. From the IP the loop sets before its interrupt check (its
     * first opline), and from each address search mode placed.
     *
     * @var list<array{string, int}>
     */
    private array $anchors = [];

    /**
     * @param list<array> $instructions the trace's final IR (parseIr)
     * @param array<int, string> $constants
     * @param array $trace the trace (compiledTraces)
     * @param array{ipRegister: ?int, handlers: array<string, true>} $process processFacts
     * @param array $interruptCheckLoad the LOAD of EG(vm_interrupt) that ends the loop
     */
    private function __construct(
        private array $instructions,
        private array $constants,
        array $trace,
        private array $process,
        private array $interruptCheckLoad,
    ) {
        foreach ($instructions as $order => $instruction) {
            $this->byRef[$instruction['ref']] = $instruction;
            $this->irOrder[$instruction['ref']] = $order;
        }
        $this->entries = bytecodeEntries($trace['bytecode'], $trace['function']);
        // a root trace starts knowing its IP (its first opline), a side trace not (zend_jit_trace.c:4181-4189)
        $this->ipKnownAtStart = !str_starts_with($trace['kind'], 'side trace');
        // the IP register: the process's, else the one stored into EX(opline)
        $this->ipRegister = $process['ipRegister'];
        foreach ($instructions as $instruction) {
            $value = $this->ipRegister === null ? $this->exOplineValue($instruction) : null;
            if ($value !== null) {
                $this->ipRegister = $value['number'];
            }
        }
        $loopEnd = $this->byRef[$interruptCheckLoad['operands'][0]['ref'] ?? 0] ?? null;
        $firstOplineAddress = $this->isIpStore($loopEnd) && $loopEnd['operands'][1]['kind'] === 'c'
            ? constantValue($constants, $loopEnd['operands'][1]['ref']) : null;
        if ($firstOplineAddress !== null && $this->entries !== []) {
            $this->anchors[] = [
                $this->entries[0]['function'],
                $firstOplineAddress - $this->entries[0]['number'] * ZEND_OP_BYTES,
            ];
        }
    }

    /**
     * The uses of the IP, in trace order (the order the trace compiler
     * emits them: the first set the flag, the ones after it may exist only
     * because of it, as an IP a handler call left behind), those it could
     * not place last: each its kind ("VM handler call", "EX(opline) from
     * the IP", "IP set from the IP"); its opline's number (null when not
     * placed), opcode (null also when the trace does not run it) and
     * function (null: the trace's own; else the function the trace inlines);
     * and for a handler call, the handler (its function constant).
     * $handlerOpcodes (handler => [opcode => true], from the process's
     * traces) turns on search mode; $knownHandlers (handlerKey => [opcode
     * => true], from other processes) names the handlers this process did
     * not place.
     *
     * @return list<array{kind: string, number: ?int, opcode: ?string, function: ?string, handler: ?string}>
     */
    public static function of(
        array $instructions,
        array $constants,
        array $trace,
        array $process,
        array $interruptCheckLoad,
        ?array $handlerOpcodes = null,
        array $knownHandlers = [],
    ): array {
        $uses = new self($instructions, $constants, $trace, $process, $interruptCheckLoad);
        if ($uses->ipRegister === null || $uses->entries === []) {
            return [];
        }
        $uses->knownHandlers = $knownHandlers;
        if ($handlerOpcodes !== null) {
            $uses->placeAddresses($handlerOpcodes);
        }
        $found = $uses->found();
        $traceOrder = static fn (array $use): int => ($use[0][0] ?? '') === 'entry' ? $use[0][1] : PHP_INT_MAX;
        usort($found, static fn (array $a, array $b): int => $traceOrder($a) <=> $traceOrder($b));
        $items = [];
        foreach ($found as [$position, $kind, $handler]) {
            $entry = \in_array($position[0] ?? '', ['entry', 'untraced'], true) ? $uses->entries[$position[1]] : null;
            $inlined = $entry !== null && $entry['level'] > $uses->entries[0]['level'];
            $items[] = [
                'kind' => $kind,
                'number' => $position === null || $entry === null ? null : ($position[2] ?? $entry['number']),
                'opcode' => ($position[0] ?? '') === 'entry' ? $entry['opcode'] : null,
                'function' => $inlined ? $entry['function'] : null,
                'handler' => $handler,
            ];
        }
        return $items;
    }

    /**
     * Every use of the IP with where it points: [position, kind, handler,
     * its instruction's ref]
     *
     * @return list<array{?array, string, ?string, int}>
     */
    private function found(): array
    {
        $loopEnd = $this->interruptCheckLoad['operands'][0]['ref'] ?? 0;
        $handlerSets = [];
        $found = [];
        foreach ($this->instructions as $instruction) {
            if ($this->isHandlerCall($instruction)) {
                $handlerSets[$instruction['operands'][0]['ref']] = true;
                $position = $this->ipAt($instruction['operands'][0]['ref'], $instruction);
                $handler = $this->constants[$instruction['operands'][1]['ref']];
                $found[] = [$position, 'VM handler call', $handler, $instruction['ref']];
                continue;
            }
            $value = $this->exOplineValue($instruction);
            if ($value !== null && $value['number'] === $this->ipRegister) {
                $position = $this->ipAt($value['operands'][0]['ref']);
                $found[] = [$position, 'EX(opline) from the IP', null, $instruction['ref']];
            }
        }
        foreach ($this->instructions as $instruction) {
            if (
                $this->relativeIpSet($instruction) !== null && !isset($handlerSets[$instruction['ref']])
                && $instruction['ref'] !== $loopEnd
            ) {
                $found[] = [$this->ipAt($instruction['ref']), 'IP set from the IP', null, $instruction['ref']];
            }
        }
        return $found;
    }

    /**
     * For RSTORE(ADD|SUB(RLOAD(ip), offset), ip), zend_jit_set_ip from the
     * IP it holds: [the RLOAD, the offset in bytes]; else null (an RSTORE
     * of an address, or of a call frame: the IP register also holds the
     * frame of a call being set up)
     *
     * @return array{array, int}|null
     */
    private function relativeIpSet(array $instruction): ?array
    {
        if (!$this->isIpStore($instruction) || $instruction['operands'][1]['kind'] !== 'd') {
            return null;
        }
        $sum = $this->byRef[$instruction['operands'][1]['ref']] ?? null;
        $base = $this->byRef[$sum['operands'][0]['ref'] ?? 0] ?? null;
        $offsetOperand = $sum['operands'][1] ?? ['kind' => '', 'ref' => 0];
        $offset = $offsetOperand['kind'] === 'c' ? constantValue($this->constants, $offsetOperand['ref']) : null;
        if (
            !\in_array($sum['op'] ?? '', ['ADD', 'SUB'], true) || ($base['op'] ?? '') !== 'RLOAD'
            || $base['number'] !== $this->ipRegister || $offset === null
        ) {
            return null;
        }
        return [$base, $sum['op'] === 'ADD' ? $offset : -$offset];
    }

    /**
     * Search mode: each address the IP is set to that is not placed yet, if
     * a handler call follows it, is tried at each opline of the trace (of
     * the handler's opcode, when the process's traces placed the handler).
     * A placement must add no inconsistency and place more handler calls;
     * of those, the one that places the most is kept if no other does as
     * many.
     *
     * @param array<string, array<string, true>> $handlerOpcodes
     */
    private function placeAddresses(array $handlerOpcodes): void
    {
        do {
            $changed = false;
            [$inconsistencies, $placed] = $this->handlerCallsChecked($handlerOpcodes);
            foreach ($this->instructions as $call) {
                $set = $this->byRef[$call['operands'][0]['ref'] ?? 0] ?? null;
                if (!$this->isHandlerCall($call) || !$this->isIpStore($set) || $set['operands'][1]['kind'] !== 'c') {
                    continue;
                }
                $address = constantValue($this->constants, $set['operands'][1]['ref']);
                if ($address === null || ($this->ipAfterSet($set, $call)[0] ?? '') !== 'address') {
                    continue;
                }
                $opcodes = $this->handlerOpcodes($handlerOpcodes, $this->constants[$call['operands'][1]['ref']]);
                // each opline of a function of the trace: [function, number] => entries
                $oplines = [];
                foreach ($this->entries as $index => $entry) {
                    if (\count($opcodes) !== 1 || isset($opcodes[$entry['opcode']])) {
                        $oplines[$entry['function'] . "\n" . $entry['number']][] = $index;
                    }
                }
                $placedBy = [];
                foreach ($oplines as $key => $indexes) {
                    $entry = $this->entries[$indexes[0]];
                    $this->anchors[] = [$entry['function'], $address - $entry['number'] * ZEND_OP_BYTES];
                    $this->forgetPositions();
                    [$inconsistenciesThen, $placedThen] = $this->handlerCallsChecked($handlerOpcodes);
                    if ($inconsistenciesThen <= $inconsistencies && $placedThen > $placed) {
                        $placedBy[$key] = $placedThen;
                    }
                    array_pop($this->anchors);
                }
                $this->forgetPositions();
                arsort($placedBy);
                $best = array_keys($placedBy, reset($placedBy), true);
                $best = \count($best) > 1 ? $this->tieBreak($best, $oplines, $call) : $best;
                if (\count($best) === 1) {
                    $entry = $this->entries[$oplines[$best[0]][0]];
                    $this->anchors[] = [$entry['function'], $address - $entry['number'] * ZEND_OP_BYTES];
                    $changed = true;
                }
                $this->forgetPositions();
                [$inconsistencies, $placed] = $this->handlerCallsChecked($handlerOpcodes);
            }
            // a placement can rule out another's candidates: try those not placed again
        } while ($changed);
    }

    /**
     * Of the oplines $candidates (keys of $oplines: function and number =>
     * entries) that tie for the handler call $call, those with an entry
     * between the oplines of the placed handler calls before and after it
     * in the IR (the trace compiler emits handler calls in trace order), if
     * any
     *
     * @param list<string> $candidates
     * @param array<string, list<int>> $oplines
     * @return list<string>
     */
    private function tieBreak(array $candidates, array $oplines, array $call): array
    {
        $after = PHP_INT_MIN;
        $before = PHP_INT_MAX;
        foreach ($this->found() as [$position, , $handler, $ref]) {
            if ($handler === null || ($position[0] ?? '') !== 'entry' || $ref === $call['ref']) {
                continue;
            }
            if ($this->irOrder[$ref] < $this->irOrder[$call['ref']]) {
                $after = max($after, $position[1]);
            } else {
                $before = min($before, $position[1]);
            }
        }
        $between = array_values(array_filter(
            $candidates,
            static fn (string $key): bool => array_filter(
                $oplines[$key],
                static fn (int $index): bool => $index > $after && $index < $before,
            ) !== [],
        ));
        return $between ?: $candidates;
    }

    /**
     * The opcodes the handler $handler was placed at: by this process's
     * traces ($handlerOpcodes), else by other processes ($knownHandlers)
     *
     * @return array<string, true>
     */
    private function handlerOpcodes(array $handlerOpcodes, string $handler): array
    {
        $opcodes = $handlerOpcodes[$handler] ?? [];
        return \count($opcodes) === 1 ? $opcodes : ($this->knownHandlers[handlerKey($handler)] ?? []);
    }

    private function forgetPositions(): void
    {
        $this->ipAt = [];
        $this->ipAfter = [];
    }

    /**
     * [how many of the trace's handler calls cannot be where the IP points
     * (at an opline the trace does not run, at an opline with another
     * handler call, at a second opcode of one handler function, or at
     * another opcode than the process's traces placed it at), how many are
     * placed at an opline of the trace]
     *
     * @param array<string, array<string, true>> $handlerOpcodes
     * @return array{int, int}
     */
    private function handlerCallsChecked(array $handlerOpcodes): array
    {
        $inconsistencies = 0;
        $placed = 0;
        $opcodes = [];
        $callsAt = [];
        foreach ($this->found() as [$position, , $handler]) {
            if ($handler === null || $position === null) {
                continue;
            }
            if ($position[0] === 'untraced') {
                $inconsistencies++;
            } elseif ($position[0] === 'entry') {
                $placed++;
                // the trace compiler emits an opline of the trace once: one handler call at most
                $inconsistencies += isset($callsAt[$position[1]]) ? 1 : 0;
                $callsAt[$position[1]] = true;
                $opcode = $this->entries[$position[1]]['opcode'];
                $opcodes[$handler][$opcode] = true;
                $known = $this->handlerOpcodes($handlerOpcodes, $handler);
                $inconsistencies += \count($known) === 1 && !isset($known[$opcode]) ? 1 : 0;
            }
        }
        foreach ($opcodes as $opcodesOfHandler) {
            $inconsistencies += \count($opcodesOfHandler) - 1;
        }
        return [$inconsistencies, $placed];
    }

    /** a CALL of a VM handler (no argument, no result) */
    private function isHandlerCall(array $instruction): bool
    {
        $callee = $instruction['operands'][1] ?? null;
        return $instruction['op'] === 'CALL' && $instruction['type'] === '' && \count($instruction['operands']) === 2
            && $callee['kind'] === 'c' && isset($this->process['handlers'][$this->constants[$callee['ref']] ?? '']);
    }

    /** for STORE(RLOAD(fp), RLOAD(r)), EX(opline) = r: the RLOAD of r, else null */
    private function exOplineValue(array $instruction): ?array
    {
        if ($instruction['op'] !== 'STORE' || \count($instruction['operands']) !== 3) {
            return null;
        }
        $address = $this->byRef[$instruction['operands'][1]['ref']] ?? null;
        $value = $this->byRef[$instruction['operands'][2]['ref']] ?? null;
        return $instruction['operands'][1]['kind'] === 'd' && $instruction['operands'][2]['kind'] === 'd'
            && ($address['op'] ?? '') === 'RLOAD' && ($value['op'] ?? '') === 'RLOAD'
            && $address['number'] !== $value['number'] ? $value : null;
    }

    private function isIpStore(?array $instruction): bool
    {
        return $instruction !== null && $instruction['op'] === 'RSTORE' && $this->ipRegister !== null
            && $instruction['number'] === $this->ipRegister;
    }

    /**
     * Where the IP points after control instruction $ref (a position, see
     * $ipAfter; null also where the paths into a MERGE disagree).
     * $handlerCall: the handler call at whose IP the walk starts (for an
     * address in an inlined function).
     */
    private function ipAt(int $ref, ?array $handlerCall = null): ?array
    {
        if (\array_key_exists($ref, $this->ipAt)) {
            return $this->ipAt[$ref];
        }
        $this->ipAt[$ref] = null;
        $instruction = $this->byRef[$ref] ?? null;
        if ($instruction === null) {
            $position = null;
        } elseif ($instruction['op'] === 'START') {
            $position = $this->ipKnownAtStart ? ['entry', 0] : null;
        } elseif ($this->isIpStore($instruction)) {
            $position = $this->ipAfterSet($instruction, $handlerCall);
        } elseif ($this->isHandlerCall($instruction)) {
            // the handler runs its opline and leaves the IP at the next opline of the trace
            $handler = $this->ipAt($instruction['operands'][0]['ref'], $instruction);
            $position = ($handler[0] ?? '') === 'entry' && isset($this->entries[$handler[1] + 1])
                ? ['entry', $handler[1] + 1] : null;
        } elseif ($instruction['op'] === 'MERGE') {
            $positions = [];
            foreach ($instruction['operands'] as $operand) {
                $positions[] = $operand['kind'] === 'l' ? $this->ipAt($operand['ref']) : null;
            }
            $position = \count(array_unique(array_map('serialize', $positions))) === 1 ? $positions[0] : null;
        } else {
            // LOOP_BEGIN: from the loop's entry, not its back edge
            $control = $instruction['operands'][0] ?? null;
            $position = $control !== null && $control['kind'] === 'l' ? $this->ipAt($control['ref']) : null;
        }
        return $this->ipAt[$ref] = $position;
    }

    /** where the IP points after the RSTORE $set */
    private function ipAfterSet(array $set, ?array $handlerCall): ?array
    {
        if (\array_key_exists($set['ref'], $this->ipAfter)) {
            return $this->ipAfter[$set['ref']];
        }
        $value = $set['operands'][1];
        $position = null;
        if ($value['kind'] === 'c') {
            $address = constantValue($this->constants, $value['ref']);
            $position = $address === null ? null : $this->placeAddress($address, $handlerCall, $set);
        } elseif (($relative = $this->relativeIpSet($set)) !== null) {
            [$base, $offset] = $relative;
            $before = $this->ipAt($base['operands'][0]['ref']);
            if (($before[0] ?? '') === 'address') {
                $position = $this->placeAddress($before[1] + $offset, $handlerCall);
            } elseif ($before !== null && $offset % ZEND_OP_BYTES === 0) {
                $number = ($before[2] ?? $this->entries[$before[1]]['number']) + intdiv($offset, ZEND_OP_BYTES);
                $position = $this->oplineInSameCall($before[1], $number);
            }
        }
        return $this->ipAfter[$set['ref']] = $position;
    }

    /**
     * ['entry', index] for an address of an opline the trace runs in a
     * function an anchor places (in several calls of it, by the rank of
     * $set among the sets of the IP to that address), or for the handler
     * call $handlerCall at an address, the one opline of the trace that the
     * call's check of the next opline fits; else ['address', $address]
     */
    private function placeAddress(int $address, ?array $handlerCall, ?array $set = null): array
    {
        foreach ($this->anchors as [$function, $base]) {
            $offset = $address - $base;
            if ($offset < 0 || $offset % ZEND_OP_BYTES !== 0) {
                continue;
            }
            $number = intdiv($offset, ZEND_OP_BYTES);
            $candidates = array_keys(array_filter(
                $this->entries,
                static fn (array $entry): bool => $entry['function'] === $function && $entry['number'] === $number,
            ));
            if (\count($candidates) === 1) {
                return ['entry', $candidates[0]];
            }
            // the opline in several calls of the function: the n-th set of the IP to it goes to the n-th
            $sets = $set === null ? [] : $this->setsTo($address);
            if ($candidates !== [] && \count($sets) === \count($candidates)) {
                return ['entry', $candidates[array_search($set['ref'], $sets, true)]];
            }
        }
        $nextOpline = $handlerCall === null ? null : $this->nextOplineCheck($handlerCall);
        if ($nextOpline !== null && (($nextOpline - $address) & 0xffffffff) % ZEND_OP_BYTES === 0) {
            // the one opline (of any function of the trace) whose next opline in the trace is that far
            $distance = intdiv(($nextOpline - $address) & 0xffffffff, ZEND_OP_BYTES);
            $candidates = [];
            foreach ($this->entries as $index => $entry) {
                $next = $this->entries[$index + 1] ?? null;
                if (
                    $next !== null && $next['level'] === $entry['level'] && $next['function'] === $entry['function']
                    && $next['number'] - $entry['number'] === $distance
                ) {
                    $candidates[$entry['level'] . ' ' . $entry['function'] . ' ' . $entry['number']] = $index;
                }
            }
            if (\count($candidates) === 1) {
                return ['entry', reset($candidates)];
            }
        }
        return ['address', $address];
    }

    /**
     * The RSTOREs that set the IP to $address, in IR order (refs)
     *
     * @return list<int>
     */
    private function setsTo(int $address): array
    {
        $sets = [];
        foreach ($this->instructions as $instruction) {
            if (
                $this->isIpStore($instruction) && $instruction['operands'][1]['kind'] === 'c'
                && constantValue($this->constants, $instruction['operands'][1]['ref']) === $address
            ) {
                $sets[] = $instruction['ref'];
            }
        }
        return $sets;
    }

    /** the low 32 bits of the next opline the trace checks the IP against after $call, or null */
    private function nextOplineCheck(array $call): ?int
    {
        $after = false;
        $loads = [];
        foreach ($this->instructions as $instruction) {
            if ($instruction === $call) {
                $after = true;
            } elseif (!$after) {
                continue;
            } elseif ($instruction['op'] === 'CALL') {
                return null;
            } elseif ($instruction['op'] === 'RLOAD' && $instruction['number'] === $this->ipRegister) {
                $loads[$instruction['ref']] = $instruction['type'] === 'uint32_t';
            } elseif (
                $instruction['op'] === 'EQ' && ($loads[$instruction['operands'][0]['ref'] ?? 0] ?? false)
                && ($instruction['operands'][1]['kind'] ?? '') === 'c'
            ) {
                return constantValue($this->constants, $instruction['operands'][1]['ref']);
            }
        }
        return null;
    }

    /**
     * Opline $number of the call entry $from is in: ['entry', index] of the
     * nearest such entry of that call (after $from first), else
     * ['untraced', $from, $number]
     */
    private function oplineInSameCall(int $from, int $number): array
    {
        $level = $this->entries[$from]['level'];
        foreach ([1, -1] as $direction) {
            for ($index = $from; isset($this->entries[$index]); $index += $direction) {
                $entry = $this->entries[$index];
                if ($entry['level'] < $level) {
                    break;
                }
                if ($entry['level'] === $level && $entry['number'] === $number) {
                    return ['entry', $index];
                }
            }
        }
        return ['untraced', $from, $number];
    }
}

/**
 * A key that names a VM handler in every process: the low 21 bits of its
 * address (the IR prints it: "func *0x55d0d28bd410(): int32_t"). The PHP
 * binary is loaded at an address aligned to 2 MB here, so ASLR leaves those
 * bits alone: in 41 processes, 46 handlers each kept one key, and no key
 * named two opcodes. A key that does name two is not used (IpUses keeps
 * only keys with one opcode).
 */
function handlerKey(string $handler): int
{
    return preg_match('/0x([0-9a-f]+)/', $handler, $match) === 1 ? (int) hexdec($match[1]) & 0x1fffff : -1;
}

/** the integer value of constant c_$ref ("0x55d0c6974bd8", "3331804184"), or null */
function constantValue(array $constants, int $ref): ?int
{
    $value = $constants[$ref] ?? '';
    if (preg_match('/^0x[0-9a-f]+$/', $value) === 1) {
        return (int) hexdec(substr($value, 2));
    }
    return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
}

/**
 * The oplines of a trace's bytecode, in trace order: number, level (its
 * indentation: deeper in a function the trace inlines), opcode and
 * function. The lines of an opline's further zend_ops (";OP_DATA",
 * ";JMPNZ") are left out.
 *
 * @param list<string> $bytecode
 * @return list<array{number: int, level: int, opcode: string, function: string}>
 */
function bytecodeEntries(array $bytecode, string $traceFunction): array
{
    $entries = [];
    $functions = [$traceFunction];
    foreach ($bytecode as $line) {
        if (preg_match('/^ +(>enter|<back) (.*)$/', $line, $match) === 1) {
            $match[1] === '>enter' ? $functions[] = $match[2] : array_pop($functions);
            continue;
        }
        if (preg_match('/^(\d+)( +)(?:\S+ = )?([A-Z][A-Z0-9_]*)/', $line, $match) === 1) {
            $entries[] = ['number' => (int) $match[1], 'level' => \strlen($match[2]), 'opcode' => $match[3],
                'function' => end($functions) ?: $traceFunction];
        }
    }
    return $entries;
}
