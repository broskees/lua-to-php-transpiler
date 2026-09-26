<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * The limits of an embedded state (GlobalState::setBudget; null limits are
 * unlimited) and what it used since start(). Reaching a limit throws
 * LimitReached, which no Lua code sees.
 *
 * - steps: roughly Lua instructions, counted only by code compiled with
 *   step counting (Emitter::emitChunk $countSteps): a function charges
 *   its size when it is called, a loop its size at each jump back, so the
 *   count is at least the instructions run. Library functions whose work
 *   grows with their input charge in proportion (a step per element, or
 *   per BYTES_PER_STEP bytes), and so may the host (chargeSteps).
 * - memoryBytes: PHP memory growth since start(), checked every
 *   STEPS_PER_CHECK steps, and before a library function builds a result
 *   larger than MemoryLimit::CHECK_ABOVE (MemoryLimit::reserve). Before
 *   giving up, PHP's cycle collector runs (C: an emergency collection,
 *   which runs no finalizers). The step checks also stop at the host's own
 *   memory_limit (less MemoryLimit's reserve), whichever comes first.
 * - seconds: wall-clock time since start(), checked with the memory (and
 *   by checkLimits, after a host function returns: its time counts).
 * - outputBytes: bytes written to the state's output sinks.
 * - coroutines: coroutines alive at once (created, not finished, not
 *   collected), checked when one is created.
 * - callDepth: Lua call levels of a thread (GlobalState::$callDepthLimit):
 *   beyond it a call is Lua's catchable "stack overflow".
 *
 * @internal
 */
final class Budget
{
    /** the steps between two checks of time and memory */
    public const STEPS_PER_CHECK = 10000;

    /** bytes a library function handles per step it charges (string.rep, table.concat, ...) */
    public const BYTES_PER_STEP = 64;

    /**
     * The steps left of the current allowance: once the budget is set,
     * a reference to GlobalState::$stepsLeft, which code compiled with
     * step counting decrements, calling stepsUsedUp when it goes below 0.
     * Untyped: a reference to a typed property costs a type check at every
     * decrement.
     *
     * @var int
     */
    public $stepsLeft = 0;

    /** the steps charged before the current allowance */
    private int $stepsBefore = 0;

    /** the size of the current allowance */
    private int $allowance = 0;

    private int $outputUsed = 0;

    /** memory_get_usage() at start() */
    private int $memoryBase = 0;

    /** memory_get_peak_usage() at start() */
    private int $processPeakAtStart = 0;

    /** the largest memory growth seen at a check */
    private int $peakGrowth = 0;

    /** hrtime(true) at start() */
    private int $startTime = 0;

    /** hrtime(true) when the time is up */
    private int $deadline = PHP_INT_MAX;

    /** @var \WeakMap<Coroutine, true>|null the coroutines counted, while they exist */
    private ?\WeakMap $liveCoroutines = null;

    /**
     * The host's memory_limit is a limit too (an embedded sandbox: its
     * memory is never more than what memory_limit leaves): memory that
     * would not fit below it, less MemoryLimit's reserve, is
     * LimitReached('memory') after a collection, not Lua's "not enough
     * memory". Checked when memory is, so it follows the host's room as
     * it changes (garbage of other states freed meanwhile included).
     */
    public bool $hostMemoryIsLimit = false;

    /** what a coroutine's first resume allocates (its Fiber and the Fiber's VM stack), with room to spare */
    public const FIBER_BYTES = 64 * 1024;

    public function __construct(
        public readonly ?int $steps = null,
        public readonly ?int $memoryBytes = null,
        public readonly ?float $seconds = null,
        public readonly ?int $outputBytes = null,
        public readonly ?int $coroutines = null,
        public readonly ?int $callDepth = null,
    ) {
        $this->start();
    }

    /**
     * Starts a run: steps, time, memory growth and output count from here
     * (the coroutines alive stay counted).
     */
    public function start(): void
    {
        $this->stepsBefore = 0;
        $this->allowance = $this->steps === null ? self::STEPS_PER_CHECK : min(self::STEPS_PER_CHECK, $this->steps);
        $this->stepsLeft = $this->allowance;
        $this->outputUsed = 0;
        $this->memoryBase = memory_get_usage();
        $this->processPeakAtStart = memory_get_peak_usage();
        $this->peakGrowth = 0;
        $this->startTime = hrtime(true);
        $this->deadline = $this->seconds === null ? PHP_INT_MAX : $this->startTime + (int) ($this->seconds * 1e9);
    }

    /**
     * What emitted code calls when the steps left went below 0: the
     * budget's refill, or, in a state without one, no limit again.
     */
    public static function stepsUsedUp(Coroutine $L): void
    {
        $G = $L->globalState;
        if ($G->budget === null) {
            $G->stepsLeft = PHP_INT_MAX;
            return;
        }
        $G->budget->refill();
    }

    /** $steps more steps (time and memory are checked with the next allowance) */
    public function chargeSteps(int $steps): void
    {
        $this->stepsLeft -= $steps;
        if ($this->stepsLeft < 0) {
            $this->refill();
        }
    }

    /**
     * Checks time and memory now, as the steps do every STEPS_PER_CHECK:
     * for PHP code that may have taken long (a host function Lua called).
     */
    public function checkLimits(): void
    {
        if (hrtime(true) > $this->deadline) {
            throw new LimitReached('seconds');
        }
        if ($this->memoryFits(0) && MemoryLimit::fits(0)) {
            return;
        }
        self::collectGarbage();
        if (!$this->memoryFits(0) || !MemoryLimit::fits(0)) {
            throw new LimitReached('memory');
        }
    }

    /** $bytes are about to be written to an output sink */
    public function chargeOutput(int $bytes): void
    {
        if ($this->outputBytes !== null && $this->outputUsed + $bytes > $this->outputBytes) {
            throw new LimitReached('output');  // (nothing of it is written)
        }
        $this->outputUsed += $bytes;
    }

    /**
     * The allowance is used up: the steps limit, then time and memory, and
     * a new allowance (up to STEPS_PER_CHECK, at most what the limit leaves).
     */
    public function refill(): void
    {
        $used = $this->stepsBefore + $this->allowance - $this->stepsLeft;
        if ($this->steps !== null && $used > $this->steps) {
            throw new LimitReached('steps');  // (the counter stays negative: the next check throws again)
        }
        $this->stepsBefore = $used;
        $this->allowance = $this->steps === null ? self::STEPS_PER_CHECK : min(self::STEPS_PER_CHECK, $this->steps - $used);
        $this->stepsLeft = $this->allowance;
        $this->checkLimits();
    }

    /**
     * MemoryLimit::reserve with this budget: $bytes more may be allocated
     * without passing memoryBytes (or the host's room, see
     * $hostMemoryIsLimit), if need be after an emergency collection, else
     * LimitReached('memory'). Also before a coroutine's fiber is created
     * (FIBER_BYTES): many of them can come between two step checks.
     */
    public function reserveMemory(int $bytes): void
    {
        if ($this->memoryFits($bytes)) {
            return;
        }
        self::collectGarbage();
        if (!$this->memoryFits($bytes)) {
            throw new LimitReached('memory');
        }
    }

    /** whether $bytes more stay within memoryBytes now (records the growth for usage()) */
    public function memoryFits(int $bytes): bool
    {
        $growth = memory_get_usage() - $this->memoryBase;
        if ($growth < 0) {  // garbage from before start() was freed: count from the lower mark
            $this->memoryBase += $growth;
            $growth = 0;
        }
        if ($growth > $this->peakGrowth) {
            $this->peakGrowth = $growth;
        }
        return ($this->memoryBytes === null || $growth + $bytes <= $this->memoryBytes)
            && (!$this->hostMemoryIsLimit || MemoryLimit::fits($bytes));
    }

    /**
     * Coroutine::newThread: $coroutine was created. Over the limit, the
     * coroutines that finished or that PHP can free (unreachable, with
     * their fibers) stop counting first.
     */
    public function countCoroutine(Coroutine $coroutine): void
    {
        if ($this->coroutines === null) {
            return;
        }
        $this->liveCoroutines ??= new \WeakMap();
        if (\count($this->liveCoroutines) >= $this->coroutines) {
            foreach ($this->liveCoroutines as $counted => $unused) {
                if ($counted->isDead()) {
                    unset($this->liveCoroutines[$counted]);
                }
            }
            if (\count($this->liveCoroutines) >= $this->coroutines) {
                gc_collect_cycles();  // (a WeakMap entry goes with its coroutine)
                if (\count($this->liveCoroutines) >= $this->coroutines) {
                    throw new LimitReached('coroutines');
                }
            }
        }
        $this->liveCoroutines[$coroutine] = true;
    }

    /**
     * What the run used since start(): steps, the peak memory growth
     * (sampled at the checks, exact when the run set a new peak for the
     * process), milliseconds, and bytes written to the output sinks.
     *
     * @return array{steps: int, peakMemoryBytes: int, milliseconds: float, outputBytes: int}
     */
    public function usage(): array
    {
        $this->memoryFits(0);
        $peak = $this->peakGrowth;
        $processPeak = memory_get_peak_usage();
        if ($processPeak > $this->processPeakAtStart) {  // the run set it
            $peak = max($peak, $processPeak - $this->memoryBase);
        }
        return [
            'steps' => $this->stepsBefore + $this->allowance - $this->stepsLeft,
            'peakMemoryBytes' => max(0, $peak),
            'milliseconds' => (hrtime(true) - $this->startTime) / 1e6,
            'outputBytes' => $this->outputUsed,
        ];
    }

    /** lgc.c: luaC_fullgc in emergency mode: PHP frees unreachable cycles; no finalizer runs */
    private static function collectGarbage(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
    }
}
