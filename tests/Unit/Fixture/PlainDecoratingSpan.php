<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;

/**
 * A wrapper that delegates SpanInterface only, as wrappers written before EndableAtInterface do.
 */
final readonly class PlainDecoratingSpan implements SpanInterface
{
    use DelegatesSpanInterface;

    public function __construct(
        private SpanInterface $inner,
    ) {
    }
}
