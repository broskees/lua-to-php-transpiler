<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

use LuaPhp\Emitter\Emitter;
use LuaPhp\Emitter\PhpLiteral;

/**
 * A directory of compiled chunks on disk, shared by load()'s byte cache
 * (LoadCache) and the embedding API's compile cache (Embed\Internal\
 * CompileCache): one generated PHP file per chunk, loaded with include,
 * so opcache keeps its compiled code across requests and the JIT can
 * compile it.
 *
 * An entry holds a chunk's Protos as a Lua binary chunk (null for a
 * binary chunk: the chunk itself is one), how deep the parser's nesting
 * went, and the factory of its PHP code (see Emitter), under the caller's
 * key. Entries are "<sha256>.php", the hash of a fingerprint of this code
 * (every PHP file under src/ and the PHP version) and the key: an entry
 * written by other code is never found.
 *
 * Like ssh's StrictModes, no other user may be able to change or replace
 * the directory: it must be ours, have no group/other permissions and not
 * be a symbolic link, and every directory above it must be owned by root
 * or us and not writable by group/others unless sticky (as /tmp is). It is
 * created (mode 0700) if missing, checked once (when first used), and then
 * used by its real path. Otherwise there is no disk cache, silently.
 * Robustness:
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
 *   pass the entry or byte limit empties the directory first; an entry
 *   bigger than the byte limit is not written.
 *
 * @internal
 */
final class CacheDirectory
{
    private const ENTRY_FORMAT = 'luaphp-load-cache-1';

    private const USAGE_FILE = 'usage';

    private static ?string $fingerprint = null;

    /** the directory asked for (absolute, no trailing slash), or null for the default */
    private readonly ?string $directory;

    private bool $checked = false;

    /** the real path of the directory once it passed the checks, else null */
    private ?string $trustedDirectory = null;

    /**
     * $directory: relative to the working directory now, or null for the
     * default directory of scripts bin/lua2php generates: "luaphp-<uid>" in
     * the system's temporary directory (found when first needed).
     */
    public function __construct(?string $directory)
    {
        if ($directory !== null) {
            if ($directory === '' || $directory[0] !== '/') {
                $directory = (getcwd() ?: '.') . '/' . $directory;
            }
            $directory = rtrim($directory, '/') ?: '/';  // "link/" would make lstat follow the link
        }
        $this->directory = $directory;
    }

    /** @return ?array{?string, int, \Closure} the entry with $key: [Protos as a binary chunk, parser nesting, factory], or null */
    public function read(string $key): ?array
    {
        if (!$this->isUsable()) {
            return null;
        }
        ob_start();  // a corrupt entry may print
        try {
            $entry = @include $this->trustedDirectory . '/' . self::fileName($key);
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
        return [$entry['protos'], $entry['nesting'], $entry['factory']];
    }

    /**
     * Writes the entry with $key (see read) under the lock of the usage
     * file, keeping the directory within $entryLimit entries and
     * $byteLimit bytes (see the class comment). Any failure just leaves
     * the entry out.
     */
    public function write(string $key, ?string $protos, int $nesting, string $factorySource, int $entryLimit, int $byteLimit): void
    {
        if (!$this->isUsable()) {
            return;
        }
        $contents = "<?php\n\n// A compiled Lua chunk (LuaPhp\\Runtime\\CacheDirectory). Safe to delete.\n\n"
            . Emitter::PREAMBLE
            . "\nreturn [\n"
            . "    'format' => '" . self::ENTRY_FORMAT . "',\n"
            . "    'key' => '$key',\n"
            . "    'nesting' => $nesting,\n"
            . "    'protos' => " . ($protos === null ? 'null' : PhpLiteral::string($protos)) . ",\n"
            . "    'factory' => $factorySource,\n"
            . "];\n";
        $size = \strlen($contents);
        if ($size > $byteLimit) {
            return;
        }
        $directory = $this->trustedDirectory;
        $usage = @fopen("$directory/" . self::USAGE_FILE, 'c+');
        if ($usage === false) {
            return;
        }
        try {
            if (!flock($usage, LOCK_EX)) {
                return;
            }
            [$entries, $bytes] = self::readUsage($usage, $directory);
            if ($entries + 1 > $entryLimit || $bytes + $size > $byteLimit) {
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
            // PHP warnings may be exceptions here (Standalone::configurePhp): a disk full, say
        } finally {
            @fclose($usage);  // unlocks
        }
    }

    private static function fileName(string $key): string
    {
        return hash('sha256', self::fingerprint() . $key) . '.php';
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

    private function isUsable(): bool
    {
        if (!$this->checked) {
            $this->checked = true;
            $uid = self::effectiveUid();
            if ($uid !== null) {
                $directory = $this->directory ?? rtrim(sys_get_temp_dir(), '/') . "/luaphp-$uid";
                $this->trustedDirectory = self::trustedDirectory($directory, $uid);
            }
        }
        return $this->trustedDirectory !== null;
    }

    /**
     * The effective uid of this process: the owner of a file it creates.
     * (No posix extension needed: shared hosts often disable it.) Null if
     * no file can be created.
     */
    private static function effectiveUid(): ?int
    {
        $file = @tmpfile();
        if ($file === false) {
            return null;
        }
        $status = @fstat($file);
        fclose($file);
        return \is_array($status) ? $status['uid'] : null;
    }

    /**
     * Creates $directory (mode 0700) if missing, and returns its real path
     * if no user but us (and root) can change or replace it (see the class
     * comment), else null.
     */
    private static function trustedDirectory(string $directory, int $uid): ?string
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }
        clearstatcache();
        $status = @lstat($directory);
        if ($status === false || ($status['mode'] & 0170000) !== 0040000) {
            return null;  // gone, or a symbolic link
        }
        $realDirectory = realpath($directory);  // resolves symbolic links above it
        $status = $realDirectory === false ? false : @lstat($realDirectory);
        if ($status === false || ($status['mode'] & 0170000) !== 0040000 || $status['uid'] !== $uid || ($status['mode'] & 0077) !== 0) {
            return null;
        }
        $ancestor = $realDirectory;
        do {
            $ancestor = \dirname($ancestor);
            $status = @lstat($ancestor);
            if (
                $status === false
                || ($status['uid'] !== 0 && $status['uid'] !== $uid)
                || (($status['mode'] & 0022) !== 0 && ($status['mode'] & 01000) === 0)  // writable by others, not sticky
            ) {
                return null;
            }
        } while ($ancestor !== '/');
        return $realDirectory;
    }

    /**
     * Identifies this runtime and transpiler: a hash of every PHP file
     * under src/ (names and contents) and the PHP version. Computed once
     * per process, when a disk cache is first used (about 1 ms).
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
