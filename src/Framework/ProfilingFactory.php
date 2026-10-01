<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
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
 * innermost first and at the parent's end time. The stack is settled before any end
 * processor runs, and processors run from a queue, so a processor that opens or ends spans
 * cannot reorder or repeat the work. Failures of assemblers, processors and implicitly run
 * end handlers are logged, never thrown into the profiled code.
 */
final class ProfilingFactory implements ProfilingFactoryInterface, ResetInterface
{
    /** End context of children closed by their parent rather than by their own end(). */
    public const array IMPLICIT_END = ['implicit' => true];

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
        #[AutowireIterator(tag: SpanAssemblerInterface::class)]
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

        try {
            $span = $this->assemble($message, $context);
        } catch (Throwable $exception) {
            $this->report('Profiling could not assemble span "{span}".', $message, $exception);
            $span = new NullSpan($message, $context);
        }

        $span->setParentSpan($parent);
        foreach ($this->createSpanProcessors as $processor) {
            try {
                $span = $processor->process($span);
            } catch (Throwable $exception) {
                $this->report('Profiling create processor ' . $processor::class . ' failed for span "{span}".', $message, $exception);
            }
        }

        // Bound to the span that sits on the stack, which may wrap the assembled one.
        $span->setParentSpan($parent)->addEndHandler(function (SpanInterface $ended, array $endContext) use ($span): void {
            $this->onEnd($span, $endContext);
        });
        $this->activeSpans[] = $span;

        return $span;
    }

    #[Override]
    public function endSpan(SpanInterface $span, array $context = []): void
    {
        try {
            $span->end($context);
        } catch (Throwable $exception) {
            $this->report('An end handler failed for span "{span}".', $span->getMessage(), $exception);
        }
    }

    #[Override]
    public function endAll(): void
    {
        while ($this->activeSpans !== []) {
            $top = $this->activeSpans[array_key_last($this->activeSpans)];
            $this->endSpan($top);
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

        // Children ended earlier lost their factory handler; they are dropped, not processed.
        $children = array_values(array_filter(
            array_reverse(array_slice($this->activeSpans, $position + 1)),
            static fn (SpanInterface $child): bool => !$child->isEnded(),
        ));
        $this->activeSpans = array_slice($this->activeSpans, 0, $position);

        foreach ($children as $child) {
            $this->endedSpans[] = [$child, self::IMPLICIT_END];
        }

        $this->endedSpans[] = [$span, $context];
        $endedAt = $span instanceof AbstractSpan ? $span->getEndedAt() : null;

        try {
            foreach ($children as $child) {
                try {
                    // Off the stack already, so its own factory handler does nothing.
                    $endedAt !== null && $child instanceof AbstractSpan ? $child->endAt($endedAt, self::IMPLICIT_END) : $child->end(self::IMPLICIT_END);
                } catch (Throwable $exception) {
                    $this->report('An end handler failed for span "{span}".', $child->getMessage(), $exception);
                }
            }
        } finally {
            $this->processEndedSpans();
        }
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
                $this->report('Profiling end processor ' . $processor::class . ' failed for span "{span}".', $span->getMessage(), $exception);
            }
        }
    }

    private function report(string $message, string $span, Throwable $exception): void
    {
        try {
            $this->logger->error($message, ['span' => $span, 'exception' => $exception]);
        } catch (Throwable) {
            // A broken logger must not break the profiled code either.
        }
    }
}
