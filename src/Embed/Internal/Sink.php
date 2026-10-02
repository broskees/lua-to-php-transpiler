<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Runtime\Budget;
use LuaPhp\Runtime\MemoryLimit;
use LuaPhp\Runtime\OutputSink;

/**
 * A sandbox's standard output and standard error (print, io.write, warn
 * once "@on", io.stderr: one sink, as the brief has them): collected for
 * Result::$output, or handed to the host's callable (Environment's
 * output). The budget charges every write first (GlobalState::
 * writeOutput), so nothing past outputBytes arrives here. The host's
 * callable is host code a run calls, as a PHP function is: after it,
 * State::checkAfterHostCode.
 *
 * @internal
 */
final class Sink implements OutputSink
{
    /** what the current run wrote, when collecting */
    public string $collected = '';

    /** bytes the current run wrote */
    public int $written = 0;

    /** the state's budget (null: none): what it collects grows within its memory */
    public ?Budget $budget = null;

    public function __construct(
        /** the host's sink (a callable, or StdoutSink::write), or null to collect */
        private readonly ?\Closure $host,
        private readonly State $state,
    ) {
    }

    public function write(string $bytes): void
    {
        $this->written += \strlen($bytes);
        if ($this->host === null) {
            // (it may move as it grows)
            MemoryLimit::reserve(\strlen($this->collected) + \strlen($bytes), $this->budget);
            $this->collected .= $bytes;
            return;
        }
        ($this->host)($bytes);
        $this->state->checkAfterHostCode();
    }
}
