<?php

declare(strict_types=1);

namespace Tests\StringIdentityTest;

/*
 * string.format('%p') of a long string depends on which Lua strings are one
 * PHP string (zend_string), and PHP's opcache changes that for literals:
 * without opcache, literals are interned (one string per contents); with
 * it, the literals of eval'd code and of files it does not cache are one
 * string per occurrence, and those of files it caches are interned in
 * shared memory. So the cases that inspect string identity run here under
 * each of these, whatever php.ini says: through bin/lua (eval'd code) and
 * through lua2php scripts (files).
 */

/** PHP settings => command-line options, and whether opcache caches the script file */
const PHP_SETTINGS = [
    'opcache off' => [['-d', 'opcache.enable_cli=0'], false],
    'opcache on, script cached' => [['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0'], true],
    // (opcache skips files modified less than file_update_protection seconds ago)
    'opcache on, script not cached' => [['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=2000000000'], false],
];

const IDENTITY_DIFF_CASES = ['string_pointer.lua', 'string_patterns.lua', 'string_format.lua'];

const IDENTITY_OFFICIAL_FILES = ['literals.lua', 'pm.lua', 'strings.lua'];

function test_php_settings_take_effect(): void
{
    $probe = scratchDirectory() . '/opcache-probe.php';
    file_put_contents($probe, '<?php echo (int) (function_exists("opcache_get_status") && opcache_get_status(false) !== false),'
        . ' (int) (function_exists("opcache_is_script_cached") && opcache_is_script_cached(__FILE__));');
    foreach (PHP_SETTINGS as $label => [$options, $scriptCached]) {
        $opcacheEnabled = $options[1] === 'opcache.enable_cli=1';
        $expected = ((int) $opcacheEnabled) . ((int) $scriptCached);
        assertSame([0, $expected, ''], runCommand(['php', ...$options, $probe]), "$label: opcache enabled, script cached");
    }
}

function test_identity_diff_cases_behave_like_lua_under_each_opcache_setting(): void
{
    $diffDirectory = REPO_ROOT . '/tests/diff';
    $outputDirectory = scratchDirectory() . '/string-identity';
    @mkdir($outputDirectory);
    foreach (IDENTITY_DIFF_CASES as $name) {
        [$expectedStatus, $expectedOutput, $expectedErrors] = runCommand(['lua5.4', $name], '', $diffDirectory);
        $transpiled = $outputDirectory . '/' . basename($name, '.lua') . '.php';
        [$status, , $errors] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $name, '-o', $transpiled], '', $diffDirectory);
        assertSame(0, $status, "lua2php $name: $errors");
        foreach (PHP_SETTINGS as $setting => [$options]) {
            $runs = ['bin/lua' => [REPO_ROOT . '/bin/lua', $name], 'lua2php' => [$transpiled]];
            foreach ($runs as $how => $arguments) {
                [$status, $output, $errors] = runCommand(['php', ...$options, ...$arguments], '', $diffDirectory);
                $label = "$name via $how, $setting";
                assertSame($expectedStatus, $status, "$label: exit status ($errors)");
                assertSame(normalizeDifferentialOutput($expectedOutput, 'lua5.4'), normalizeDifferentialOutput($output, 'lua5.4'), "$label: stdout");
                assertSame(normalizeDifferentialOutput($expectedErrors, 'lua5.4'), normalizeDifferentialOutput($errors, $arguments[0]), "$label: stderr");
            }
        }
    }
}

function test_official_identity_files_pass_under_each_opcache_setting(): void
{
    $outputDirectory = scratchDirectory() . '/string-identity-official';
    @mkdir($outputDirectory);
    $running = [];
    foreach (IDENTITY_OFFICIAL_FILES as $name) {
        $transpiled = $outputDirectory . '/' . basename($name, '.lua') . '.php';
        [$status, , $errors] = runCommand(['php', REPO_ROOT . '/bin/lua2php', $name, '-o', $transpiled], '', OFFICIAL_TESTS_DIRECTORY);
        assertSame(0, $status, "lua2php $name: $errors");
        foreach (PHP_SETTINGS as $setting => [$options]) {
            // as tests/official.sh runs them
            $command = ['php', ...$options, REPO_ROOT . '/bin/lua', '-e_U=true _soft=true _port=true _nomsg=true', $name];
            $running["$name via bin/lua, $setting"] = startProcess($command, OFFICIAL_TESTS_DIRECTORY);
            $running["$name via lua2php, $setting"] = startProcess(['php', ...$options, $transpiled], OFFICIAL_TESTS_DIRECTORY);
        }
    }
    $failures = [];
    foreach ($running as $label => $started) {
        [$status, $output, $errors] = finishProcess($started);
        if ($status !== 0 || !str_ends_with($output, "OK\n")) {
            $failures[] = "$label: exit status $status: " . trim(substr($errors, 0, 300));
        }
    }
    assertSame([], $failures);
}
