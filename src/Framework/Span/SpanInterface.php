<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Span;

interface SpanInterface
{
    /**
     * Fixes the duration and runs the end handlers; later calls do nothing.
     *
     * @param array<string, mixed> $context merged into the span context for end processors
     */
    public function end(array $context = []): void;

    public function isEnded(): bool;

    /**
     * Seconds from creation to end() (or to now while the span is open), monotonic clock.
     */
    public function getDuration(): float;

    public function getMessage(): string;

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;

    /**
     * Wall-clock creation time as a Unix timestamp; use getDuration() for elapsed time.
     */
    public function getStartTime(): float;

    public function getParentSpan(): ?SpanInterface;

    public function setParentSpan(?SpanInterface $parentSpan): static;

    /**
     * @return list<callable(SpanInterface, array<string, mixed>): void>
     */
    public function getEndHandlers(): array;

    /**
     * @param callable(SpanInterface, array<string, mixed>): void $endHandler
     */
    public function addEndHandler(callable $endHandler): static;

    /**
     * @param (callable(SpanInterface, array<string, mixed>): void)|int $endHandler handler or its index
     */
    public function removeEndHandler(callable|int $endHandler): static;

    /**
     * Whether end processors (logging, metrics) should record this span.
     */
    public function isRecorded(): bool;
}
