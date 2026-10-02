<?php

declare(strict_types=1);

namespace Tests\TimeZoneTest;

/*
 * The local time zone as glibc's tzset sets it from TZ (diff cases cannot
 * set the environment): a zone file (":" prefix, absolute path, or a name
 * under /usr/share/zoneinfo), else a POSIX TZ string (names, <quoted>
 * names, offsets, Jn / n / Mm.w.d rules, transition times, no rule: the
 * rules of zoneinfo's posixrules), else UTC under the name given. lua5.4
 * and bin/lua run PROBE with each TZ; os.date, os.date("*t") and os.time
 * (mktime with its isdst hint, in skipped and repeated hours) must agree.
 */

// prints the local zone around its transitions in a few years, and what os.time makes of them
const PROBE = <<<'LUA'
    local function show(t)
      local ok, text = pcall(os.date, "%Y-%m-%d %H:%M:%S %z %Z %j %a", t)
      if not ok then print(t, text) return end
      local fields = os.date("*t", t)
      print(t, text, fields.isdst, fields.yday, fields.wday)
    end
    local function time(fields)
      local copy = {}
      for k, v in pairs(fields) do copy[k] = v end
      local ok, t = pcall(os.time, copy)
      print("os.time", fields.year, fields.month, fields.day, fields.hour, fields.min, fields.isdst, "->",
        ok, t, copy.year, copy.month, copy.day, copy.hour, copy.min, copy.isdst, copy.yday, copy.wday)
    end
    -- transitions: hourly scan of a few years (a change of offset or zone name)
    for _, year in ipairs{1969, 1970, 1971, 2021, 2024, 2038, 2100} do
      local start = os.time{year = year, month = 1, day = 1, hour = 0, isdst = false}
      local before = os.date("%z %Z", start)
      for t = start + 3600, start + 366 * 86400, 3600 do
        local now = os.date("%z %Z", t)
        if now ~= before then
          -- the second it changed
          local low, high = t - 3600, t
          while high - low > 1 do
            local middle = (low + high) // 2
            if os.date("%z %Z", middle) == before then low = middle else high = middle end
          end
          show(high - 1) show(high)
          local wall = os.date("*t", high)
          for _, isdst in ipairs{"nil", true, false} do
            for _, delta in ipairs{-2, -1, 0, 1} do
              local fields = {year = wall.year, month = wall.month, day = wall.day, hour = wall.hour + delta, min = 30}
              if isdst ~= "nil" then fields.isdst = isdst end
              time(fields)
            end
          end
          before = now
        end
      end
    end
    -- times far from 1970, and the extremes
    for _, t in ipairs{0, -1, 86399, 951782400, 4102444800, -2208988800, -11644473600, 253402300799,
                       -62135596800, 1 << 40, -(1 << 40), 1 << 55, -(1 << 55), math.maxinteger} do
      show(t)
    end
    for _, fields in ipairs{
      {year = 1970, month = 1, day = 1, hour = 0},
      {year = 1969, month = 12, day = 31, hour = 23, min = 59, sec = 59},
      {year = 2000, month = 13, day = 32, hour = 25, min = 61, sec = 61},
      {year = 2000, month = 1, day = 1, hour = 0, isdst = true},
      {year = 2000, month = 7, day = 1, hour = 0, isdst = false},
      {year = 1600, month = 2, day = 29}, {year = 3000, month = 7, day = 4},
      {year = -1000, month = 1, day = 1}, {year = 1000000, month = 6, day = 15, isdst = true},
      {year = 2000, month = -30, day = -400, hour = -50},
    } do
      time(fields)
    end
    print(pcall(os.time, {year = math.maxinteger // 2, month = 1, day = 1}))
    print(pcall(os.date, "%c", 1 << 60))
    LUA;

/**
 * [exit status, stdout, stderr] of $program running PROBE with TZ set to
 * $tz (null: unset) and the variables $environment
 *
 * @param list<string> $program
 * @param list<string> $environment
 */
function probe(array $program, ?string $tz, array $environment): array
{
    $script = scratchDirectory() . '/time_zone_probe.lua';
    if (!is_file($script)) {
        file_put_contents($script, PROBE);
    }
    $tzSetting = $tz === null ? ['-u', 'TZ'] : ["TZ=$tz"];
    return runCommand(['env', ...$tzSetting, ...$environment, ...$program, $script]);
}

/**
 * @param list<?string> $values
 * @param list<string> $environment
 */
function assertSameAsReference(array $values, array $environment = []): void
{
    $ourProgram = ['php', realpath(REPO_ROOT . '/bin/lua')];
    foreach ($values as $tz) {
        $reference = probe(['lua5.4'], $tz, $environment);
        assertSame(0, $reference[0], "lua5.4 with TZ=$tz: $reference[2]");
        assertSame($reference, probe($ourProgram, $tz, $environment), 'TZ=' . ($tz ?? '(unset)'));
    }
}

function test_posix_time_zone_strings(): void
{
    assertSameAsReference([
        'EST5EDT,M3.2.0,M11.1.0', 'CET-1CEST,M3.5.0,M10.5.0/3', 'JST-9', 'UTC0', '<+0330>-3:30', 'EST5',
        'AAA3BBB,J60/2,J300/2', 'AAA3BBB,59,299', 'NZST-12NZDT,M9.5.0,M4.1.0/3', '<-03>3<-02>,M3.5.0/-2,M10.5.0/-1',
        'EST5EDT4,M3.2.0/-1,M11.1.0/26', 'AAA+3BBB+2,M3.2.0/2:30:15,M11.1.0/1:2:3', 'AAA-25BBB-26:61:61,J1/0,J365/25',
        'AAA3BBB,M3.5.6/167,M10.1.0/-167', 'AAA3BBB2,0/0,365/24',
    ]);
}

function test_strings_glibc_rejects_part_of(): void
{
    // no offset (UTC under the name), a bad rule (the rule fields stay zero), garbage after it
    assertSameAsReference([
        'XYZ', '+0330', 'AB3', '<AB>3', '<AAA', 'AAA 3', 'AAA3 BBB', 'AAA+-3BBB', 'AAA99999BBB',
        'AAA3BBB,M13.1.0,M1.1.0', 'AAA3BBB,J0,J1', 'AAA3BBB,M3.2.0/x', 'AAA3BBB,M3.2.0/1:2:3:4,M11.1.0',
        'EST5EDT,M3.2.0,M11.1.0,junk',
    ]);
}

function test_no_rule_takes_the_rules_of_posixrules(): void
{
    assertSameAsReference(['AAA3BBB', 'CET-1CEST', 'AAA3BBB,', 'XXX-2YYY-3']);
}

function test_zone_files(): void
{
    assertSameAsReference([
        null, '', 'America/New_York', ':Europe/Berlin', '/usr/share/zoneinfo/Asia/Tokyo', ':/etc/localtime',
        'Australia/Lord_Howe', 'Pacific/Chatham', 'Etc/GMT+5', 'right/UTC', 'america/new_york', 'Europe/Nowhere',
        'EST5EDT', '/etc/passwd', ':', '/usr/share/zoneinfo', 'Factory',
    ]);
}

function test_zone_files_under_tzdir(): void
{
    $directory = scratchDirectory() . '/zoneinfo';
    @mkdir($directory);
    copy('/usr/share/zoneinfo/Asia/Tokyo', "$directory/Mine");
    assertSameAsReference(['Mine', 'America/New_York', 'AAA3BBB'], ["TZDIR=$directory"]);
}
