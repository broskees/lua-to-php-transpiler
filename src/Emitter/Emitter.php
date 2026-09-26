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
        . "use LuaPhp\\Runtime\\Op;\n"
        . "use LuaPhp\\Runtime\\TableConstructor;\n"
        . "use LuaPhp\\Runtime\\Upvalues;\n"
        . "use LuaPhp\\Runtime\\Vm;\n";

    /**
     * The most weight (FunctionEmitter::inlineWeights: about one per
     * instruction) of inline code in one chunk. PHP needs 3 to 8 KB of
     * memory to compile each instruction's inline code (more with opcache,
     * whose optimizer works on the whole function), so 4096 take up to about
     * 32 MB. In a heavier chunk, the code outside loops of its heaviest
     * functions is compact (FunctionEmitter::compactCodeOutsideLoops: one
     * call per instruction, about 1 to 2 KB to compile), the heaviest first,
     * until the rest weighs at most this: the code that runs once per call,
     * like a module's main chunk, rather than loops. Measured: 3,000 lines
     * of if-statements in one function (32,500 instructions) needed 146 MB
     * to compile with opcache, beyond PHP's default memory_limit.
     */
    public const INLINE_WEIGHT_MAXIMUM = 4096;

    /** PHP source of the factory expression for the chunk whose main function is $main */
    public static function emitChunk(Proto $main): string
    {
        $protoDeclarations = '';
        $emitters = [];
        self::createEmitters($main, '0', $protoDeclarations, $emitters);
        $inlineWeight = 0;
        $weightsOutsideLoops = [];
        foreach ($emitters as $index => $emitter) {
            [$weight, $weightOutsideLoops] = $emitter->inlineWeights();
            $inlineWeight += $weight;
            $weightsOutsideLoops[$index] = $weightOutsideLoops;
        }
        arsort($weightsOutsideLoops);  // (stable: equal weights in the order of the functions)
        foreach ($weightsOutsideLoops as $index => $weightOutsideLoops) {
            if ($inlineWeight <= self::INLINE_WEIGHT_MAXIMUM) {
                break;
            }
            $emitters[$index]->compactCodeOutsideLoops();
            $inlineWeight -= $weightOutsideLoops;
        }
        $functionDefinitions = '';
        foreach ($emitters as $emitter) {
            $functionDefinitions .= $emitter->emit() . "\n";
        }
        return "static function (Proto \$proto_0): \\Closure {\n"
            . $protoDeclarations
            . $functionDefinitions
            . "    return \$function_0;\n"
            . "}";
    }

    /**
     * Declares $proto_<path> for the children of $proto and appends the
     * emitters of their functions and of $proto's, children before parents
     * (the order of the function definitions).
     *
     * @param list<FunctionEmitter> $emitters
     */
    private static function createEmitters(Proto $proto, string $path, string &$protoDeclarations, array &$emitters): void
    {
        foreach ($proto->p as $childIndex => $child) {
            $childPath = $path . '_' . $childIndex;
            $protoDeclarations .= '    $proto_' . $childPath . ' = $proto_' . $path . '->p[' . $childIndex . "];\n";
        }
        foreach ($proto->p as $childIndex => $child) {
            self::createEmitters($child, $path . '_' . $childIndex, $protoDeclarations, $emitters);
        }
        $emitters[] = new FunctionEmitter($proto, $path);
    }
}
