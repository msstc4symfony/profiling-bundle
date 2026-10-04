<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

#[Exclude]
final class NullProfilingFactory implements ProfilingFactoryInterface
{
    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return new NullSpan($message, $context);
    }

    #[Override]
    public function endSpan(SpanInterface $span, array $context = []): void
    {
        try {
            $span->end($context);
        } catch (Throwable) {
            // No logger here; the contract is only that nothing is thrown.
        }
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
