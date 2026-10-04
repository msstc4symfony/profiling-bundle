<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Framework\NullProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;

/**
 * Records what the listeners ask of the factory.
 */
final class RecordingFactory implements ProfilingFactoryInterface
{
    /** @var list<SpanInterface> */
    public array $endedSpans = [];

    /** @var list<SpanInterface> */
    public array $keptSpans = [];

    public int $endAllCalls = 0;

    private readonly NullProfilingFactory $inner;

    public function __construct()
    {
        $this->inner = new NullProfilingFactory();
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return $this->inner->createSpan($message, $context);
    }

    #[Override]
    public function endSpan(SpanInterface $span, array $context = []): void
    {
        $this->endedSpans[] = $span;
        $this->inner->endSpan($span, $context);
    }

    #[Override]
    public function endAll(): void
    {
        $this->endAllCalls++;
    }

    #[Override]
    public function keepOpenOnReset(SpanInterface $span): void
    {
        $this->keptSpans[] = $span;
    }
}
