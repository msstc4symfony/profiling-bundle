<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Keeps the stack of open spans. Ending a span first ends every span opened inside it,
 * innermost first. The stack is settled before any end processor runs, and processors run
 * from a queue, so a processor that opens or ends spans cannot reorder or repeat the work.
 */
final class ProfilingFactory implements ProfilingFactoryInterface, ResetInterface
{
    /** @var list<SpanInterface> */
    private array $activeSpans = [];

    /** @var list<array{SpanInterface, array<string, mixed>}> */
    private array $endedSpans = [];

    private bool $processing = false;

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
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        $this->dropEndedTop();
        $parent = $this->activeSpans === [] ? null : $this->activeSpans[array_key_last($this->activeSpans)];

        $span = $this->assemble($message, $context)->setParentSpan($parent);
        foreach ($this->createSpanProcessors as $processor) {
            $span = $processor->process($span);
        }

        // Bound to the span that sits on the stack, which may wrap the assembled one.
        $span->setParentSpan($parent)->addEndHandler(function (SpanInterface $ended, array $endContext) use ($span): void {
            $this->onEnd($span, $endContext);
        });
        $this->activeSpans[] = $span;

        return $span;
    }

    #[Override]
    public function endAll(): void
    {
        while ($this->activeSpans !== []) {
            $top = $this->activeSpans[array_key_last($this->activeSpans)];
            $top->end();
            // A span whose factory handler was removed never leaves the stack by itself.
            $this->remove($top);
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->endAll();
    }

    /**
     * Spans ended after their factory handler was removed are not processed; they only must
     * not become parents.
     */
    private function dropEndedTop(): void
    {
        while ($this->activeSpans !== [] && $this->activeSpans[array_key_last($this->activeSpans)]->isEnded()) {
            array_pop($this->activeSpans);
        }
    }

    private function remove(SpanInterface $span): void
    {
        $position = array_search($span, $this->activeSpans, true);
        if (is_int($position)) {
            array_splice($this->activeSpans, $position, 1);
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

        return new NullSpan($message, $context);
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

        $children = array_reverse(array_slice($this->activeSpans, $position + 1));
        $this->activeSpans = array_slice($this->activeSpans, 0, $position);

        foreach ($children as $child) {
            // Off the stack already, so its own factory handler does nothing.
            $child->end();
            $this->endedSpans[] = [$child, []];
        }

        $this->endedSpans[] = [$span, $context];
        $this->processEndedSpans();
    }

    private function processEndedSpans(): void
    {
        if ($this->processing) {
            return;
        }

        $this->processing = true;

        try {
            while ($this->endedSpans !== []) {
                [$span, $context] = array_shift($this->endedSpans);
                if ($span->isRecorded()) {
                    $this->runEndProcessors($span, $context);
                }
            }
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Profiling must never break the profiled code: a failing processor is logged and skipped.
     *
     * @param array<string, mixed> $context
     */
    private function runEndProcessors(SpanInterface $span, array $context): void
    {
        foreach ($this->endSpanProcessors as $processor) {
            try {
                $processor->process($span, $context);
            } catch (Throwable $exception) {
                $this->logger->error('Profiling end processor {processor} failed for span "{span}".', [
                    'processor' => $processor::class,
                    'span' => $span->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }
    }
}
