<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Emitter\Emitter;
use LuaPhp\Emitter\PhpLiteral;

/**
 * The byte cache of load() (ChunkLoader::load): compiled chunks by their
 * exact bytes and chunk name, so loading a chunk again compiles and emits
 * nothing. Two levels:
 *
 * - in memory, per process: at most $memoryEntryLimit entries, emptied
 *   when full (like ChunkLoader's factory cache);
 * - on disk, when a directory is set (useDirectory): one generated PHP
 *   file per chunk, loaded with include, so opcache keeps its compiled
 *   code across requests and the JIT can compile it.
 *
 * An entry holds the chunk's Protos as a Lua binary chunk (null for a
 * binary chunk: the chunk itself is one), how deep the parser's nesting
 * went, and the factory of its PHP code (see Emitter). A hit undumps fresh
 * Protos, sharing equal strings as a compile does, so two loads of the
 * same bytes look like two compiles (string.format('%p') tells their long
 * strings apart). A hit is used only if compiling at the current C-call
 * depth would succeed (ChunkLoader::nestingError); failed compiles are
 * never cached. The mode check ('t'/'b') is the caller's.
 *
 * Disk entries are "<sha256>.php", the hash of a fingerprint of this
 * code (every PHP file under src/ and the PHP version), the chunk name and
 * the chunk: an entry written by other code is never found. The directory
 * must be private: owned by this process's user, no permissions for
 * others, not a symbolic link; it is created (mode 0700) if missing.
 * Otherwise there is no disk cache, silently. Robustness:
 *
 * - a write is a temporary file renamed into place, so a reader never
 *   includes half an entry;
 * - an entry that fails to include, prints, throws or does not have the
 *   expected shape and key is a miss, and the store that follows replaces
 *   it;
 * - run directly (say, requested over HTTP), an entry only returns an
 *   array;
 * - size: the file "usage" (locked while writing) counts the entries and
 *   bytes written since the directory was last emptied. A write that would
 *   pass $diskEntryLimit entries or $diskByteLimit bytes empties the
 *   directory first; an entry bigger than the byte limit is not written.
 */
final class LoadCache
{
    /** at most this many entries in memory; tests lower it */
    public static int $memoryEntryLimit = 1000;

    /** at most this many entries on disk; tests lower it */
    public static int $diskEntryLimit = 20000;

    /** at most this many bytes of entries on disk; tests lower it */
    public static int $diskByteLimit = 256 * 1024 * 1024;

    private const ENTRY_FORMAT = 'luaphp-load-cache-1';

    private const USAGE_FILE = 'usage';

    /** @var array<string, array{?string, int, \Closure}> key => [Protos as a binary chunk, parser nesting, factory] */
    private static array $memory = [];

    private static ?string $directory = null;

    /** whether $directory is usable; null until checked */
    private static ?bool $directoryUsable = null;

    private static ?string $fingerprint = null;

    /**
     * Sets the disk cache directory (null: no disk cache) and empties the
     * cache in memory.
     */
    public static function useDirectory(?string $directory): void
    {
        if ($directory !== null && $directory !== '' && $directory[0] !== '/') {
            $directory = (getcwd() ?: '.') . '/' . $directory;  // include must not search include_path
        }
        self::$directory = $directory === '' ? null : $directory;
        self::$directoryUsable = null;
        self::$memory = [];
    }

    /**
     * The disk cache directory of scripts bin/lua2php generates when
     * LUAPHP_CACHE_DIR is not set: "luaphp-<uid>" in the system's
     * temporary directory. Null without the posix extension (no way to
     * tell whose a directory is).
     */
    public static function defaultDirectory(): ?string
    {
        if (!\function_exists('posix_geteuid')) {
            return null;
        }
        return rtrim(sys_get_temp_dir(), '/') . '/luaphp-' . posix_geteuid();
    }

    /** the key of $chunk loaded with chunk name $chunkname */
    public static function key(string $chunk, string $chunkname): string
    {
        $context = hash_init('sha256');  // in pieces: load(s) names the chunk s, which may be big
        hash_update($context, \strlen($chunkname) . ':');
        hash_update($context, $chunkname);
        hash_update($context, $chunk);
        return hash_final($context);
    }

    /**
     * The Protos (a binary chunk, or null: the chunk itself) and factory
     * of the chunk with $key, or null: not cached, or compiling it at
     * C-call depth $nCcalls would fail.
     *
     * @return ?array{?string, \Closure}
     */
    public static function find(string $key, int $nCcalls): ?array
    {
        $entry = self::$memory[$key] ?? self::readEntry($key);
        if ($entry === null) {
            return null;
        }
        [$protos, $nesting, $factory] = $entry;
        if (ChunkLoader::nestingError($nCcalls, $nesting) !== null) {
            return null;  // compile it again: that raises the error
        }
        return [$protos, $factory];
    }

    /**
     * Caches the chunk with $key: its Protos as a binary chunk (null for
     * a binary chunk), how deep its parser nesting went (see
     * Compiler::compile), its factory and the factory's PHP source.
     */
    public static function store(string $key, ?string $protos, int $nesting, \Closure $factory, string $factorySource): void
    {
        self::remember($key, [$protos, $nesting, $factory]);
        if (!self::directoryIsUsable()) {
            return;
        }
        $contents = "<?php\n\n// A chunk cached by load() (LuaPhp\\Runtime\\LoadCache). Safe to delete.\n\n"
            . Emitter::PREAMBLE
            . "\nreturn [\n"
            . "    'format' => '" . self::ENTRY_FORMAT . "',\n"
            . "    'key' => '$key',\n"
            . "    'nesting' => $nesting,\n"
            . "    'protos' => " . ($protos === null ? 'null' : PhpLiteral::string($protos)) . ",\n"
            . "    'factory' => $factorySource,\n"
            . "];\n";
        self::write($key, $contents);
    }

