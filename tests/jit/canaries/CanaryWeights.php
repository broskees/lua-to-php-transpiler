<?php

declare(strict_types=1);

namespace Tests\TracingJit\Canaries;

/**
 * The loop of canary (g), tests/jit/canaries/inlined_class_constant.php,
 * shaped like FunctionEmitter::inlineWeights: a method of the same class
 * that the trace inlines reads class constants of another file, each a
 * call to the VM handler of FETCH_CLASS_CONSTANT at the address of its
 * opline, with no check after it that would place it.
 */
final class CanaryWeights
{
    /** @param list<int> $opcodes */
    public function total(array $opcodes): int
    {
        $sum = 0;
        for ($index = 0; $index < 4; $index++) {
            $sum += $this->weight($opcodes[$index]);
        }
        return $sum;
    }

    private function weight(int $opcode): int
    {
        return match ($opcode) {
            CanaryOpcodes::CALL, CanaryOpcodes::VARARG => 2,
            CanaryOpcodes::CONCAT => 3,
            default => 1,
        };
    }
}
