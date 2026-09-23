<?php

declare(strict_types=1);

namespace Tests\NumberFormatTest;

use LuaPhp\Runtime\NumberFormat;

function floatFromBits(int $bits): float
{
    return unpack('e', pack('P', $bits))[1];
}

/*
 * Expected values come from the reference interpreter:
 *     print(tostring(v), string.format("%.3e", v), string.format("%.2f", v))
 */
function test_lua_tostring_of_floats_matches_the_reference(): void
{
    $cases = [
        [0.0, '0.0'],
        [-0.0, '-0.0'],
        [1.0, '1.0'],
        [100.0, '100.0'],
        [0.1, '0.1'],
        [3.14159, '3.14159'],
        [1e15, '1e+15'],
        [1e16, '1e+16'],
        [123456789012345.0, '1.2345678901234e+14'],  // exact tie, rounded to even
        [2.0 ** 53, '9.007199254741e+15'],
        [2.0 ** 63, '9.2233720368548e+18'],
        [1e100, '1e+100'],
        [1e308, '1e+308'],
        [5e-324, '4.9406564584125e-324'],
        [1e-5, '1e-05'],
        [0.0001, '0.0001'],
        [INF, 'inf'],
        [-INF, '-inf'],
        [floatFromBits(0x7FF8000000000000), 'nan'],
        [floatFromBits(-0x0008000000000000), '-nan'],  // 0/0 on x86-64
    ];
    foreach ($cases as [$value, $expected]) {
        assertSame($expected, NumberFormat::luaNumberToString($value));
    }
}

function test_e_and_f_styles_match_the_reference(): void
{
    assertSame('1.235e+14', NumberFormat::formatE(123456789012345.0, 3));
    assertSame('4.941e-324', NumberFormat::formatE(5e-324, 3));
    assertSame('-0.000e+00', NumberFormat::formatE(-0.0, 3));
    assertSame('1.000e+308', NumberFormat::formatE(1e308, 3));
    assertSame('0.10', NumberFormat::formatF(0.1, 2));
    assertSame('0.00', NumberFormat::formatF(1e-5, 2));
    assertSame('9223372036854775808.00', NumberFormat::formatF(2.0 ** 63, 2));
    assertSame(
        '10000000000000000159028911097599180468360808563945281389781327557747838772170381060813469985856815104.00',
        NumberFormat::formatF(1e100, 2),
    );
}

/** @return list<float> a spread of doubles: boundaries, ties, and pseudo-random bit patterns */
function sampleValues(): array
{
    $values = [
        0.0, -0.0, 0.5, 1.5, 2.5, -2.5, 0.125, 0.375, 1e15, 1e16, 1e17, 999999999999999.0,
        99999999999999.5, 0.1, 0.2, 0.3, 1 / 3, 2 / 3, 2.0 ** 53, 2.0 ** 53 + 2, 2.0 ** 63, -(2.0 ** 63),
        2.0 ** 64, 1e21, 1e22, 1e23, 1e300, 1e308, 1.7976931348623157e308, 2.2250738585072014e-308,
        2.225073858507201e-308, 5e-324, 1e-300, 0.0001, 0.00001, 123.456, 9.5, 99.5, 0.95, 0.05,
        1e-4 - 1e-20, 9.9999999999999e-5, 0.000099999999999999995, 3.14159265358979, 2.718281828459045,
        INF, -INF,
    ];
    mt_srand(20260923);
    for ($i = 0; $i < 300; $i++) {
        // random mantissa and full-range exponent
        $values[] = floatFromBits((mt_rand(0, 0xFFFFFFF) << 36) | (mt_rand(0, 0xFFFFFFF) << 8) | mt_rand(0, 0xFF));
    }
    for ($i = 0; $i < 200; $i++) {
        // short decimals and integers, where rounding ties and '.0' matter
        $values[] = mt_rand(-100000, 100000) / (10 ** mt_rand(0, 6));
        $values[] = (float) mt_rand(0, PHP_INT_MAX) / (2 ** mt_rand(0, 40));
    }
    return array_values(array_filter($values, fn (float $value) => !is_nan($value)));
}

function formatWithNumberFormat(string $format, float $value): string
{
    preg_match('/^%\.(\d+)([efg])$/', $format, $parts);
    $precision = (int) $parts[1];
    return match ($parts[2]) {
        'e' => NumberFormat::formatE($value, $precision),
        'f' => NumberFormat::formatF($value, $precision),
        'g' => NumberFormat::formatG($value, $precision),
    };
}

function test_formats_match_lua_string_format_on_many_values(): void
{
    $formats = ['%.14g', '%.17g', '%.6g', '%.1g', '%.0g', '%.3e', '%.0e', '%.25e', '%.2f', '%.0f', '%.30f'];
    $values = sampleValues();

    $valuesFile = scratchDirectory() . '/number-format-values.txt';
    file_put_contents($valuesFile, implode("\n", array_map(fn (float $value) => bin2hex(pack('e', $value)), $values)) . "\n");
    $oracleScript = <<<'LUA'
        local formats = {...}
        for line in io.lines(table.remove(formats, 1)) do
          local value = string.unpack("<d", (line:gsub("..", function(hex) return string.char(tonumber(hex, 16)) end)))
          local results = {}
          for _, format in ipairs(formats) do results[#results + 1] = string.format(format, value) end
          results[#results + 1] = tostring(value)
          print(table.concat(results, "\t"))
        end
        LUA;
    $oracleScriptFile = scratchDirectory() . '/number-format-oracle.lua';
    file_put_contents($oracleScriptFile, $oracleScript);
    [$exitCode, $oracleOutput, $errorOutput] = runCommand(['lua5.4', $oracleScriptFile, $valuesFile, ...$formats]);
    assertSame(0, $exitCode, "lua5.4 failed: $errorOutput");
    $oracleLines = explode("\n", rtrim($oracleOutput, "\n"));
    assertSame(count($values), count($oracleLines), 'one oracle line per value');

    foreach ($values as $index => $value) {
        $expectedResults = explode("\t", $oracleLines[$index]);
        foreach ($formats as $formatIndex => $format) {
            assertSame($expectedResults[$formatIndex], formatWithNumberFormat($format, $value), "$format of " . bin2hex(pack('E', $value)));
        }
        assertSame($expectedResults[count($formats)], NumberFormat::luaNumberToString($value), 'tostring of ' . bin2hex(pack('E', $value)));
    }
}
