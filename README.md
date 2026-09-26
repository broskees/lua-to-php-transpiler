# lua-to-php-transpiler

Transpiles Lua 5.4 to PHP. The generated PHP behaves like Lua 5.4.9: it passes
Lua's official 5.4.9 test suite. The transpiler and its runtime are plain PHP,
with no Composer packages and no extensions.

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
- Command line only for now: generated scripts read `$argv` and do not run as
  web pages yet.
- No C modules: `package.loadlib` always fails.
- Deep recursion raises Lua's "stack overflow" at about 100,000 levels, where
  `lua5.4` allows about 1,000,000, and each level costs a few KB of memory.
  With less `memory_limit` the limit comes sooner (about 19,000 levels at
  PHP's default 128M), so that it is still Lua's catchable error.
- Transpiled scripts keep the `memory_limit` PHP was started with (`bin/lua`
  raises it to 4G, or to the host's `max_memory_limit`). A library call that
  would build a result too large for the memory left (`string.rep`,
  `table.concat`, `string.format`, `..`, `io.read`, ...) raises Lua's
  catchable "not enough memory", as `lua5.4` does when an allocation fails.
  Memory that grows a little at a time (tables, closures) still ends in PHP's
  fatal error at the limit.
- A deployed program cannot `dofile` or `require` Lua files it writes while
  running: they were never transpiled, so it gets a "not transpiled ahead of
  time" error.
- Shared hosting: `bin/lua2php`, its output and `bin/lua` run under a typical
  host's `disable_functions` (`ini_set`, `set_time_limit`, `getmypid`,
  `putenv`, `symlink`, `link`, `dl`, `exec`, `system`, `shell_exec`,
  `passthru`, `popen`, the `proc_*` functions, every `posix_*` and `pcntl_*`
  function). Without `ini_set` the host's settings stay: the JIT
  stays as configured, `bin/lua` keeps the host's `memory_limit`, and each
  coroutine reserves PHP's default 2 MB of address space instead of 256 KB
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
tests/          unit and differential tests
bench/          memory and speed benchmarks
reference/      Lua 5.4.9 source and official test suites (unchanged)
```

## License

MIT, see [LICENSE](LICENSE). `reference/` contains Lua's source code and test
suites, Copyright (C) 1994-2026 Lua.org, PUC-Rio, under Lua's own MIT license.
