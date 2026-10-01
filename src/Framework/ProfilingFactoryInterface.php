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

    public function endAll(): void;
}
