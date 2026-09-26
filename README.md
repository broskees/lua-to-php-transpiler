# lua-to-php-transpiler

Transpiles Lua 5.4 to PHP. The generated PHP behaves like Lua 5.4.9: it passes
Lua's official 5.4.9 test suite. The transpiler and its runtime are plain PHP,
with no Composer packages and no extensions. PHP applications can also run
untrusted Lua inside a request, sandboxed and with limits: see
[Embedding](#embedding).

```sh
bin/lua2php hello.lua -o hello.php
php hello.php
```

## Requirements

- PHP 8.4 or newer, from the command line. Developed on PHP 8.5; the official
  test suite also passes on 8.4. PHP 8.3 and older don't work.
- For running the tests: `lua5.4` and `luac5.4` (5.4.9). The tests compare
  against them.

## Usage

### One script

```sh
bin/lua2php script.lua -o script.php
php script.php arg1 arg2
```

This behaves like `lua5.4 script.lua arg1 arg2`: same output, same error
messages, same exit status.

### A project

```sh
bin/lua2php myproject/ -o build/
cd build && php main.php
```

Every `.lua` file becomes a `.php` file at the same path. Run them from
`build/` as you would run `lua5.4 main.lua` from `myproject/`. `require`,
`dofile` and `loadfile` load the precompiled `.php` files: nothing is
transpiled while the program runs. `lua2php` only converts code, so deploy
`build/` together with any data files your program reads.

Generated files need this repository's runtime (`src/`). Each file remembers
where the runtime was when it was generated. On another machine, set
`LUAPHP_HOME` to the directory that contains `src/` (or `LUAPHP_AUTOLOAD` to
`src/autoload.php`). The runtime must be the same version that generated the
files.

### `load()`

Code that only exists while the program runs, such as strings passed to
`load()`, is the one thing transpiled at run time. Each chunk is cached by its
exact bytes, in memory and on disk, so the same string is transpiled once. The
disk cache is `$LUAPHP_CACHE_DIR`, or `luaphp-<uid>` in the system temp
directory. It is used only if the directory is private (yours, no group or
other access, not a symlink, and no parent directory that other users can
change) and it is emptied when it passes 20,000 entries or 256 MB.

### Other tools

- `bin/lua script.lua` is a development tool that transpiles and runs in one
  step, with the same options as `lua5.4` (`-e`, `-l`, `-i`, `-v`, `-E`, `-W`,
  `-`).
- `bin/luac -l -l file.lua` prints a bytecode listing identical to
  `luac5.4 -l -l` (also `-p`, `-o`, `-s`).

## Embedding

`LuaPhp\Embed` runs Lua inside a PHP application (a web request, a worker),
for code the application does not trust: store owners' discount rules,
report scripts, functions an AI agent wrote. The application decides which
PHP functions scripts may call, and every run has limits. Nothing in the
host process changes: no `ini_set`, no handler or output buffer left
behind, no output.

```php
require 'path/to/src/autoload.php';

use LuaPhp\Embed\{Environment, Limits, RunContext, ScriptError};
use LuaPhp\Embed\Loader\FilesystemLoader;

$env = new Environment(                          // once per process
    loader: new FilesystemLoader('/srv/shop/scripts'),
    cacheDir: '/srv/shop/var/lua-cache',
    limits: new Limits(steps: 5_000_000, memoryBytes: 32 << 20, seconds: 2.0, outputBytes: 1 << 20),
);
$env->addModule('shop.orders', fn (RunContext $ctx): array => [
    'recent' => function (int $limit) use ($ctx): array {
        if ($limit > 100) {
            throw new ScriptError('orders.recent: limit must be 100 or less');
        }
        return $ctx->get('orders')->recentFor($ctx->get('customerId'), $limit);
    },
]);

$result = $env->run('discounts/loyalty.lua', args: [['total' => 180.0]],
    context: ['customerId' => 42, 'orders' => $orderRepository]);
$result->values;   // [['discount' => 18.0, 'reason' => 'loyal customer (5 orders)']]
$result->output;   // what the script printed
$result->usage;    // steps, peakMemoryBytes, milliseconds, outputBytes
```

The script says `local orders = require("shop.orders")` and gets the cart
as `...`. `$env->newSandbox()` gives one Lua state to use directly
(`setGlobal`, `getGlobal`, `load($code)->call()`, handles for tables and
functions); `LuaPhp\Embed\Lua::run('return 1 + 1')` is the one-liner.

- **Libraries.** `Libraries::SAFE` (the default): base without `load`,
  `dofile`, `loadfile` (`allowLoad: true` brings `load` back for text
  chunks), `collectgarbage` limited to collect/count/step, `require` and
  `package.loaded`, string without `string.dump`, table, math, utf8,
  coroutine, and `os.time`, `os.date`, `os.clock`, `os.difftime`.
  `Libraries::ALL` is everything, for trusted code. Or a list such as
  `['base', 'string', 'os.time']`.
- **PHP functions.** Only Closures become Lua functions; their parameter
  types check the arguments with Lua's own rules and messages (`int`,
  `float`, `string`, `bool`, `array`, `LuaTable`, `LuaFunction`, `mixed`,
  variadics, defaults). They return nothing (`void`), one value, or several
  with `Lua::multiple()`. `ScriptError` is a Lua error the script can
  `pcall`; any other exception stops the run and reaches your code
  unchanged: no `pcall` in the script sees it.
