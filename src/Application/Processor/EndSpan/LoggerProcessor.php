<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application\Processor\EndSpan;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[WithMonologChannel('profiling')]
final readonly class LoggerProcessor implements EndSpanProcessorInterface
{
    public function __construct(
        private LoggerInterface $profilingLogger,
    ) {
    }

    #[Override]
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
                'duration' => round($span->getDuration(), 6),
            ] + array_merge($span->getContext(), $context),
        );
    }
}
