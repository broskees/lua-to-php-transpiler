<?php

declare(strict_types=1);

namespace Tests\StringToNumberTest;

use LuaPhp\Runtime\StringToNumber;

/*
 * StringToNumber::convert against lua5.4's tonumber(s): nil, or math.type
 * plus the exact value (integers in decimal, floats as their IEEE bits).
 */

/** @return list<string> */
function handPickedCases(): array
{
    return [
        '', ' ', '0', '-0', '+0', '1', ' 1 ', "\t1\n", "\x0B1\x0C", "\r\n 42 \r\n", '1 x', 'x1',
        '0x', '0x1', '0X1F', '-0x1', '+0x10', '0xffffffffffffffff', '0x10000000000000000',
        '-0xffffffffffffffff', '0x7fffffffffffffff', '0x8000000000000000', '0x123456789abcdef0123',
        '9223372036854775807', '9223372036854775808', '-9223372036854775808', '-9223372036854775809',
        '18446744073709551616', '922337203685477580', '9223372036854775806',
        '1e', '1e+', '1e5', '1E-5', '1e+05', '.5', '5.', '.', '-.5', '+.5', '- 1', '--1', '+-1', '-+1',
        '0x.8', '0x8.', '0x.', '0x1p4', '0x1P-4', '0x1p', '0x1p+', '0x1.8p1', '0xA.Bp-3', '0x1e+1', '0x1e',
        'inf', 'nan', '-inf', 'INF', 'NaN', 'infinity', '1e400', '-1e400', '1e-400', '4.9e-324',
        '2.4703282292062327e-324', '2.4703282292062328e-324', '1.7976931348623157e308', '1.7976931348623159e308',
        '0x1p-1074', '0x1p-1075', '0x1.8p-1075', '0x1.0000001p-1075', '0x1p-1076', '0x1p1023', '0x1p1024',
        '0x1.fffffffffffff8p1023', '0x1.fffffffffffff7ffp1023', '0x1.fffffffffffffp1023',
        '0x1.00000000000008p0', '0x1.00000000000018p0', '0x1.000000000000080001p0', '0x1.00000000000007ffp0',
        '0x0.0000000000001p-1022', '0x1.0000000000001p-1022', '0x0.fffffffffffff8p-1022', '0x0.fffffffffffffcp-1022',
        '0x1p99999999999999999999', '0x1p-99999999999999999999', '0x0p99999999999999999999',
        "1\0", "0x1\0", "\x851", "1\xA0", '1e1', '0e0', '-0.0', '0x0p0', '-0x0p0', '0.1', '3.14159265358979',
        '123456789012345678901234567890', '1' . str_repeat('0', 400), '0.' . str_repeat('0', 400) . '1',
        '0x' . str_repeat('f', 20), '0x' . str_repeat('0', 50) . '1', '0x' . str_repeat('f', 20) . '.8',
        '  0x1p-1022  ', '1.5 ', ' 1.5', '1 .5', '1..5', '1.5.', '0x1..', '3-4', '1e5.5', '1ee5', '1e5e5',
    ];
}

/** @return list<string> */
function randomCases(): array
{
    mt_srand(20260923);
    $cases = [];
    $alphabet = '0123456789abcdefxXpPeE.+- ';
    for ($i = 0; $i < 1500; $i++) {  // random junk from numeral characters
        $length = mt_rand(1, 12);
        $case = '';
        for ($j = 0; $j < $length; $j++) {
            $case .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        $cases[] = $case;
    }
    $randomHexDigits = function (int $count): string {
        $digits = '';
        for ($j = 0; $j < $count; $j++) {
            $digits .= dechex(mt_rand(0, 15));
        }
        return $digits;
    };
    for ($i = 0; $i < 1500; $i++) {  // hexadecimal floats, many near rounding and range edges
        $case = '0x' . $randomHexDigits(mt_rand(0, 20));
        if (mt_rand(0, 1) === 1) {
            $case .= '.' . $randomHexDigits(mt_rand(0, 20));
        }
        $case .= 'p' . mt_rand(-1160, 1030);
        $cases[] = $case;
    }
    for ($i = 0; $i < 1000; $i++) {  // decimal floats
        $case = (string) mt_rand(0, 999999999) . '.' . (string) mt_rand(0, 999999999) . (string) mt_rand(0, 999999999);
        $case .= 'e' . mt_rand(-340, 320);
        $cases[] = $case;
    }
    for ($i = 0; $i < 500; $i++) {  // decimal integers around the 64-bit limit
        $cases[] = (mt_rand(0, 1) === 1 ? '-' : '') . '92233720368547758' . mt_rand(0, 99);
    }
    return $cases;
}

function describe(int|float|null $number): string
{
    if ($number === null) {
        return 'nil';
    }
    if (is_int($number)) {
        return "int $number";
    }
    return 'float ' . bin2hex(pack('E', $number));
}

function test_convert_matches_lua_tonumber(): void
{
    $cases = [...handPickedCases(), ...randomCases()];
    $casesFile = scratchDirectory() . '/tonumber-cases.txt';
    file_put_contents($casesFile, implode("\n", array_map('bin2hex', $cases)) . "\n");
    $luaProgram = <<<'LUA'
        for line in io.lines(arg[1]) do
          local s = line:gsub('..', function(h) return string.char(tonumber(h, 16)) end)
          local n = tonumber(s)
          if n == nil then print('nil')
          elseif math.type(n) == 'integer' then print(string.format('int %d', n))
          else print('float ' .. (string.pack('>d', n):gsub('.', function(c) return string.format('%02x', c:byte()) end)))
          end
        end
        LUA;
    $scriptFile = scratchDirectory() . '/tonumber.lua';
    file_put_contents($scriptFile, $luaProgram);
    [$exitCode, $output, $errorOutput] = runCommand(['lua5.4', $scriptFile, $casesFile]);
    assertSame(0, $exitCode, $errorOutput);
    $expectedLines = explode("\n", rtrim($output, "\n"));
    assertSame(count($cases), count($expectedLines), 'one answer per case');
    foreach ($cases as $index => $case) {
        assertSame($expectedLines[$index], describe(StringToNumber::convert($case)), 'tonumber(' . var_export($case, true) . ')');
    }
}
