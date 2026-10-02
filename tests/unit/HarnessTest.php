<?php

declare(strict_types=1);

namespace Tests\HarnessTest;

/*
 * The helpers of tests/harness.php that the other tests rely on.
 */

const MEGABYTE = 1024 * 1024;

/**
 * runCommand feeds stdin and collects stdout and stderr at the same time. A
 * pipe holds 64 KB: a child that writes a megabyte to stderr before it reads
 * its input, then copies a megabyte of input to stdout, blocks unless all
 * three pipes are served together. `timeout` kills a child that is stuck, so
 * a harness that serves them one after the other fails here instead of
 * hanging the suite.
 */
function test_run_command_serves_a_megabyte_on_stdin_stdout_and_stderr_at_once(): void
{
    $errorFile = scratchDirectory() . '/megabyte-of-errors.txt';
    $expectedErrors = substr(str_repeat("error line\n", intdiv(MEGABYTE, 11) + 1), 0, MEGABYTE);
    file_put_contents($errorFile, $expectedErrors);
    $input = substr(str_repeat("input line\n", intdiv(MEGABYTE, 11) + 1), 0, MEGABYTE);
    $childScript = 'cat "$1" >&2 && exec cat';

    $result = runCommand(['timeout', '20', 'sh', '-c', $childScript, 'sh', $errorFile], $input);

    [$exitStatus, $standardOutput, $standardError] = $result;
    assertSame(0, $exitStatus, 'exit status (124: the child was stuck and timeout killed it)');
    assertSame(strlen($input), strlen($standardOutput), 'stdout length');
    assertTrue($standardOutput === $input, 'stdout is the input');
    assertSame(strlen($expectedErrors), strlen($standardError), 'stderr length');
    assertTrue($standardError === $expectedErrors, 'stderr is the error file');
}