- **Values.** Lists become Lua sequences (0-based in PHP, 1-based in Lua),
  other arrays tables; Lua tables come back as arrays (raw contents, keys
  1..n as a list), functions as `LuaFunction`, coroutines and userdata as
  `LuaValue`. Other PHP objects, cycles, tables PHP arrays cannot hold
  (`{[1] = "a", ["1"] = "b"}`, boolean keys) and nesting beyond 100 levels
  are a `ConversionError` naming the path (`result[1].tags[3]`).
- **Limits** (`Limits`; `Limits::none()` for none): steps (about one per
  Lua instruction, plus library work in proportion to its input), memory
  (never more than `memory_limit` leaves), seconds, output bytes,
  coroutines alive (1,000 by default) and call depth (1,000 levels by
  default, then Lua's own catchable "stack overflow"). Going over one is
  `LimitExceeded` (`->limit`, `->usage`); no Lua code runs after it.
- **Errors.** `SyntaxError` (message as `lua5.4` prints it, `->chunkName`,
  `->luaLine`), `RuntimeError` (`->luaMessage`, `->value`, `->traceback`),
  `LimitExceeded`, `ConversionError`, and `SandboxClosed` for a sandbox
  used after a limit or a PHP exception stopped it. Pending `__gc`
  finalizers never run when a sandbox is closed or dropped.
- **Output.** Collected into `Result::$output` by default;
  `output: new StdoutSink()` echoes it, `output: $callable` receives it.
  `warn` writes there once a script turns warnings on (`warn("@on")`).
- **Scripts and caching.** A `Loader` (`FilesystemLoader`, which never
  reads outside its root, or `ArrayLoader`) finds scripts;
  `require("a.b")` tries host modules, then `a/b.lua`, then `a/b/init.lua`.
  Each script is compiled the first time its exact bytes appear, in memory
  and, with `cacheDir` (private to your user, as the `load()` cache), as PHP
  files that opcache keeps. `$env->compile($names)` compiles ahead of time
  and returns the syntax errors.

Measured: a new sandbox 0.04 ms and 75 KB, a small script with a module
and a PHP function 0.1 ms, limits on 3 to 7% slower than off (13% with the
tracing JIT) ([bench/results/embed.md](bench/results/embed.md)).

## How it works

Instead of translating Lua syntax straight into PHP, the transpiler ports Lua's
own compiler (`llex.c`, `lparser.c`, `lcode.c`) to PHP and produces exactly the
bytecode `luac5.4` produces. Each Lua function then becomes one PHP function,
with the Lua registers in an array and jumps as `goto`. The runtime and the
standard libraries are PHP ports of Lua's C sources (`lvm.c`, `ldo.c`,
`lstrlib.c` and so on).

The reason for the bytecode step: Lua's official tests inspect the virtual
machine closely. They read register names through `debug.getlocal`, count
line-hook events, check variable names in error messages and round-trip
functions through `string.dump`. Producing the same bytecode as `luac5.4` is
what makes all of that exact, and checkable against `luac5.4 -l -l`.

Architecture notes and conventions for contributors are in
[AGENTS.md](AGENTS.md).

## Performance

Measured on the workloads in `bench/`, running the saved PHP with opcache,
against `lua5.4`:

