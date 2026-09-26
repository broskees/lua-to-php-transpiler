<?php

declare(strict_types=1);

// Prepend file (php -d auto_prepend_file=...) that makes the emitter cut
// every function into segments of one instruction
// (FunctionEmitter::$maximumSegmentWeight), so that every jump and every
// fall-through goes through the dispatcher, for SegmentsTest.

require __DIR__ . '/../../src/autoload.php';

LuaPhp\Emitter\FunctionEmitter::$maximumSegmentWeight = 1;
