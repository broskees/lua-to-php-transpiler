<?php

declare(strict_types=1);

// Prepend file (php -d auto_prepend_file=...) that makes the emitter emit
// the compact form of every instruction that has one, in every function
// and in loops too (FunctionEmitter::$compactEverywhere), for
// CompactFormsTest and Lua2PhpTest.

require __DIR__ . '/../../src/autoload.php';

LuaPhp\Emitter\FunctionEmitter::$compactEverywhere = true;
