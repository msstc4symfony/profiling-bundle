<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework;

use Hot\ProfilingBundle\Framework\Assembler\SpanAssemblerInterface;
use Hot\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Hot\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Hot\ProfilingBundle\Framework\Span\NullableSpan;
use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ProfilingFactory implements ProfilingFactoryInterface
{
    /** @var SpanInterface[] */
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

    public function createSpan(string $message, array $context = []): SpanInterface
    {
        $last = end($this->activeSpans);
        $last = $last instanceof SpanInterface ? $last : null;

        foreach ($this->spanAssemblers as $spanAssembler) {
            $span = $spanAssembler->assemble($message, $context);

            if ($span instanceof SpanInterface) {
                break;
            }
        }

        if (!$span instanceof SpanInterface) {
            $span = new NullableSpan($message, $context);
        }

        $span
            ->setParentSpan($last)
            ->addEndHandler($this->getEndCallback())
        ;

        foreach ($this->createSpanProcessors as $processor) {
            $span = $processor->process($span);
        }

        $this->activeSpans[] = $span;
        $this->activeSpans = array_values($this->activeSpans);

        return $span;
    }

    public function endAll(): void
    {
        foreach (array_reverse($this->activeSpans) as $i => $span) {
            unset($this->activeSpans[$i]);

            $span->end();
        }
    }

    private function getEndCallback(): callable
    {
        return function (SpanInterface $span, array $context): void {
            $hash = spl_object_hash($span);
            $ended = [];
            $found = null;
            foreach (array_reverse($this->activeSpans, true) as $i => $activeSpan) {
                if (spl_object_hash($activeSpan) === $hash) {
                    $found = $activeSpan;
                    unset($this->activeSpans[$i]);
                    break;
                }
                $ended[$i] = $activeSpan;
            }

            if ($found === null) {
                return;
            }

            foreach ($this->endSpanProcessors as $processor) {
                $processor->process($found, $context);
            }

            foreach ($ended as $i => $endedSpan) {
                unset($this->activeSpans[$i]);
            }

            $this->activeSpans = array_values($this->activeSpans);

            foreach ($ended as $endedSpan) {
                $endedSpan->end();
            }
        };
    }
}
