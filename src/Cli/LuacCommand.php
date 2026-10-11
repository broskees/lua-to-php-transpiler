<?php

declare(strict_types=1);

namespace LuaPhp\Cli;

use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\Dump;
use LuaPhp\Compiler\Listing;
use LuaPhp\Compiler\Proto;
use LuaPhp\Compiler\Undump;
use LuaPhp\Lib\Io\CFile;
use LuaPhp\Runtime\Standalone;

/**
 * bin/luac: port of luac.c (Lua 5.4.9) for a single input file: list,
 * parse, strip, dump. Usage and messages match luac5.4; the program name
 * is argv[0].
 *
 * @internal
 */
final class LuacCommand
{
    private const LUA_COPYRIGHT = 'Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio';
    private const DEFAULT_OUTPUT = 'luac.out';  // luac.c: OUTPUT

    private static string $programName = 'luac';

    /**
     * luac.c: main / doargs / pmain, after refusing a JIT mode that can
     * compute wrong results (Standalone::refusesJit)
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        self::$programName = (isset($argv[0]) && $argv[0] !== '') ? $argv[0] : 'luac';
        if (Standalone::refusesJit(self::$programName)) {
            return 1;
        }

        // luac.c: doargs
        $listing = 0;
        $dumping = true;
        $stripping = false;
        $output = self::DEFAULT_OUTPUT;  // null means stdout
        $version = 0;
        $argumentCount = count($argv);
        for ($i = 1; $i < $argumentCount; $i++) {
            $argument = $argv[$i];
            if ($argument === '' || $argument[0] !== '-') {  // end of options; keep it
                break;
            } elseif ($argument === '--') {  // end of options; skip it
                ++$i;
                if ($version) {
                    ++$version;
                }
                break;
            } elseif ($argument === '-') {  // end of options; use stdin
                break;
            } elseif ($argument === '-l') {  // list
                ++$listing;
            } elseif ($argument === '-o') {  // output file
                $output = $argv[++$i] ?? null;
                if ($output === null || $output === '' || ($output[0] === '-' && $output !== '-')) {
                    self::usage("'-o' needs argument");
                }
                if ($output === '-') {
                    $output = null;
                }
            } elseif ($argument === '-p') {  // parse only
                $dumping = false;
            } elseif ($argument === '-s') {  // strip debug information
                $stripping = true;
            } elseif ($argument === '-v') {  // show version
                ++$version;
            } else {  // unknown option
                self::usage($argument);
            }
        }
        if ($i === $argumentCount && ($listing || !$dumping)) {
            $dumping = false;
            $argv[--$i] = self::DEFAULT_OUTPUT;
        }
        if ($version) {
            echo self::LUA_COPYRIGHT, "\n";
            if ($version === $argumentCount - 1) {
                return 0;
            }
        }

        // luac.c: main / pmain
        $inputFiles = array_slice($argv, $i);
        if (count($inputFiles) <= 0) {
            self::usage('no input files given');
        }
        if (count($inputFiles) > 1) {
            self::fatal('only one input file is supported (luac.c combine() is not ported)');
        }
        $proto = self::loadFile($inputFiles[0] === '-' ? null : $inputFiles[0]);
        if ($listing) {
            echo Listing::printFunction($proto, $listing > 1);
        }
        if ($dumping) {
            $bytes = Dump::dump($proto, $stripping);
            if ($output === null) {
                fwrite(STDOUT, $bytes);
                return 0;
            }
            $outputHandle = @fopen(CFile::plainPath($output), 'wb');  // (a plain path, as for C's fopen)
            if ($outputHandle === false) {
                self::fatal("cannot open $output: " . self::lastSystemError());  // luac.c: cannot("open")
            }
            if (@fwrite($outputHandle, $bytes) !== strlen($bytes)) {
                self::fatal("cannot write $output: " . self::lastSystemError());  // luac.c: cannot("write")
            }
            if (!@fclose($outputHandle)) {
                self::fatal("cannot close $output: " . self::lastSystemError());  // luac.c: cannot("close")
            }
        }
        return 0;
    }

    // luac.c: fatal
    private static function fatal(string $message): never
    {
        fwrite(STDERR, self::$programName . ": $message\n");
        exit(1);
    }

    // luac.c: usage
    private static function usage(string $message): never
    {
        $programName = self::$programName;
        if ($message !== '' && $message[0] === '-') {
            fwrite(STDERR, "$programName: unrecognized option '$message'\n");
        } else {
            fwrite(STDERR, "$programName: $message\n");
        }
        fwrite(STDERR, "usage: $programName [options] [filenames]\n"
            . "Available options are:\n"
            . "  -l       list (use -l -l for full listing)\n"
            . "  -o name  output to file 'name' (default is \"" . self::DEFAULT_OUTPUT . "\")\n"
            . "  -p       parse only\n"
            . "  -s       strip debug information\n"
            . "  -v       show version information\n"
            . "  --       stop handling options\n"
            . "  -        stop handling options and process stdin\n");
        exit(1);
    }

    // The OS error text from PHP's last warning ("...: No such file or directory").
    private static function lastSystemError(): string
    {
        $lastError = error_get_last()['message'] ?? '';
        $separatorPosition = strrpos($lastError, ': ');
        return $separatorPosition === false ? $lastError : substr($lastError, $separatorPosition + 2);
    }

    /**
     * lauxlib.c: luaL_loadfilex (with skipBOM and skipcomment) for luac.
     * $filename null means stdin.
     */
    private static function loadFile(?string $filename): Proto
    {
        if ($filename === null) {
            $chunkname = '=stdin';
            $contents = stream_get_contents(STDIN);
        } else {
            $chunkname = '@' . $filename;
            $path = CFile::plainPath($filename);  // a plain path, as for C's fopen: never a stream wrapper
            if (is_dir($path)) {
                // C's fopen succeeds on a directory; the read then fails with EISDIR
                self::fatal("cannot read $filename: Is a directory");
            }
            $contents = @file_get_contents($path);
            if ($contents === false) {
                self::fatal("cannot open $filename: " . self::lastSystemError());
            }
        }

        // lauxlib.c: skipBOM (an incomplete BOM still consumes what it matched)
        $position = 0;
        if (($contents[0] ?? '') === "\xEF") {
            $position = 1;
            if (($contents[1] ?? '') === "\xBB") {
                $position = 2;
                if (($contents[2] ?? '') === "\xBF") {
                    $position = 3;
                }
            }
            if ($position < 3) {
                // not a valid BOM: C returns the 0xEF, then continues after the consumed bytes
                $contents = "\xEF" . substr($contents, $position === 1 ? 2 : 3);
                $position = 0;
            }
        }
        $prefix = '';
        // lauxlib.c: skipcomment (first line starting with '#')
        if (($contents[$position] ?? '') === '#') {
            $newlinePosition = strpos($contents, "\n", $position);
            $position = $newlinePosition === false ? strlen($contents) : $newlinePosition + 1;
            $prefix = "\n";  // add newline to correct line numbers
        }
        $chunk = substr($contents, $position);

        if (($chunk[0] ?? '') === Undump::LUA_SIGNATURE[0]) {  // binary file?
            try {
                return Undump::undump($chunk, $chunkname);
            } catch (CompileError $error) {
                self::fatal($error->getMessage());
            }
        }
        try {
            return Compiler::compile($prefix . $chunk, $chunkname, 1); // luac.c parses at C-call depth 1
        } catch (CompileError $error) {
            self::fatal($error->getMessage());
        }
    }
}
