<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AutoSpans;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * An application decorator of the factory: remembers the spans asked for, then delegates.
 */
final class RecordingFactoryDecorator implements DetachedSpanFactoryInterface, ResetInterface
{
    /** @var list<string> */
    public array $created = [];

    public function __construct(
        private readonly DetachedSpanFactoryInterface $inner,
    ) {
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        $this->created[] = $message;

        return $this->inner->createSpan($message, $context);
    }

    #[Override]
    public function createDetachedSpan(string $message, array $context = []): SpanInterface
    {
        $this->created[] = $message;

        return $this->inner->createDetachedSpan($message, $context);
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

    #[Override]
    public function reset(): void
    {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }
}
