<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Span;

use Override;

abstract class AbstractSpan implements SpanInterface
{
    protected readonly float $startTime;

    private readonly int $startedAt;

    private ?int $endedAt = null;

    protected ?SpanInterface $parentSpan = null;

    /**
     * @var list<callable(SpanInterface, array<string, mixed>): void>
     */
    protected array $endHandlers = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        protected readonly string $message,
        protected readonly array $context = [],
    ) {
        $this->startTime = microtime(true);
        $this->startedAt = hrtime(true);
    }

    #[Override]
    public function getMessage(): string
    {
        return $this->message;
    }

    #[Override]
    public function getContext(): array
    {
        return $this->context;
    }

    #[Override]
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    #[Override]
    public function getParentSpan(): ?SpanInterface
    {
        return $this->parentSpan;
    }

    #[Override]
    public function setParentSpan(?SpanInterface $parentSpan): static
    {
        $this->parentSpan = $parentSpan;

        return $this;
    }

    #[Override]
    public function getEndHandlers(): array
    {
        return $this->endHandlers;
    }

    #[Override]
    public function addEndHandler(callable $endHandler): static
    {
        $this->endHandlers[] = $endHandler;

        return $this;
    }

    #[Override]
    public function removeEndHandler(callable|int $endHandler): static
    {
        $index = is_int($endHandler) ? $endHandler : array_search($endHandler, $this->endHandlers, true);

        if (is_int($index) && isset($this->endHandlers[$index])) {
            $handlers = $this->endHandlers;
            unset($handlers[$index]);
            $this->endHandlers = array_values($handlers);
        }

        return $this;
    }

    #[Override]
    public function isEnded(): bool
    {
        return $this->endedAt !== null;
    }

    #[Override]
    public function getDuration(): float
    {
        return (($this->endedAt ?? hrtime(true)) - $this->startedAt) / 1e9;
    }

    #[Override]
    public function end(array $context = []): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $this->endedAt = hrtime(true);

        foreach ($this->endHandlers as $endHandler) {
            $endHandler($this, $context);
        }
    }
}
