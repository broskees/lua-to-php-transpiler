<?php

declare(strict_types=1);

// Prepend file (php -d auto_prepend_file=...) that makes the emitter emit
// constant table constructors inline, one instruction at a time
// (FunctionEmitter::$inlineConstructors), for TableConstructorTest.

require __DIR__ . '/../../src/autoload.php';

LuaPhp\Emitter\FunctionEmitter::$inlineConstructors = true;
