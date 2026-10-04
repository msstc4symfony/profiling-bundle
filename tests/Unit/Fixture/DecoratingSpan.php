<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;

/**
 * What a CreateSpanProcessor may return: a wrapper delegating everything to the assembled span.
 */
final readonly class DecoratingSpan implements SpanInterface
{
    public function __construct(
        private SpanInterface $inner,
    ) {
    }

    #[Override]
    public function end(array $context = []): void
    {
        $this->inner->end($context);
    }

    #[Override]
    public function isEnded(): bool
    {
        return $this->inner->isEnded();
    }

    #[Override]
    public function getDuration(): float
    {
        return $this->inner->getDuration();
    }

    #[Override]
    public function getMessage(): string
    {
        return 'decorated ' . $this->inner->getMessage();
    }

    #[Override]
    public function getContext(): array
    {
        return $this->inner->getContext();
    }

    #[Override]
    public function getStartTime(): float
    {
        return $this->inner->getStartTime();
    }

    #[Override]
    public function getParentSpan(): ?SpanInterface
    {
        return $this->inner->getParentSpan();
    }

    #[Override]
    public function setParentSpan(?SpanInterface $parentSpan): static
    {
        $this->inner->setParentSpan($parentSpan);

        return $this;
    }

    #[Override]
    public function getEndHandlers(): array
    {
        return $this->inner->getEndHandlers();
    }

    #[Override]
    public function addEndHandler(callable $endHandler): static
    {
        $this->inner->addEndHandler($endHandler);

        return $this;
    }

    #[Override]
    public function removeEndHandler(callable|int $endHandler): static
    {
        $this->inner->removeEndHandler($endHandler);

        return $this;
    }

    #[Override]
    public function isRecorded(): bool
    {
        return $this->inner->isRecorded();
    }
}
