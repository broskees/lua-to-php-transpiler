<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;

/**
 * Port of linit.c: luaL_openlibs. Libraries not ported yet are skipped.
 */
final class StandardLibraries
{
    public static function openAll(Coroutine $L): void
    {
        $globals = $L->globalState->globals;
        // linit.c: loadedlibs order
        Auxiliary::registerLoaded($L, Lua::LUA_GNAME, BaseLib::open($L));
        $libraries = [
            'package' => PackageLib::open(...),
            'coroutine' => CoroutineLib::open(...),
            'table' => TableLib::open(...),
            'io' => IoLib::open(...),
            'os' => OsLib::open(...),
            'string' => StringLib::open(...),
            'math' => MathLib::open(...),
            'utf8' => Utf8Lib::open(...),
            'debug' => DebugLib::open(...),
        ];
        foreach ($libraries as $name => $opener) {
            $module = $opener($L);
            Auxiliary::registerLoaded($L, $name, $module);  // package.loaded[name] = module
            $globals->hash[$name] = $module;  // set global name
        }
    }
}
