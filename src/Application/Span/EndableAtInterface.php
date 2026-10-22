<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application\Span;

/**
 * A span the factory can end at its parent's end time, so a child never outlasts its parent. A span wrapper returned
 * by a CreateSpanProcessorInterface implements it by delegating to the span it wraps.
 */
interface EndableAtInterface
{
    /**
     * Ends the span at an hrtime(true) instant in nanoseconds, never before its start, and runs its end handlers as
     * end() does — the factory's included; later calls do nothing.
     *
     * @param array<string, mixed> $context merged into the span context for end processors
     */
    public function endAt(int $endedAt, array $context = []): void;

    /**
     * The hrtime instant the span ended at, or null while it is open.
     */
    public function getEndedAt(): ?int;
}
