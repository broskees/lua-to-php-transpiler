<?php

declare(strict_types=1);

namespace LuaPhp\Embed\Internal;

use LuaPhp\Embed\ConversionError;
use LuaPhp\Embed\LuaFunction;
use LuaPhp\Embed\LuaTable;
use LuaPhp\Embed\Multiple;
use LuaPhp\Runtime\Auxiliary;
use LuaPhp\Runtime\Coroutine;
use LuaPhp\Runtime\LuaClosure;
use LuaPhp\Runtime\LuaTable as RuntimeTable;
use LuaPhp\Runtime\NativeFunction;

/**
 * A PHP Closure as a Lua function. Its signature is read once, the first
 * time the Closure is passed to Lua (registered), and kept as long as the
 * Closure lives. Each parameter takes its argument as lauxlib.c's
 * luaL_check* functions would, with their messages:
 *
 *     int                 luaL_checkinteger (10, 10.0, "10")
 *     float               luaL_checknumber, as a float
 *     string              luaL_checklstring (numbers become strings)
 *     bool                any value: nil and false are false
 *     array               a table, converted to PHP (Convert::toPhp)
 *     LuaTable            a table, as a handle
 *     LuaFunction         a function, as a handle
 *     mixed or untyped    any value, converted
 *     ...$rest            the remaining arguments, each by its type
 *
 * A nullable parameter or one with a default takes nil or a missing
 * argument as null or its default (luaL_opt*). A Lua table that cannot be
 * converted is a bad argument ("table contains a cycle at self.parent").
 *
 * Results: a declared void (or never) returns no values, Lua::multiple()
 * several, anything else one value (null is nil, an array one table).
 *
 * @internal
 */
final class HostFunction
{
    private const INT = 0;
    private const FLOAT = 1;
    private const STRING = 2;
    private const BOOL = 3;
    private const ARRAY = 4;
    private const TABLE = 5;
    private const FUNCTION = 6;
    private const MIXED = 7;

    private const KINDS = [
        'int' => self::INT,
        'float' => self::FLOAT,
        'string' => self::STRING,
        'bool' => self::BOOL,
        'array' => self::ARRAY,
        LuaTable::class => self::TABLE,
        LuaFunction::class => self::FUNCTION,
        'mixed' => self::MIXED,
    ];

    /** @var \WeakMap<\Closure, HostFunction>|null */
    private static ?\WeakMap $signatures = null;

    /**
     * @param list<array{int, bool, bool, mixed}> $parameters [kind, takes nil (nullable or with a default), has a default, default]
     */
    private function __construct(
        private readonly \Closure $closure,
        private readonly array $parameters,
        private readonly bool $variadic,
        private readonly bool $returnsNothing,
    ) {
    }

    /** the Lua function spec of $closure, read once; $path names it in a ConversionError */
    public static function of(\Closure $closure, string $path): self
    {
        self::$signatures ??= new \WeakMap();
        return self::$signatures[$closure] ??= self::read($closure, $path);
    }

    private static function read(\Closure $closure, string $path): self
    {
        $function = new \ReflectionFunction($closure);
        $parameters = [];
        $variadic = false;
        foreach ($function->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type === null ? 'mixed' : ($type instanceof \ReflectionNamedType ? $type->getName() : (string) $type);
            $kind = self::KINDS[$typeName] ?? null;
            if ($kind === null || $parameter->isPassedByReference()) {
                $what = $parameter->isPassedByReference() ? 'by-reference parameter' : "parameter of type $typeName";
                throw new ConversionError("cannot pass a Closure with a $what (\$" . $parameter->getName() . ') to Lua', $path);
            }
            $hasDefault = $parameter->isDefaultValueAvailable();
            if ($parameter->isOptional() && !$hasDefault && !$parameter->isVariadic()) {
                throw new ConversionError('cannot pass a Closure whose default for $' . $parameter->getName() . ' is unknown to Lua', $path);
            }
            $takesNil = $hasDefault || ($type !== null && $type->allowsNull());
            $parameters[] = [$kind, $takesNil, $hasDefault, $hasDefault ? $parameter->getDefaultValue() : null];
            $variadic = $parameter->isVariadic();
        }
        $returnType = $function->getReturnType();
        $returnsNothing = $returnType instanceof \ReflectionNamedType && \in_array($returnType->getName(), ['void', 'never'], true);
        return new self($closure, $parameters, $variadic, $returnsNothing);
    }

