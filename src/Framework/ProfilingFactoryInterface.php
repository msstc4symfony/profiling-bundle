<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;

interface ProfilingFactoryInterface
{
    /**
     * End context of spans closed by the factory (a parent ending, endAll(), kernel.reset)
     * rather than by their own end().
     */
    public const array IMPLICIT_END = ['profiling_implicit_end' => true];

    /**
     * @param array<string, mixed> $context
     */
    public function createSpan(string $message, array $context = []): SpanInterface;

    /**
     * Ends a span on behalf of framework code: end handler failures are logged, not thrown.
     *
     * @param array<string, mixed> $context
     */
    public function endSpan(SpanInterface $span, array $context = []): void;

    public function endAll(): void;

    /**
     * Lets an open span, and therefore the spans below it, survive reset(): for spans covering
     * many units of work, such as a worker command, whose kernel.reset runs after every message.
     * Spans opened above it still end on reset(); endAll() ends it too.
     * Decorators of the factory must delegate this method to the inner factory.
     */
    public function keepOpenOnReset(SpanInterface $span): void;
}
