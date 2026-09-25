<?php

declare(strict_types=1);

// Prepend file (php -d auto_prepend_file=...) that makes every pattern
// match run lstrlib.c's port instead of its PCRE translation
// (MatchState::$forcePort), for tests/fuzz/patterns.php and PatternRegexTest.

require __DIR__ . '/../../src/autoload.php';

LuaPhp\Lib\String\MatchState::$forcePort = true;