    /** the number of entries in memory */
    public static function memoryEntryCount(): int
    {
        return \count(self::$memory);
    }

    /** @param array{?string, int, \Closure} $entry */
    private static function remember(string $key, array $entry): array
    {
        if (\count(self::$memory) >= self::$memoryEntryLimit) {
            self::$memory = [];
        }
        return self::$memory[$key] = $entry;
    }

    /** @return ?array{?string, int, \Closure} the entry on disk, or null */
    private static function readEntry(string $key): ?array
    {
        if (!self::directoryIsUsable()) {
            return null;
        }
        ob_start();  // a corrupt entry may print
        try {
            $entry = @include self::$directory . '/' . self::fileName($key);
        } catch (\Throwable) {
            return null;
        } finally {
            ob_end_clean();
        }
        if (
            !\is_array($entry)
            || ($entry['format'] ?? null) !== self::ENTRY_FORMAT
            || ($entry['key'] ?? null) !== $key
            || !\is_int($entry['nesting'] ?? null)
            || !\array_key_exists('protos', $entry)
            || !($entry['protos'] === null || \is_string($entry['protos']))
            || !(($entry['factory'] ?? null) instanceof \Closure)
        ) {
            return null;
        }
        return self::remember($key, [$entry['protos'], $entry['nesting'], $entry['factory']]);
    }

    private static function fileName(string $key): string
    {
        return hash('sha256', self::fingerprint() . $key) . '.php';
    }

    /**
     * Writes an entry under the lock of the usage file (see the class
     * comment). Any failure just leaves the entry out.
     */
    private static function write(string $key, string $contents): void
    {
        $size = \strlen($contents);
        if ($size > self::$diskByteLimit) {
            return;
        }
        $directory = self::$directory;
        $usage = @fopen("$directory/" . self::USAGE_FILE, 'c+');
        if ($usage === false) {
            return;
        }
        try {
            if (!flock($usage, LOCK_EX)) {
                return;
            }
            [$entries, $bytes] = self::readUsage($usage, $directory);
            if ($entries + 1 > self::$diskEntryLimit || $bytes + $size > self::$diskByteLimit) {
                self::emptyDirectory($directory);
                [$entries, $bytes] = [0, 0];
            }
            $temporaryFile = "$directory/." . bin2hex(random_bytes(8)) . '.tmp';
            if (@file_put_contents($temporaryFile, $contents) !== $size || !@rename($temporaryFile, "$directory/" . self::fileName($key))) {
                @unlink($temporaryFile);
                return;
            }
            @ftruncate($usage, 0);
            @rewind($usage);
            @fwrite($usage, ($entries + 1) . ' ' . ($bytes + $size));
        } catch (\Throwable) {
            // PHP warnings are exceptions here (Standalone::configurePhp): a disk full, say
        } finally {
            @fclose($usage);  // unlocks
        }
    }

    /**
     * [entries, bytes] written since the directory was last emptied, from
     * the usage file; if it is new or unreadable, from the directory.
     *
     * @param resource $usage
     * @return array{int, int}
     */
    private static function readUsage($usage, string $directory): array
    {
        rewind($usage);
        if (preg_match('/^(\d+) (\d+)$/D', (string) stream_get_contents($usage), $match) === 1) {
            return [(int) $match[1], (int) $match[2]];
        }
        $entries = 0;
        $bytes = 0;
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name !== self::USAGE_FILE && $name[0] !== '.') {
                $entries++;
                $bytes += (int) @filesize("$directory/$name");
            }
        }
        return [$entries, $bytes];
    }

    /** removes everything but the usage file, whose lock the caller holds */
    private static function emptyDirectory(string $directory): void
    {
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && $name !== self::USAGE_FILE) {
                @unlink("$directory/$name");
            }
        }
    }

    private static function directoryIsUsable(): bool
    {
        self::$directoryUsable ??= self::$directory !== null && self::isPrivateDirectory(self::$directory);
        return self::$directoryUsable;
    }

    /**
     * Creates $directory (mode 0700) if missing; true if it is a directory
     * (not a symbolic link) owned by this process's user with no
     * permissions for group or others.
     */
    private static function isPrivateDirectory(string $directory): bool
    {
        if (!\function_exists('posix_geteuid')) {
            return false;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        clearstatcache(true, $directory);
        $status = @lstat($directory);
        return $status !== false
            && ($status['mode'] & 0170000) === 0040000
            && $status['uid'] === posix_geteuid()
            && ($status['mode'] & 0077) === 0;
    }

    /**
     * Identifies this runtime and transpiler: a hash of every PHP file
     * under src/ (names and contents) and the PHP version. Computed once
     * per process, when the disk cache is first used (about 1 ms).
     */
    private static function fingerprint(): string
    {
        if (self::$fingerprint !== null) {
            return self::$fingerprint;
        }
        $sourceDirectory = \dirname(__DIR__);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $context = hash_init('xxh128');
        hash_update($context, PHP_VERSION);
        foreach ($files as $file) {
            hash_update($context, "\0" . substr($file, \strlen($sourceDirectory)) . "\0");
            hash_update_file($context, $file);
        }
        return self::$fingerprint = hash_final($context);
    }
}
