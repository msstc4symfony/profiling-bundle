<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;
use RuntimeException;

/**
 * A custom span whose setParentSpan() or addEndHandler() throws.
 */
final readonly class FailingSpan implements SpanInterface
{
    use DelegatesSpanInterface;

    private Span $inner;

    public function __construct(
        string $message,
        private bool $failsToSetParent,
        private bool $failsToAddHandler,
    ) {
        $this->inner = new Span($message);
    }

    #[Override]
    public function setParentSpan(?SpanInterface $parentSpan): static
    {
        if ($this->failsToSetParent) {
            throw new RuntimeException('custom span bug');
        }

        $this->inner->setParentSpan($parentSpan);

        return $this;
    }

    #[Override]
    public function addEndHandler(callable $endHandler): static
    {
        if ($this->failsToAddHandler) {
            throw new RuntimeException('custom span bug');
        }

        $this->inner->addEndHandler($endHandler);

        return $this;
    }
}
