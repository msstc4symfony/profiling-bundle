<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\NullProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;

/**
 * Records every endSpan() call, also the repeated ones a real factory would ignore.
 */
final class RecordingDetachedFactory implements DetachedSpanFactoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $endContexts = [];

    private readonly NullProfilingFactory $inner;

    public function __construct()
    {
        $this->inner = new NullProfilingFactory();
    }

    #[Override]
    public function createDetachedSpan(string $message, array $context = []): SpanInterface
    {
        return $this->inner->createDetachedSpan($message, $context);
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return $this->inner->createSpan($message, $context);
    }

    #[Override]
    public function endSpan(SpanInterface $span, array $context = []): void
    {
        $this->endContexts[] = $context;
        $this->inner->endSpan($span, $context);
    }

    #[Override]
    public function endAll(): void
    {
    }

    #[Override]
    public function keepOpenOnReset(SpanInterface $span): void
    {
    }
}
