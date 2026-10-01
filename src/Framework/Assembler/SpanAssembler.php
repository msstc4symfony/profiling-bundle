<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Assembler;

use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\AllowSpanDecisionMakerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
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

    #[Override]
    public static function getDefaultPriority(): int
    {
        return 0;
    }

    #[Override]
    public function assemble(string $message, array $context): SpanInterface
    {
        if (!$this->isAllowed($message)) {
            return new NullSpan($message, $context);
        }

        return new Span($message, $context);
    }

    private function isAllowed(string $message): bool
    {
        foreach ($this->allowSpanDecisionMakers as $allowSpanDecisionMaker) {
            $result = $allowSpanDecisionMaker->isAllowed($message);
            if ($result === null) {
                continue;
            }

            return $result;
        }

        return true;
    }
}
