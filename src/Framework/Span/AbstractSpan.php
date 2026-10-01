<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Span;

use Override;

abstract class AbstractSpan implements SpanInterface
{
    protected readonly float $startTime;

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
    public function end(array $context = []): void
    {
        foreach ($this->endHandlers as $endHandler) {
            $endHandler($this, $context);
        }
    }
}
