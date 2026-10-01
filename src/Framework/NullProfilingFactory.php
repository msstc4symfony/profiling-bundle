<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class NullProfilingFactory implements ProfilingFactoryInterface
{
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return new NullSpan($message, $context);
    }

    public function endAll(): void
    {
    }
}
