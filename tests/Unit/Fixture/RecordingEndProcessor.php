<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;

final class RecordingEndProcessor implements EndSpanProcessorInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $ended = [];

    #[Override]
    public function process(SpanInterface $span, array $context): void
    {
        $this->ended[] = [$span->getMessage(), $context];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (array $ended): string => $ended[0], $this->ended);
    }
}
