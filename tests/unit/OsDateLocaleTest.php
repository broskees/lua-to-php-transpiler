<?php

declare(strict_types=1);

namespace Tests\OsDateLocaleTest;

/*
 * os.date in LC_TIME locales this machine does not install (the diff case
 * os_date_locale.lua covers the installed ones): localedef builds them
 * into a scratch directory that LOCPATH points glibc at, for lua5.4 and
 * for bin/lua alike. Every conversion of loslib.c's LUA_STRFTIMEOPTIONS
 * must agree, the locale's names, formats and era included.
 *
 * What PHP's nl_langinfo cannot give is left out (see CTime::timeLocale):
 * of ja_JP's eras only the first (Reiwa, from 2020) is known, so its era
 * conversions are compared on later dates only, and no %O conversion of
 * a locale with alternative digits (ja_JP's kanji numerals, fa_IR's
 * Persian digits, which its %c and %x use) can agree.
 */

const LOCALES = ['en_US', 'de_DE', 'fr_FR', 'th_TH', 'ja_JP'];

/** the directory of the locales of LOCALES, built once ("de_DE.utf8", ...) */
function localeDirectory(): string
{
    $directory = scratchDirectory() . '/locales';
    if (!is_dir($directory)) {
        mkdir($directory);
        foreach (LOCALES as $locale) {
            $command = ['localedef', '-i', $locale, '-f', 'UTF-8', "$directory/$locale.utf8"];
            [$status, , $errorOutput] = runCommand($command);
            assertSame(0, $status, "localedef $locale: $errorOutput");
        }
    }
    return $directory;
}

const SCRIPT = <<<'LUA'
    local all = {
      "a", "A", "b", "B", "c", "C", "d", "D", "e", "F", "g", "G", "h", "H", "I", "j", "m", "M", "n", "p",
      "r", "R", "S", "t", "T", "u", "U", "V", "w", "W", "x", "X", "y", "Y", "z", "Z", "%",
      "Ec", "EC", "Ex", "EX", "Ey", "EY",
      "Od", "Oe", "OH", "OI", "Om", "OM", "OS", "Ou", "OU", "OV", "Ow", "OW", "Oy",
    }
    local function formatOf(conversions, skip)
      local format = "!"
      for _, conversion in ipairs(conversions) do
        if not conversion:find(skip) then format = format .. "%" .. conversion .. "|" end
      end
      return format
    end
    local times = {0, 951830709, 1700000000, 1719835200, -2208902401, 4102444800 + 3600 * 15}
    local recent = {1700000000, 1719835200, 4102444800 + 3600 * 15}
    local cases = {
      {"en_US.utf8", all, times}, {"de_DE.utf8", all, times}, {"fr_FR.utf8", all, times},
      {"th_TH.utf8", all, times},
      {"ja_JP.utf8", all, times, "^[OE]"}, {"ja_JP.utf8", all, recent, "^O"},
    }
    for _, case in ipairs(cases) do
      local locale, conversions, when, skip = case[1], case[2], case[3], case[4] or "^$"
      print(locale, os.setlocale(locale, "time"))
      local format = formatOf(conversions, skip)
      for _, t in ipairs(when) do print(os.date(format, t)) end
      -- the 12-hour clock around noon and midnight
      for _, hour in ipairs{0, 1, 11, 12, 13, 23} do print(os.date("!%I %p %r", hour * 3600)) end
      os.setlocale("C", "time")
    end
    LUA;

function test_os_date_in_locales_built_for_the_test(): void
{
    $script = scratchDirectory() . '/os_date_locales.lua';
    file_put_contents($script, SCRIPT);
    $environment = ['env', 'LOCPATH=' . localeDirectory(), 'TZ=UTC'];
    $reference = runCommand([...$environment, 'lua5.4', $script]);
    assertSame(0, $reference[0], "lua5.4: $reference[2]");
    assertTrue(str_contains($reference[1], "ja_JP.utf8\tja_JP.utf8\n"), 'the locales were built');
    $ours = runCommand([...$environment, 'php', realpath(REPO_ROOT . '/bin/lua'), $script]);
    assertSame($reference, $ours);
}
