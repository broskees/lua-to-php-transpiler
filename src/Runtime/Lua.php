<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * Constants from lua.h, luaconf.h, llimits.h and lstate.h, under their C
 * names so C code ports mechanically.
 */
final class Lua
{
    // lua.h: version
    public const LUA_VERSION = 'Lua 5.4';
    public const LUA_RELEASE = 'Lua 5.4.9';
    public const LUA_COPYRIGHT = 'Lua 5.4.9  Copyright (C) 1994-2026 Lua.org, PUC-Rio';

    // lua.h: thread status
    public const LUA_OK = 0;
    public const LUA_YIELD = 1;
    public const LUA_ERRRUN = 2;
    public const LUA_ERRSYNTAX = 3;
    public const LUA_ERRMEM = 4;
    public const LUA_ERRERR = 5;
    public const LUA_ERRFILE = 6;

    // lua.h: basic types
    public const LUA_TNONE = -1;
    public const LUA_TNIL = 0;
    public const LUA_TBOOLEAN = 1;
    public const LUA_TLIGHTUSERDATA = 2;
    public const LUA_TNUMBER = 3;
    public const LUA_TSTRING = 4;
    public const LUA_TTABLE = 5;
    public const LUA_TFUNCTION = 6;
    public const LUA_TUSERDATA = 7;
    public const LUA_TTHREAD = 8;

    // ltm.c: luaT_typenames_ (index = type + 1, so "no value" is LUA_TNONE)
    public const TYPE_NAMES = [
        'no value', 'nil', 'boolean', 'userdata', 'number',
        'string', 'table', 'function', 'userdata', 'thread',
    ];

    // lua.h: option for multiple returns
    public const LUA_MULTRET = -1;

    // lua.h: minimum Lua stack available to a C function
    public const LUA_MINSTACK = 20;

    // luaconf.h / llimits.h / lstate.h: stack limits
    public const LUAI_MAXSTACK = 1000000;
    public const ERRORSTACKSIZE = self::LUAI_MAXSTACK + 200;
    public const LUAI_MAXCCALLS = 200;

    // lua.h: event codes and masks for hooks
    public const LUA_HOOKCALL = 0;
    public const LUA_HOOKRET = 1;
    public const LUA_HOOKLINE = 2;
    public const LUA_HOOKCOUNT = 3;
    public const LUA_HOOKTAILCALL = 4;
    public const LUA_MASKCALL = 1 << self::LUA_HOOKCALL;
    public const LUA_MASKRET = 1 << self::LUA_HOOKRET;
    public const LUA_MASKLINE = 1 << self::LUA_HOOKLINE;
    public const LUA_MASKCOUNT = 1 << self::LUA_HOOKCOUNT;

    // lstate.h: bits in CallInfo status
    public const CIST_OAH = 1 << 0;
    public const CIST_C = 1 << 1;
    public const CIST_FRESH = 1 << 2;
    public const CIST_HOOKED = 1 << 3;
    public const CIST_YPCALL = 1 << 4;
    public const CIST_TAIL = 1 << 5;
    public const CIST_HOOKYIELD = 1 << 6;
    public const CIST_FIN = 1 << 7;
    public const CIST_TRAN = 1 << 8;
    public const CIST_CLSRET = 1 << 9;
    public const CIST_LEQ = 1 << 13;

    // lauxlib.h / lualib.h: names
    public const LUA_GNAME = '_G';
    public const LUA_LOADED_TABLE = '_LOADED';
    public const LUA_PRELOAD_TABLE = '_PRELOAD';
    public const LUA_ENV = '_ENV';

    // llimits.h: maximum length of a short string
    public const LUAI_MAXSHORTLEN = 40;

    // luaconf.h: LUA_MAXINTEGER / LUA_MININTEGER
    public const LUA_MAXINTEGER = PHP_INT_MAX;
    public const LUA_MININTEGER = PHP_INT_MIN;
}
