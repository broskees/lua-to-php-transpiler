<?php

declare(strict_types=1);

namespace LuaPhp\Emitter;

use LuaPhp\Compiler\Proto;

/**
 * Proto -> PHP source.
 *
 * A chunk becomes a factory expression:
 *
 *     static function (Proto $proto): \Closure { ... return $function_main; }
 *
 * that, given the chunk's main Proto, creates one PHP closure per Proto
 * (children first, each captured with `use` by its parent for
 * OP_CLOSURE) and returns the main one. Every Lua closure of a Proto
 * shares its PHP closure. The code refers to runtime classes by the short
 * names imported in PREAMBLE, which must precede it.
 *
 * See FunctionEmitter for the shape of one function and AGENTS.md
 * ("Runtime conventions") for the conventions the code follows.
 */
final class Emitter
{
    public const GENERATED_NAMESPACE = 'LuaPhp\\Generated';

    public const PREAMBLE = "namespace LuaPhp\\Generated;\n\n"
        . "use LuaPhp\\Compiler\\Proto;\n"
        . "use LuaPhp\\Runtime\\CallInfo;\n"
        . "use LuaPhp\\Runtime\\Calls;\n"
        . "use LuaPhp\\Runtime\\Coroutine;\n"
        . "use LuaPhp\\Runtime\\Gc\\Collector;\n"
        . "use LuaPhp\\Runtime\\Hooks;\n"
        . "use LuaPhp\\Runtime\\LuaClosure;\n"
        . "use LuaPhp\\Runtime\\LuaClosure1;\n"
        . "use LuaPhp\\Runtime\\LuaClosure2;\n"
        . "use LuaPhp\\Runtime\\LuaClosure3;\n"
        . "use LuaPhp\\Runtime\\LuaClosureN;\n"
        . "use LuaPhp\\Runtime\\LuaTable;\n"
        . "use LuaPhp\\Runtime\\MetaMethods;\n"
        . "use LuaPhp\\Runtime\\Upvalues;\n"
        . "use LuaPhp\\Runtime\\Vm;\n";

    /** PHP source of the factory expression for the chunk whose main function is $main */
    public static function emitChunk(Proto $main): string
    {
        $protoDeclarations = '';
        $functionDefinitions = '';
        self::emitProtoTree($main, '0', $protoDeclarations, $functionDefinitions);
        return "static function (Proto \$proto_0): \\Closure {\n"
            . $protoDeclarations
            . $functionDefinitions
            . "    return \$function_0;\n"
            . "}";
    }

    /**
     * Declares $proto_<path> for the children of $proto and appends the
     * function definitions, children before parents.
     */
    private static function emitProtoTree(Proto $proto, string $path, string &$protoDeclarations, string &$functionDefinitions): void
    {
        foreach ($proto->p as $childIndex => $child) {
            $childPath = $path . '_' . $childIndex;
            $protoDeclarations .= '    $proto_' . $childPath . ' = $proto_' . $path . '->p[' . $childIndex . "];\n";
        }
        foreach ($proto->p as $childIndex => $child) {
            self::emitProtoTree($child, $path . '_' . $childIndex, $protoDeclarations, $functionDefinitions);
        }
        $functionDefinitions .= (new FunctionEmitter($proto, $path))->emit() . "\n";
    }
}
