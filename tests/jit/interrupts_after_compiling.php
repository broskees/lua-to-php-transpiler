<?php

declare(strict_types=1);

// The interrupt harness (tests/jit/interrupts.php) for runs of our own
// code: prepend it as that one. It first compiles our runtime (src/) and
// the program into opcache, then starts the harness, so that no signal
// arrives while opcache compiles a file. PHP 8.5 can crash there
// (zend_signal_handler_unblock, called from opcache's compile_file with
// signals queued while it blocked interruptions: about one run in a
// hundred at 3,000 signals a second), a PHP bug apart from php-src#24186
// that a test of our code must not trip over. The files load from opcache
// later, as they would after any warm-up.

(static function (): void {
    $sourceFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(realpath(__DIR__ . '/../../src'), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($sourceFiles as $file) {
        if ($file->getExtension() === 'php') {
            opcache_compile_file($file->getPathname());
        }
    }
    opcache_compile_file(realpath($_SERVER['SCRIPT_FILENAME']));
})();

require __DIR__ . '/interrupts.php';
