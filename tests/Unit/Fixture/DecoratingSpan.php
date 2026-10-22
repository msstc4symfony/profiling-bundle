<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Application\Span\EndableAtInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;

/**
 * What a CreateSpanProcessor may return: a wrapper delegating everything to the assembled span.
 */
final readonly class DecoratingSpan implements SpanInterface, EndableAtInterface
{
    use DelegatesSpanInterface;

    public function __construct(
        private SpanInterface $inner,
    ) {
    }

    #[Override]
    public function endAt(int $endedAt, array $context = []): void
    {
        if ($this->inner instanceof EndableAtInterface) {
            $this->inner->endAt($endedAt, $context);
        } else {
            $this->inner->end($context);
        }
    }

    #[Override]
    public function getEndedAt(): ?int
    {
        return $this->inner instanceof EndableAtInterface ? $this->inner->getEndedAt() : null;
    }
}
