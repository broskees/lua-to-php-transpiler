<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A full userdata (C: Udata in lobject.h): an opaque PHP payload with a
 * metatable and user values (lua_newuserdatauv).
 */
final class Userdata
{
    public ?LuaTable $metatable = null;

    /**
     * @param list<mixed> $userValues
     */
    public function __construct(
        public mixed $payload = null,
        public array $userValues = [],
    ) {
    }
}
