<?php

declare(strict_types=1);

// Loaded with `php -d auto_prepend_file=tests/aot/guard.php ...`: exits with
// status 97 the moment a class of the transpiler is loaded, i.e. when code
// is compiled or emitted at run time. Undump, Dump, Proto and the other data
// classes of LuaPhp\Compiler are allowed.
spl_autoload_register(static function (string $className): void {
    if (preg_match('/^LuaPhp\\\\(Compiler\\\\(Compiler|Lexer|Parser|CodeGen)|Emitter\\\\.+)$/', $className) === 1) {
        fwrite(STDERR, "GUARD: $className loaded at run time\n");
        exit(97);
    }
});
