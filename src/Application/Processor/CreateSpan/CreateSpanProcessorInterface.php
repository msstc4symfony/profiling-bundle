<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan;

use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(CreateSpanProcessorInterface::class)]
interface CreateSpanProcessorInterface
{
    public function process(SpanInterface $span): SpanInterface;
}
