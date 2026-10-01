<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullableSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the stack of open spans. Ending a span first ends every span opened inside it,
 * innermost first, so each of them reaches the end processors.
 */
final class ProfilingFactory implements ProfilingFactoryInterface, ResetInterface
{
    /** @var list<SpanInterface> */
    private array $activeSpans = [];

    /**
     * @param iterable<SpanAssemblerInterface> $spanAssemblers
     * @param iterable<CreateSpanProcessorInterface> $createSpanProcessors
     * @param iterable<EndSpanProcessorInterface> $endSpanProcessors
     */
    public function __construct(
        #[AutowireIterator(tag: SpanAssemblerInterface::class, defaultPriorityMethod: 'getDefaultPriority')]
        private readonly iterable $spanAssemblers = [],
        #[AutowireIterator(tag: CreateSpanProcessorInterface::class)]
        private readonly iterable $createSpanProcessors = [],
        #[AutowireIterator(tag: EndSpanProcessorInterface::class)]
        private readonly iterable $endSpanProcessors = [],
    ) {
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        $span = $this->assemble($message, $context);
        $span->setParentSpan($this->activeSpans === [] ? null : $this->activeSpans[count($this->activeSpans) - 1]);
        $span->addEndHandler($this->onEnd(...));

        foreach ($this->createSpanProcessors as $processor) {
            $span = $processor->process($span);
        }

        $this->activeSpans[] = $span;

        return $span;
    }

    #[Override]
    public function endAll(): void
    {
        while ($this->activeSpans !== []) {
            $this->endTop();
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->endAll();
    }

    private function endTop(): void
    {
        $count = count($this->activeSpans);
        $this->activeSpans[$count - 1]->end();

        // A span whose end handler was removed would never leave the stack by itself.
        if (count($this->activeSpans) === $count) {
            array_pop($this->activeSpans);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assemble(string $message, array $context): SpanInterface
    {
        foreach ($this->spanAssemblers as $spanAssembler) {
            $span = $spanAssembler->assemble($message, $context);
            if ($span instanceof SpanInterface) {
                return $span;
            }
        }

        return new NullableSpan($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function onEnd(SpanInterface $span, array $context): void
    {
        $position = array_search($span, $this->activeSpans, true);
        if (!is_int($position)) {
            return;
        }

        // Children still open are ended first, innermost first, through their own handlers.
        while (count($this->activeSpans) - 1 > $position) {
            $this->endTop();
        }

        array_splice($this->activeSpans, $position, 1);

        if (!$span->isRecorded()) {
            return;
        }

        foreach ($this->endSpanProcessors as $processor) {
            $processor->process($span, $context);
        }
    }
}
