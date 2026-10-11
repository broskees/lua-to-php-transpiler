<?php

declare(strict_types=1);

// The interrupt harness: a regression test harness for the PHP JIT bug
// php-src#24186 (and #23983). Prepend it to any PHP program
// (php -d auto_prepend_file=tests/jit/interrupts.php program.php ...):
// before the program starts it turns on pcntl_async_signals, installs a
// SIGUSR1 handler that does nothing, and forks a child that sends this
// process SIGUSR1, then sleeps 250 microseconds, until the program ends
// (about 3,000 signals a second on this machine). Each signal sets
// EG(vm_interrupt), as a sampling profiler (Excimer) or
// max_execution_time does. Needs the pcntl and posix extensions.
//
// JIT_INTERRUPTS_REPORT=<file>: the child writes "<signals sent>
// <seconds>" there when it stops, so a test can check the rate.

(static function (): void {
    $signalIntervalMicroseconds = 250;
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, static function (): void {
    });
    $parent = getmypid();
    $child = pcntl_fork();
    if ($child === -1) {
        fwrite(STDERR, "interrupts.php: cannot fork\n");
        exit(1);
    }
    if ($child === 0) {
        $stopped = false;
        pcntl_signal(SIGTERM, static function () use (&$stopped): void {
            $stopped = true;
        });
        $signalsSent = 0;
        $start = hrtime(true);
        // stops when the parent asks, or is gone
        while (!$stopped && posix_kill($parent, SIGUSR1)) {
            $signalsSent++;
            usleep($signalIntervalMicroseconds);
        }
        $reportFile = getenv('JIT_INTERRUPTS_REPORT');
        if ($reportFile !== false) {
            file_put_contents($reportFile, sprintf("%d %.6f\n", $signalsSent, (hrtime(true) - $start) / 1e9));
        }
        exit(0);
    }
    register_shutdown_function(static function () use ($child): void {
        pcntl_signal(SIGUSR1, SIG_IGN);
        posix_kill($child, SIGTERM);
        pcntl_waitpid($child, $status);
    });
})();
