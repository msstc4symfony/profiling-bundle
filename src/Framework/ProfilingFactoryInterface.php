<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;

interface ProfilingFactoryInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function createSpan(string $message, array $context = []): SpanInterface;

    /**
     * Ends a span on behalf of framework code: end handler failures are logged, not thrown.
     *
     * @param array<string, mixed> $context
     */
    public function endSpan(SpanInterface $span, array $context = []): void;

    public function endAll(): void;
}
