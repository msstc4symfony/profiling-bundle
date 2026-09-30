<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Processor\CreateSpan;

use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(CreateSpanProcessorInterface::class)]
interface CreateSpanProcessorInterface
{
    public function process(SpanInterface $span): SpanInterface;
}
