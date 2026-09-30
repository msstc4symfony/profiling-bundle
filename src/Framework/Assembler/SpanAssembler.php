<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Assembler;

use Hot\ProfilingBundle\Framework\DecisionMaker\AllowSpan\AllowSpanDecisionMakerInterface;
use Hot\ProfilingBundle\Framework\Span\NullableSpan;
use Hot\ProfilingBundle\Framework\Span\Span;
use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SpanAssembler implements SpanAssemblerInterface
{
    /**
     * @param iterable<AllowSpanDecisionMakerInterface> $allowSpanDecisionMakers
     */
    public function __construct(
        #[AutowireIterator(tag: AllowSpanDecisionMakerInterface::class, defaultPriorityMethod: 'getDefaultPriority')]
        private iterable $allowSpanDecisionMakers = [],
    ) {
    }

    public static function getDefaultPriority(): int
    {
        return 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function assemble(string $message, array $context): SpanInterface
    {
        if (!$this->isAllowed($message)) {
            return new NullableSpan($message, $context);
        }

        return new Span($message, $context);
    }

    private function isAllowed(string $message): bool
    {
        foreach ($this->allowSpanDecisionMakers as $allowSpanDecisionMaker) {
            $result = $allowSpanDecisionMaker->isAllow($message);
            if ($result === null) {
                continue;
            }

            return $result;
        }

        return true;
    }
}
