<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\NullableSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class NullableProfilingFactory implements ProfilingFactoryInterface
{
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        return new NullableSpan($message, $context);
    }

    public function endAll(): void
    {
    }
}
