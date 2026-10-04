<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
use RuntimeException;

/**
 * Gives every span an end handler that throws, as a buggy create processor would add.
 */
final readonly class ThrowingEndHandlerProcessor implements CreateSpanProcessorInterface
{
    #[Override]
    public function process(SpanInterface $span): SpanInterface
    {
        return $span->addEndHandler(static function (): never {
            throw new RuntimeException('handler bug');
        });
    }
}
