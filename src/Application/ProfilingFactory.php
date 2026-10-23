<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application;

use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\EndableAtInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;
use Psr\Log\LoggerInterface;
use SplObjectStorage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

/**
 * Keeps the stack of open spans. Ending a span first ends every span opened inside it,
 * innermost first and at the parent's end time. The stack is settled before any end
 * processor runs, and processors run from a queue, so a processor that opens or ends spans
 * cannot reorder or repeat the work. Failures of assemblers, processors and implicitly run
 * end handlers are logged, never thrown into the profiled code.
 */
final class ProfilingFactory implements DetachedSpanFactoryInterface, ResetInterface
{
    /**
     * Rounds of endAll()/reset() ending the stack and the detached spans; end processors that open a span for every
     * span they process would otherwise keep them going forever.
     */
    public const MAX_END_PASSES = 64;

    /** @var list<SpanInterface> */
    private array $activeSpans = [];

    /** @var SplObjectStorage<SpanInterface, null> in creation order */
    private readonly SplObjectStorage $detachedSpans;

    /** @var list<array{SpanInterface, array<string, mixed>}> */
    private array $endedSpans = [];

    private bool $processing = false;

    /** Depth of open() calls: assemblers, decision makers and create processors running. */
    private int $opening = 0;

    /** Depth of onEnd() calls still ending children: end processors wait until the stack is settled. */
    private int $settling = 0;

    /** @var WeakMap<SpanInterface, true> */
    private readonly WeakMap $keptOpenOnReset;

    /**
     * @param iterable<SpanAssemblerInterface> $spanAssemblers
     * @param iterable<CreateSpanProcessorInterface> $createSpanProcessors
     * @param iterable<EndSpanProcessorInterface> $endSpanProcessors
     */
    public function __construct(
        #[AutowireIterator(tag: SpanAssemblerInterface::class)]
        private readonly iterable $spanAssemblers,
        #[AutowireIterator(tag: CreateSpanProcessorInterface::class)]
        private readonly iterable $createSpanProcessors,
        #[AutowireIterator(tag: EndSpanProcessorInterface::class)]
        private readonly iterable $endSpanProcessors,
        private readonly LoggerInterface $logger,
    ) {
        $this->keptOpenOnReset = new WeakMap();
        $this->detachedSpans = new SplObjectStorage();
    }

    #[Override]
    public function createSpan(string $message, array $context = []): SpanInterface
    {
        $span = $this->open($message, $context);
        // Bound to the span that sits on the stack, which may wrap the assembled one.
        $bound = $this->bind($span, function (SpanInterface $ended, array $endContext) use ($span): void {
            $this->onEnd($span, $endContext);
        });
        // Without the factory's handler the span could never leave the stack.
        if ($bound) {
            $this->activeSpans[] = $span;
        }

        return $span;
    }

    #[Override]
    public function createDetachedSpan(string $message, array $context = []): SpanInterface
    {
        $span = $this->open($message, [...$context, ...self::DETACHED]);
        $bound = $this->bind($span, function (SpanInterface $ended, array $endContext) use ($span): void {
            $this->onDetachedEnd($span, $endContext);
        });
        if ($bound) {
            $this->detachedSpans->offsetSet($span);
        }

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
        $this->endOpenSpans(keepMarked: false);
    }

    #[Override]
    public function keepOpenOnReset(SpanInterface $span): void
    {
        $this->keptOpenOnReset[$span] = true;
    }

    #[Override]
    public function reset(): void
    {
        $this->endOpenSpans(keepMarked: true);
    }

    /**
     * Assembles and processes a span whose parent is the top of the stack.
     *
     * @param array<string, mixed> $context
     */
    private function open(string $message, array $context): SpanInterface
    {
        $this->dropEndedTop();
        $parent = $this->activeSpans === [] ? null : $this->activeSpans[array_key_last($this->activeSpans)];

        // A library span opened by profiling's own extension points (an end processor logging through DBAL, a
        // decision maker calling HTTP) would feed itself.
        if (($this->processing || $this->opening > 0) && array_intersect_key($context, self::AUTOMATIC) === self::AUTOMATIC) {
            // Not through the create processors either: one of them may be what opens it.
            $span = new NullSpan($message, $context);
            $this->adopt($span, $parent);

            return $span;
        }

        $this->opening++;

        try {
            try {
                $span = $this->assemble($message, $context);
            } catch (Throwable $exception) {
                $this->report('Profiling could not assemble span "{span}".', $message, $exception);
                $span = new NullSpan($message, $context);
            }

            $this->adopt($span, $parent);
            foreach ($this->createSpanProcessors as $processor) {
                try {
                    $span = $processor->process($span);
                } catch (Throwable $exception) {
                    $this->report('Profiling create processor ' . $processor::class . ' failed for span "{span}".', $message, $exception);
                }
            }
        } finally {
            $this->opening--;
        }

        $this->adopt($span, $parent);

        return $span;
    }