    /** this function as a Lua function of the sandbox $state; $name is for PHP-side debugging and paths */
    public function bind(State $state, string $name): NativeFunction
    {
        return new NativeFunction($name, function (Coroutine $L, array $args) use ($state, $name): array {
            $state->chargeSteps(1);  // a PHP function costs one step, plus what it charges itself
            $phpArguments = $this->arguments($L, $state, $args);
            $result = $state->callHost($L, fn (): mixed => ($this->closure)(...$phpArguments));
            if ($this->returnsNothing) {
                return [];
            }
            if ($result instanceof Multiple) {
                $results = [];
                foreach ($result->values as $index => $value) {
                    $results[] = Convert::toLua($value, "$name()#" . ($index + 1), $state);
                }
                return $results;
            }
            return [Convert::toLua($result, "$name()", $state)];
        });
    }

    /**
     * The PHP arguments for Lua's $args, checked parameter by parameter.
     *
     * @param list<mixed> $args
     * @return list<mixed>
     */
    private function arguments(Coroutine $L, State $state, array $args): array
    {
        $phpArguments = [];
        $last = \count($this->parameters) - 1;
        foreach ($this->parameters as $index => $parameter) {
            if ($index === $last && $this->variadic) {
                for ($arg = $index + 1; $arg <= \count($args); $arg++) {
                    $phpArguments[] = $this->argument($L, $state, $parameter, $args, $arg);
                }
                break;
            }
            $phpArguments[] = $this->argument($L, $state, $parameter, $args, $index + 1);
        }
        return $phpArguments;
    }

    /**
     * Lua argument #$arg for $parameter.
     *
     * @param array{int, bool, bool, mixed} $parameter
     * @param list<mixed> $args
     */
    private function argument(Coroutine $L, State $state, array $parameter, array $args, int $arg): mixed
    {
        [$kind, $takesNil, $hasDefault, $default] = $parameter;
        $value = $args[$arg - 1] ?? null;
        if ($value === null && $takesNil) {  // nil or none (luaL_opt)
            return $hasDefault ? $default : null;
        }
        switch ($kind) {
            case self::INT:
                return Auxiliary::checkInteger($L, $args, $arg);
            case self::FLOAT:
                return (float) Auxiliary::checkNumber($L, $args, $arg);
            case self::STRING:
                return Auxiliary::checkString($L, $args, $arg);
            case self::BOOL:
                return $value !== null && $value !== false;
            case self::ARRAY:
                return self::converted($L, $state, Auxiliary::checkTable($L, $args, $arg), $arg);
            case self::TABLE:
                return Convert::toHandle(Auxiliary::checkTable($L, $args, $arg), $state);
            case self::FUNCTION:
                if (!($value instanceof LuaClosure || $value instanceof NativeFunction)) {
                    Auxiliary::typeError($L, $args, $arg, 'function');
                }
                return Convert::toHandle($value, $state);
            default:
                return self::converted($L, $state, $value, $arg);
        }
    }

    /** $value converted to PHP; a value that cannot be is a bad argument #$arg */
    private static function converted(Coroutine $L, State $state, mixed $value, int $arg): mixed
    {
        if (!($value instanceof RuntimeTable)) {
            return Convert::toPhp($value, '', $state);
        }
        try {
            return Convert::toPhp($value, '', $state);
        } catch (ConversionError $error) {
            $where = ltrim($error->path, '.');
            Auxiliary::argError($L, $arg, $error->reason . ($where === '' ? '' : " at $where"));
        }
    }
}
