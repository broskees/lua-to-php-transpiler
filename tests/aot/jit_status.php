<?php

declare(strict_types=1);

// Prepended to a generated script by AheadOfTimeTest: prints the JIT's state
// on stderr at exit, "jit <opcache.jit> <on|off>".
register_shutdown_function(static function (): void {
    $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    $on = is_array($status) && ($status['jit']['on'] ?? false);
    fwrite(STDERR, 'jit ' . ini_get('opcache.jit') . ' ' . ($on ? 'on' : 'off') . "\n");
});
