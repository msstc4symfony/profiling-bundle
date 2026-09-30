<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework;

use Hot\ProfilingBundle\Framework\Span\SpanInterface;

interface ProfilingFactoryInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function createSpan(string $message, array $context = []): SpanInterface;

    public function endAll(): void;
}
