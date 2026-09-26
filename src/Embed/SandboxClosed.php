<?php

declare(strict_types=1);

namespace LuaPhp\Embed;

/**
 * A sandbox (or a handle of one) was used after it was closed: by
 * Sandbox::close(), at the end of Environment::run(), or because a
 * LimitExceeded or a PHP exception stopped a run in it.
 */
final class SandboxClosed extends \LogicException
{
}
