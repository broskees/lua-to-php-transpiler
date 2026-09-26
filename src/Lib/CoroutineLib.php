<?php

declare(strict_types=1);

namespace LuaPhp\Lib;

use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Calls;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\Lua;
use LuaPhp\Runtime\LuaError;
use LuaPhp\Runtime\LuaTable;
use LuaPhp\Runtime\NativeFunction;

/**
 * Port of lcorolib.c: the coroutine library. The thread machinery
 * (lua_resume, lua_yield, lua_closethread) is in Coroutine.
 *
 * @internal
 */
final class CoroutineLib
{
    // lcorolib.c: COS_* and statname
    private const COS_RUN = 0;
    private const COS_DEAD = 1;
    private const COS_YIELD = 2;
    private const COS_NORM = 3;
    private const STATUS_NAMES = ['running', 'dead', 'suspended', 'normal'];

    /**
     * the function of every wrap() closure: one PHP closure for all of
     * them (each would cost about 750 bytes), which, like C's, finds its
     * coroutine in its upvalue
     */
    private static ?\Closure $auxwrapFunction = null;

    // lcorolib.c: luaopen_coroutine
    public static function open(Coroutine $L): LuaTable
    {
        $library = new LuaTable();
        Auxiliary::setFunctions($library, [
            'create' => self::create(...),
            'resume' => self::resume(...),
            'running' => self::running(...),
            'status' => self::status(...),
            'wrap' => self::wrap(...),
            'yield' => self::yield(...),
            'isyieldable' => self::isyieldable(...),
            'close' => self::close(...),
        ]);
        return $library;
    }

    // lcorolib.c: getco
    private static function getco(Coroutine $L, array $args): Coroutine
    {
        $co = $args[0] ?? null;
        Auxiliary::argExpected($L, $co instanceof Coroutine, $args, 1, 'thread');
        return $co;
    }

    /**
     * lcorolib.c: auxresume: resume $co with $arguments. Returns [true,
     * values yielded or returned] or [false, error value].
     *
     * @param list<mixed> $arguments
     * @return array{bool, mixed}
     */
    private static function auxresume(Coroutine $L, Coroutine $co, array $arguments): array
    {
        if (!Calls::checkStack($co, \count($arguments))) {
            return [false, 'too many arguments to resume'];
        }
        [$status, $values] = $co->resume($L, $arguments);
        if ($status === Lua::LUA_OK || $status === Lua::LUA_YIELD) {
            if (!Calls::checkStack($L, \count($values) + 1)) {
                return [false, 'too many results to resume'];
            }
            return [true, $values];
        }
        return [false, $values];  // error message
    }

    // lcorolib.c: luaB_coresume
    private static function resume(Coroutine $L, array $args): array
    {
        $co = self::getco($L, $args);
        [$succeeded, $values] = self::auxresume($L, $co, \array_slice($args, 1));
        if (!$succeeded) {
            return [false, $values];  // return false + error message
        }
        array_unshift($values, true);
        return $values;  // return true + 'resume' returns
    }

    // lcorolib.c: luaB_auxwrap
    private static function auxwrap(Coroutine $L, array $args): array
    {
        $co = $L->ci->func->upvalues[0];  // getco: lua_upvalueindex(1) of the running C closure
        [$succeeded, $values] = self::auxresume($L, $co, $args);
        if ($succeeded) {
            return $values;
        }
        $error = $values;
        $status = $co->status;
        if ($status !== Lua::LUA_OK && $status !== Lua::LUA_YIELD) {  // error in the coroutine?
            [$status, $error] = $co->closeThread($L);  // close its tbc variables
        }
        if ($status !== Lua::LUA_ERRMEM && \is_string($error)) {  // not a memory error and error object is a string?
            $error = Auxiliary::where($L, 1) . $error;  // add extra info, if available
        }
        LuaError::raise($error);  // propagate error
    }

    // lcorolib.c: luaB_cocreate
    private static function create(Coroutine $L, array $args): array
    {
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TFUNCTION);
        return [Coroutine::newThread($L, $args[0])];
    }

    // lcorolib.c: luaB_cowrap
    private static function wrap(Coroutine $L, array $args): array
    {
        Auxiliary::checkType($L, $args, 1, Lua::LUA_TFUNCTION);
        $co = Coroutine::newThread($L, $args[0]);
        self::$auxwrapFunction ??= self::auxwrap(...);
        return [new NativeFunction('auxwrap', self::$auxwrapFunction, [$co])];  // C closure with the coroutine as upvalue
    }

    // lcorolib.c: luaB_yield
    private static function yield(Coroutine $L, array $args): array
    {
        return $L->yield($args);
    }

    // lcorolib.c: auxstatus
    private static function auxstatus(Coroutine $L, Coroutine $co): int
    {
        if ($L === $co) {
            return self::COS_RUN;
        }
        switch ($co->status) {
            case Lua::LUA_YIELD:
                return self::COS_YIELD;
            case Lua::LUA_OK:
                if ($co->ci !== $co->baseCi) {  // does it have frames?
                    return self::COS_NORM;  // it is running
                }
                if ($co->body === null) {  // (C: lua_gettop(co) == 0)
                    return self::COS_DEAD;
                }
                return self::COS_YIELD;  // initial state
            default:  // some error occurred
                return self::COS_DEAD;
        }
    }

    // lcorolib.c: luaB_costatus
    private static function status(Coroutine $L, array $args): array
    {
        $co = self::getco($L, $args);
        return [self::STATUS_NAMES[self::auxstatus($L, $co)]];
    }

    // lcorolib.c: luaB_yieldable (lapi.c: lua_isyieldable)
    private static function isyieldable(Coroutine $L, array $args): array
    {
        $co = $args === [] ? $L : self::getco($L, $args);
        return [$co->nny === 0];
    }

    // lcorolib.c: luaB_corunning
    private static function running(Coroutine $L, array $args): array
    {
        return [$L, $L === $L->globalState->mainThread];
    }

    // lcorolib.c: luaB_close
    private static function close(Coroutine $L, array $args): array
    {
        $co = self::getco($L, $args);
        $status = self::auxstatus($L, $co);
        if ($status !== self::COS_DEAD && $status !== self::COS_YIELD) {  // normal or running coroutine
            Auxiliary::error($L, 'cannot close a ' . self::STATUS_NAMES[$status] . ' coroutine');
        }
        [$status, $error] = $co->closeThread($L);
        if ($status === Lua::LUA_OK) {
            return [true];
        }
        return [false, $error];
    }
}
