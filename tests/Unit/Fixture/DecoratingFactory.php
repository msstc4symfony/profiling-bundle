<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Framework\SpanKeeperInterface;
use Override;

/**
 * An application decorator of the factory service, as seen by the bundle's listeners.
 */
final readonly class DecoratingFactory implements ProfilingFactoryInterface, SpanKeeperInterface
{
    public function __construct(private ProfilingFactory $inner)
    {
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return $this->inner->createSpan($message, $context);
    }

    #[Override]
    public function endSpan(SpanInterface $span, array $context = []): void
    {
        $this->inner->endSpan($span, $context);
    }

    #[Override]
    public function endAll(): void
    {
        $this->inner->endAll();
    }

    #[Override]
    public function keepOpenOnReset(SpanInterface $span): void
    {
        $this->inner->keepOpenOnReset($span);
    }
}
