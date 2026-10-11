<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * PHP's function JIT with register allocation (opcache.jit with T digit 0
 * to 3 and R digit 1 or 2: "function", 1205, 1105, 1215, 1225, ...) gives
 * wrong results without an error: in PHP 8.4 and 8.5, a VM interrupt (an
 * async signal handler, a sampling profiler) handled inside a JIT-compiled
 * loop resumes it from stale register-held values, so iterations repeat
 * (php-src#23983, any loop; fix PR #24003 open). PHP has no per-function
 * opt-out for it (opcache_jit_blacklist covers the tracing JIT only), and
 * the setting is the host's: hosts lock it, and a lua2php script is
 * compiled before it could switch. So every entry point refuses to run
 * under it, before it compiles or runs any Lua code: bin/lua, bin/luac,
 * bin/lua2php and lua2php scripts with a message and exit status 1
 * (Standalone::refusesJit), the embedding API with Embed\UnsafeJitSetting
 * (Embed\Internal\State's constructor and every call into Lua, State::enter,
 * as a host may switch it with ini_set; Environment::compile). The tracing
 * JIT (T digit 5) and register allocation off (R digit 0) run.
 *
 * @internal
 */
final class JitSafety
{
    /** the opcache.jit value $refusal was decided for (null: none yet; false: no opcache) */
    private static string|false|null $decidedSetting = null;

    private static ?string $refusal = null;

    /**
     * The message to refuse with when PHP runs a function JIT with
     * register allocation, else null. Decided from opcache_get_status()
     * when the host lets us call it, else from the ini settings, and never
     * warns (bin/lua makes warnings exceptions). opcache.jit is the only
     * setting that decides it and can change at run time (ini_set), so the
     * verdict is kept per value: a check costs one ini_get.
     */
    public static function refusal(): ?string
    {
        $setting = ini_get('opcache.jit');
        if ($setting === self::$decidedSetting) {
            return self::$refusal;
        }
        self::$decidedSetting = $setting;
        self::$refusal = self::functionJitWithRegisterAllocationRuns()
            ? "opcache.jit=$setting is PHP's function JIT with register allocation, which can repeat loop"
                . ' iterations and compute wrong results (php-src#23983); use opcache.jit=tracing, 1005 or disable'
            : null;
        return self::$refusal;
    }

    private static function functionJitWithRegisterAllocationRuns(): bool
    {
        // opcache.restrict_api makes opcache_get_status warn for scripts outside it: use the ini settings
        if (\function_exists('opcache_get_status') && (string) ini_get('opcache.restrict_api') === '') {
            $status = opcache_get_status(false);
            if (!\is_array($status)) {
                return false;  // opcache did not start (off for this SAPI, no shared memory): no JIT
            }
            $jit = $status['jit'] ?? null;  // (none in file_cache_only mode, which has no JIT)
            // zend_jit_status: 'kind' is the T digit, 'opt_flags' & 3 the R digit (ZEND_JIT_REG_ALLOC_*)
            return \is_array($jit) && $jit['on'] === true && $jit['kind'] <= 3 && ($jit['opt_flags'] & 3) !== 0;
        }
        // ZendAccelerator.c: accel_startup, accel_post_startup: when opcache starts the JIT
        if (!\extension_loaded('Zend OPcache') || !self::iniBool('opcache.enable')) {
            return false;
        }
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && !self::iniBool('opcache.enable_cli')) {
            return false;
        }
        if (self::iniBool('opcache.file_cache_only')) {
            return false;
        }
        if (@ini_parse_quantity((string) ini_get('opcache.jit_buffer_size')) <= 0) {
            return false;
        }
        return self::isFunctionJitWithRegisterAllocation((string) ini_get('opcache.jit'));
    }

    /** zend_jit.c: zend_jit_config and zend_jit_parse_config_num, for an opcache.jit value */
    private static function isFunctionJitWithRegisterAllocation(string $setting): bool
    {
        $name = strtolower($setting);
        if ($name === 'function') {
            return true;  // ZEND_JIT_ON_SCRIPT_LOAD with ZEND_JIT_REG_ALLOC_GLOBAL
        }
        // "disable", "", "0", "off", "no", "false": no JIT; "1", "on", "yes", "true", "tracing": tracing
        if (\in_array($name, ['disable', '', '0', 'off', 'no', 'false', '1', 'on', 'yes', 'true', 'tracing'], true)) {
            return false;
        }
        // a CRTO number as strtol reads it (a minus sign makes it 0 or invalid: no JIT)
        if (preg_match('/^[ \t\n\v\f\r]*\+?([0-9]+)$/D', $setting, $match) !== 1) {
            return false;  // invalid: the JIT is disabled (or ini_set refused it)
        }
        $digits = ltrim($match[1], '0');
        if ($digits === '' || \strlen($digits) > 4) {
            return false;  // 0: off; more than 4 digits: invalid
        }
        $number = (int) $digits;
        $optimizationLevel = $number % 10;
        $trigger = intdiv($number, 10) % 10;
        $registerAllocation = intdiv($number, 100) % 10;
        $cpuFlags = intdiv($number, 1000);
        $valid = $optimizationLevel >= 1 && $optimizationLevel <= 5 && $trigger <= 5 && $trigger !== 4
            && $registerAllocation <= 2 && $cpuFlags <= 1;
        return $valid && $trigger <= 3 && $registerAllocation !== 0;
    }

    /** zend_ini.c: zend_ini_parse_bool of an ini setting ("true", "yes", "on", or atoi() != 0) */
    private static function iniBool(string $name): bool
    {
        $value = (string) ini_get($name);
        return \in_array(strtolower($value), ['true', 'yes', 'on'], true)
            || preg_match('/^[ \t\n\v\f\r]*[+-]?0*[1-9]/', $value) === 1;
    }
}