    /**
     * A custom span or wrapper may throw; the span then keeps whatever parent it has.
     */
    private function adopt(SpanInterface $span, ?SpanInterface $parent): void
    {
        try {
            $span->setParentSpan($parent);
        } catch (Throwable $exception) {
            $this->report('Profiling could not set the parent of span "{span}".', $span->getMessage(), $exception);
        }
    }

    /**
     * @param callable(SpanInterface, array<string, mixed>): void $handler
     *
     * @return bool false when the span refused the factory's end handler; it is then not tracked
     */
    private function bind(SpanInterface $span, callable $handler): bool
    {
        try {
            $span->addEndHandler($handler);
        } catch (Throwable $exception) {
            $this->report('Profiling could not watch the end of span "{span}".', $span->getMessage(), $exception);

            return false;
        }

        return true;
    }

    /**
     * Ending a span may open others (end handlers, end processors): each round ends the detached spans and the spans
     * on the stack when it starts; spans opened meanwhile wait for the next round, at most MAX_END_PASSES rounds.
     *
     * @param bool $keepMarked stop at the topmost span marked by keepOpenOnReset()
     */
    private function endOpenSpans(bool $keepMarked): void
    {
        for ($pass = 0; $pass < self::MAX_END_PASSES; $pass++) {
            foreach (clone $this->detachedSpans as $detached) {
                $this->endSpan($detached, self::IMPLICIT_END);
                // A span whose factory handler was removed never leaves the list by itself.
                $this->forgetDetached($detached);
            }

            $this->endStack($keepMarked);

            if ($this->detachedSpans->count() === 0 && $this->isStackSettled($keepMarked)) {
                return;
            }
        }

        $this->log('Profiling stopped ending spans after {passes} passes: end processors keep opening spans.', ['passes' => self::MAX_END_PASSES]);
    }

    private function endStack(bool $keepMarked): void
    {
        foreach (array_reverse($this->activeSpans) as $top) {
            if ($this->isKept($top, $keepMarked)) {
                return;
            }

            $this->endSpan($top, self::IMPLICIT_END);
            // A span whose factory handler was removed never leaves the stack by itself.
            $this->remove($top);
        }
    }

    private function isStackSettled(bool $keepMarked): bool
    {
        return $this->activeSpans === [] || $this->isKept($this->activeSpans[array_key_last($this->activeSpans)], $keepMarked);
    }

    private function isKept(SpanInterface $span, bool $keepMarked): bool
    {
        return $keepMarked && !$span->isEnded() && isset($this->keptOpenOnReset[$span]);
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
        $endedAt = $span instanceof EndableAtInterface ? $span->getEndedAt() : null;
        $this->settling++;

        try {
            foreach ($children as $child) {
                try {
                    // Off the stack already, so its own factory handler does nothing.
                    if ($endedAt !== null && $child instanceof EndableAtInterface) {
                        $child->endAt($endedAt, self::IMPLICIT_END);
                    } else {
                        $child->end(self::IMPLICIT_END);
                    }
                } catch (Throwable $exception) {
                    $this->report('An end handler failed for span "{span}".', $child->getMessage(), $exception);
                }
            }
        } finally {
            $this->settling--;
            $this->processEndedSpans();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function onDetachedEnd(SpanInterface $span, array $context): void
    {
        if (!$this->forgetDetached($span)) {
            return;
        }

        $this->endedSpans[] = [$span, $context];
        $this->processEndedSpans();
    }

    private function forgetDetached(SpanInterface $span): bool
    {
        if (!$this->detachedSpans->offsetExists($span)) {
            return false;
        }

        $this->detachedSpans->offsetUnset($span);

        return true;
    }

    private function processEndedSpans(): void
    {
        if ($this->processing || $this->settling > 0) {
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
        $this->log($message, ['span' => $span, 'exception' => $exception]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context): void
    {
        try {
            $this->logger->error($message, $context);
        } catch (Throwable) {
            // A broken logger must not break the profiled code either.
        }
    }
}
