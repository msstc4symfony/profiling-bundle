<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application;

use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

#[Exclude]
final class NullProfilingFactory implements DetachedSpanFactoryInterface
{
    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return new NullSpan($message, $context);
    }

    #[Override]
    public function createDetachedSpan(string $message, array $context = []): SpanInterface
    {
        return new NullSpan($message, [...$context, ...self::DETACHED]);
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
