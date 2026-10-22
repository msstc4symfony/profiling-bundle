<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;

/**
 * SpanInterface delegated to $this->inner, the span a wrapper fixture decorates.
 */
trait DelegatesSpanInterface
{
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
