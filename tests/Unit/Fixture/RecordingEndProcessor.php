<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Closure;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;

final class RecordingEndProcessor implements EndSpanProcessorInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $ended = [];

    /** @var (Closure(SpanInterface): void)|null runs after recording, to open or end spans re-entrantly */
    public ?Closure $onProcess = null;

    #[Override]
    public function process(SpanInterface $span, array $context): void
    {
        $this->ended[] = [$span->getMessage(), $context];

        if ($this->onProcess instanceof Closure) {
            ($this->onProcess)($span);
        }
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (array $ended): string => $ended[0], $this->ended);
    }
}
