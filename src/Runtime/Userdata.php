<?php

declare(strict_types=1);

namespace LuaPhp\Runtime;

/**
 * A full userdata (C: Udata in lobject.h): an opaque PHP payload with a
 * metatable and user values (lua_newuserdatauv).
 *
 * @internal
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

    /** freeing a long chain through userdata must not recurse: see Teardown */
    public function __destruct()
    {
        if ($this->payload === null && $this->userValues === [] && $this->metatable === null) {
            return;
        }
        if (Teardown::$releasing) {
            if ($this->payload !== null) {
                Teardown::$pending[] = $this->payload;
            }
            if ($this->userValues !== []) {
                Teardown::$pending[] = $this->userValues;
            }
            if ($this->metatable !== null) {
                Teardown::$pending[] = $this->metatable;
            }
            return;
        }
        Teardown::$releasing = true;
        $this->payload = $this->metatable = null;
        $this->userValues = [];
        Teardown::release();
    }
}
