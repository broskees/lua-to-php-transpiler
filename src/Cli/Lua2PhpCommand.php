<?php

declare(strict_types=1);

namespace LuaPhp\Cli;

use FilesystemIterator;
use LuaPhp\Compiler\ChunkId;
use LuaPhp\Compiler\CompileError;
use LuaPhp\Compiler\Compiler;
use LuaPhp\Compiler\Dump;
use LuaPhp\Compiler\Undump;
use LuaPhp\Emitter\Emitter;
use LuaPhp\Emitter\PhpLiteral;
use LuaPhp\Runtime\ChunkLoader;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\Standalone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * bin/lua2php: transpiles Lua 5.4 to PHP ahead of time (see bin/lua2php
 * for its usage and what the generated files do).
 *
 * @internal
 */
final class Lua2PhpCommand
{
    private static string $programName = 'lua2php';

    /**
     * Refuses a JIT mode that can compute wrong results first
     * (Standalone::refusesJit): then nothing is transpiled or written.
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        self::$programName = (isset($argv[0]) && $argv[0] !== '') ? $argv[0] : 'lua2php';
        if (Standalone::refusesJit(self::$programName)) {
            return 1;
        }

        Standalone::raiseMemoryLimit();

        $input = null;
        $output = null;
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '-o') {
                $output = $argv[++$i] ?? self::usage("'-o' needs argument");
            } elseif ($input === null && $argv[$i] !== '') {
                $input = $argv[$i];
            } else {
                self::usage("unexpected argument '{$argv[$i]}'");
            }
        }
        if ($input === null) {
            self::usage('no input file given');
        }

        if (!is_dir($input)) {
            [$php, $errorMessage] = self::transpile($input, $input);
            if ($errorMessage !== null) {
                self::fail($errorMessage);
            }
            try {
                self::writeFile($output ?? ChunkLoader::precompiledFileName($input), $php);
            } catch (RuntimeException $error) {
                self::fail($error->getMessage());
            }
            return 0;
        }

        // directory mode: every *.lua below $input, in a stable order
        $inputDirectory = $input === '/' ? '' : rtrim($input, '/');
        $outputDirectory = $output === null ? $inputDirectory : rtrim($output, '/');
        $luaFiles = [];
        $directoryIterator = new RecursiveDirectoryIterator(
            $inputDirectory === '' ? '/' : $inputDirectory,
            FilesystemIterator::SKIP_DOTS,
        );
        $iterator = new RecursiveIteratorIterator($directoryIterator);
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.lua')) {
                $luaFiles[] = substr($file->getPathname(), strlen($inputDirectory) + 1);
            }
        }
        sort($luaFiles, SORT_STRING);
        $failed = false;
        foreach ($luaFiles as $relativePath) {
            try {
                [$php, $errorMessage] = self::transpile("$inputDirectory/$relativePath", $relativePath);
                if ($errorMessage !== null) {
                    fwrite(STDERR, self::$programName . ": $errorMessage\n");
                    $failed = true;
                }
                if ($php !== null) {
                    self::writeFile($outputDirectory . '/' . ChunkLoader::precompiledFileName($relativePath), $php);
                }
            } catch (Throwable $error) {  // one file that fails does not stop the others
                fwrite(STDERR, self::$programName . ": $relativePath: " . $error->getMessage() . "\n");
                $failed = true;
            }
        }
        return $failed ? 1 : 0;
    }

    private static function fail(string $message): never
    {
        fwrite(STDERR, self::$programName . ": $message\n");
        exit(1);
    }

    private static function usage(string $message): never
    {
        $programName = self::$programName;
        fwrite(STDERR, "$programName: $message\n"
            . "usage: $programName input.lua [-o output.php]\n"
            . "       $programName directory [-o output-directory]\n");
        exit(1);
    }

    /**
     * The PHP file for the Lua file $inputFile, run as the script
     * $scriptName: [PHP source, null], or [PHP source that raises the load
     * error, error message] when it does not compile. Null source: the file
     * cannot be read.
     *
     * @return array{?string, ?string}
     */
    private static function transpile(string $inputFile, string $scriptName): array
    {
        // lauxlib.c: luaL_loadfilex (skip a UTF-8 BOM and a first line starting with '#')
        $contents = is_dir($inputFile) ? false : @file_get_contents($inputFile);
        if ($contents === false) {
            return [null, "cannot open $inputFile"];
        }
        $position = str_starts_with($contents, "\xEF\xBB\xBF") ? 3 : 0;
        $prefix = '';
        if (($contents[$position] ?? '') === '#') {
            $newlinePosition = strpos($contents, "\n", $position);
            $position = $newlinePosition === false ? strlen($contents) : $newlinePosition + 1;
            $prefix = "\n";  // keep line numbers
        }
        $chunk = substr($contents, $position);
        $chunkname = '@' . $scriptName;
        $isBinaryInput = ($chunk[0] ?? '') === Undump::LUA_SIGNATURE[0];
        $proto = null;
        $nesting = 0;
        $errorLiteral = 'null';
        $errorMessage = null;
        try {
            $proto = $isBinaryInput
                ? Undump::undump($chunk, $chunkname)
                : Compiler::compile($prefix . $chunk, $chunkname, Compiler::MAIN_CHUNK_C_CALLS, $nesting);
        } catch (CompileError $error) {
            // raised again when the file is loaded, with the chunk name it is loaded as
            $errorMessage = $error->getMessage();
            $status = match ($error->getCode()) {
                Lua::LUA_ERRRUN => Lua::LUA_ERRRUN,
                Lua::LUA_ERRERR => Lua::LUA_ERRERR,
                default => Lua::LUA_ERRSYNTAX,
            };
            $name = $isBinaryInput ? Undump::nameForErrors($chunkname) : ChunkId::of($chunkname);
            $followsName = str_starts_with($errorMessage, $name . ':');
            $storedMessage = $followsName ? substr($errorMessage, strlen($name)) : $errorMessage;
            $errorLiteral = "[$status, " . PhpLiteral::string($storedMessage) . ', '
                . ($followsName ? 'true' : 'false') . ']';
        }

        $runtimeAutoload = realpath(__DIR__ . '/../autoload.php');
        $preamble = Emitter::PREAMBLE;
        $format = PhpLiteral::string(ChunkLoader::PRECOMPILED_FORMAT);
        $binary = $isBinaryInput ? 'true' : 'false';
        $protos = $proto === null ? 'null' : PhpLiteral::string(Dump::dump($proto, false));
        $factory = $proto === null ? 'null' : Emitter::emitChunk($proto);
        $scriptNameLiteral = PhpLiteral::string($scriptName);
        $chunkLoader = '\\' . ChunkLoader::class;
        $runtimeLiteral = PhpLiteral::string($runtimeAutoload);
        $commentSafeInput = PhpLiteral::commentText($inputFile, 200);
        $commentSafeScript = PhpLiteral::commentText($scriptName, 200);
        $commentSafeRuntime = PhpLiteral::commentText($runtimeAutoload, 300);

        // the runtime includes no other PHP file, and this one only for this Lua file
        $slash = strrpos($inputFile, '/');
        $inputFileName = $slash === false ? $inputFile : substr($inputFile, $slash + 1);
        $header = ChunkLoader::PRECOMPILED_HEADER . rawurlencode($inputFileName);
        $php = <<<PHP
        $header
        // ($commentSafeInput). Do not edit.
        //
        // Run it like the Lua script:   php <this file> args...
        // (behaves like 'lua5.4 $commentSafeScript args...'; in the 'arg' table,
        // index 0 is the Lua script name and -1 is this PHP file).
        // The LuaPhp runtime also loads this file when the program requires,
        // dofiles or loadfiles the Lua file: then it returns the chunk below.
        //
        // Finding the runtime: this file needs the LuaPhp runtime (the src/
        // directory of the lua-to-php-transpiler). It loads, in this order:
        //   1. the file named by the environment variable LUAPHP_AUTOLOAD, or
        //      src/autoload.php inside the directory named by LUAPHP_HOME;
        //   2. the runtime this file was generated with:
        //      $commentSafeRuntime
        // The runtime must be the same version that generated this file.

        $preamble
        // the chunk (see LuaPhp\\Runtime\\ChunkLoader::loadPrecompiled)
        \$luaChunk = [
            'format' => $format,
            'binary' => $binary,
            'nesting' => $nesting,
            'error' => $errorLiteral,
            // the function prototypes (a Lua 5.4 binary chunk): debug information and long string constants
            'protos' => $protos,
            // the main function and all nested functions
            'factory' => $factory,
        ];
        if (\\class_exists({$chunkLoader}::class, false) && {$chunkLoader}::\$includingPrecompiled) {
            return \$luaChunk;  // loaded by require, dofile or loadfile
        }

        (static function (): void {
            \$autoload = getenv('LUAPHP_AUTOLOAD');
            if (\$autoload === false && getenv('LUAPHP_HOME') !== false) {
                \$autoload = rtrim(getenv('LUAPHP_HOME'), '/') . '/src/autoload.php';
            }
            if (\$autoload === false) {
                \$autoload = $runtimeLiteral;
            }
            if (!is_file(\$autoload)) {
                fwrite(STDERR, "cannot find the LuaPhp runtime at \$autoload (set LUAPHP_AUTOLOAD or LUAPHP_HOME)\\n");
                exit(1);
            }
            require_once \$autoload;
        })();

        \\LuaPhp\\Runtime\\Standalone::configurePhp();

        exit(\\LuaPhp\\Runtime\\Standalone::runCompiledScript(\$argv, $scriptNameLiteral, \$luaChunk));

        PHP;
        return [$php, $errorMessage];
    }

    /** writes $contents to $file atomically (a running program never includes half a file) */
    private static function writeFile(string $file, string $contents): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("cannot create directory $directory");
        }
        // (hosts may disable getmypid)
        $temporaryFile = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temporaryFile, $contents) !== strlen($contents) || !@rename($temporaryFile, $file)) {
            @unlink($temporaryFile);
            throw new RuntimeException("cannot write $file");
        }
    }
}
