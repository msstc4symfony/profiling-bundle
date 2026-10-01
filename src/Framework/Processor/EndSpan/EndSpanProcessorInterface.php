<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan;

use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(EndSpanProcessorInterface::class)]
interface EndSpanProcessorInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function process(SpanInterface $span, array $context): void;
}