- Speed: roughly 5 to 25 times slower than `lua5.4`. String and table code is
  at the fast end, code dominated by function calls at the slow end. PHP's
  tracing JIT (`opcache.jit=tracing`) makes it 1.3 to 2 times faster.
- Transpiling: about 30 ms per 1,000 lines of Lua, plus PHP's startup.
- Memory: arrays and strings take about what Lua takes; tables and closures
  1.5 to 2.5 times as much; a suspended coroutine about 10 KB.

## Compatibility and limits

- Checked against the official Lua 5.4.9 test suite in its basic mode: from
  `reference/lua-5.4.9-tests/`, `php ../../bin/lua -e"_U=true" all.lua`
  prints `final OK !!!`, as `lua5.4` does. The suite's full mode needs C
  libraries and Lua's internal test API, so it is out of scope.
- `bin/lua2php` output is for the command line: generated scripts read
  `$argv` and do not run as web pages yet. To run Lua inside a web request,
  use the [embedding API](#embedding).
- No C modules: `package.loadlib` always fails.
- Deep recursion raises Lua's "stack overflow" at about 100,000 levels, where
  `lua5.4` allows about 1,000,000, and each level costs a few KB of memory.
  With less `memory_limit` the limit comes sooner (about 19,000 levels at
  PHP's default 128M), so that it is still Lua's catchable error. In a
  sandbox, `Limits::$callDepth` (1,000 by default) sets it.
- Transpiled scripts and sandboxes keep the `memory_limit` PHP was started
  with (`bin/lua` raises it to 4G, or to the host's `max_memory_limit`). A library call that
  would build a result too large for the memory left (`string.rep`,
  `table.concat`, `string.format`, `..`, `io.read`, ...) raises Lua's
  catchable "not enough memory", as `lua5.4` does when an allocation fails.
  Memory that grows a little at a time (tables, closures) still ends in PHP's
  fatal error at the limit, except in a sandbox with limits, where it is
  `LimitExceeded('memory')` before `memory_limit` is reached.
- A deployed program cannot `dofile` or `require` Lua files it writes while
  running: they were never transpiled, so it gets a "not transpiled ahead of
  time" error.
- Shared hosting: `bin/lua2php`, its output, `bin/lua` and the embedding API
  run under a typical
  host's `disable_functions` (`ini_set`, `set_time_limit`, `getmypid`,
  `putenv`, `symlink`, `link`, `dl`, `exec`, `system`, `shell_exec`,
  `passthru`, `popen`, the `proc_*` functions, every `posix_*` and `pcntl_*`
  function). Without `ini_set` the host's settings stay: the JIT
  stays as configured, `bin/lua` keeps the host's `memory_limit`, and each
  coroutine reserves PHP's default 2 MB of address space instead of 256 KB
  (as in a sandbox, which never changes `fiber.stack_size`)
  (`php -d fiber.stack_size=256K` restores it; where the address space is
  capped, a coroutine that cannot get its stack fails with Lua's "not enough
  memory").
  Without `proc_open`, `proc_get_status` or `proc_close`, `os.execute(cmd)`
  returns `fail, "Function not implemented", 38`, `io.popen(cmd)` returns
  `fail, "cmd: Function not implemented", 38`, and `os.execute()` returns
  `false` (no shell).

## Tests

```sh
php tests/run.php     # unit tests, plus every tests/diff case compared with lua5.4
tests/bytecode.sh     # bin/luac listings compared with luac5.4 for the official test files
tests/official.sh     # the official test files, one at a time
```

Each differential case in `tests/diff/` runs under `lua5.4` and under this
project, both through `bin/lua` and through `bin/lua2php` output. Output,
errors and exit status must be identical.

## Layout

```
bin/            lua2php, lua, luac
src/Compiler/   lexer, parser, code generator, bytecode dump and undump
src/Emitter/    bytecode to PHP
src/Runtime/    values, tables, calls, errors, coroutines, metamethods, debug info, GC
src/Lib/        the standard libraries
src/Embed/      the embedding API (the only public classes)
tests/          unit and differential tests
bench/          memory and speed benchmarks
reference/      Lua 5.4.9 source and official test suites (unchanged)
```

## License

MIT, see [LICENSE](LICENSE). `reference/` contains Lua's source code and test
suites, Copyright (C) 1994-2026 Lua.org, PUC-Rio, under Lua's own MIT license.
