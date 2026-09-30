<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Processor\EndSpan;

use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class LoggerProcessor implements EndSpanProcessorInterface
{
    public function __construct(
        private LoggerInterface $profilingLogger = new NullLogger(),
    ) {
    }

    public function process(SpanInterface $span, array $context): void
    {
        $parentSpan = $span->getParentSpan();
        $parentMessages = [$span->getMessage()];
        while ($parentSpan instanceof SpanInterface) {
            $parentMessages[] = $parentSpan->getMessage();
            $parentSpan = $parentSpan->getParentSpan();
        }

        $this->profilingLogger->info(
            implode(' > ', array_reverse($parentMessages)),
            [
                'duration' => round(microtime(true) - $span->getStartTime(), 6),
            ] + array_merge($span->getContext(), $context),
        );
    }
}
