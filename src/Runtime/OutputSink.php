<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Where a state's standard output or standard error goes (C: the stdout
 * and stderr FILEs lua_writestring and lua_writestringerror write to):
 * GlobalState::$output and $errorOutput. print, io.write, io.stdout,
 * io.stderr, warn and debug.debug write through them (GlobalState::
 * writeOutput / writeErrorOutput, which charge the bytes to the state's
 * Budget first). StandardOutput and StandardError are the defaults: the
 * process's own streams, as bin/lua uses them.
 *
 * @internal
 */
interface OutputSink
{
    public function write(string $bytes): void;
}
