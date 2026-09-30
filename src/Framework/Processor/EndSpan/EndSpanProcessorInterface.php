<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Processor\EndSpan;

use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(EndSpanProcessorInterface::class)]
interface EndSpanProcessorInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function process(SpanInterface $span, array $context): void;
}
