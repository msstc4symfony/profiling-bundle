<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Span;

interface SpanInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function end(array $context = []): void;

    public function getMessage(): string;

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;

    public function getStartTime(): ?float;

    public function getParentSpan(): ?SpanInterface;

    public function getEndHandlers(): array;

    public function addEndHandler(callable $endHandler): static;

    public function removeEndHandler(callable|int $endHandler): static;
}
