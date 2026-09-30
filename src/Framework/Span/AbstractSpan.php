<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Span;

abstract class AbstractSpan implements SpanInterface
{
    protected float $startTime;

    protected ?SpanInterface $parentSpan = null;

    /**
     * @var callable[]
     */
    protected array $endHandlers = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        protected string $message,
        protected array $context,
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getStartTime(): ?float
    {
        return $this->startTime;
    }

    public function getParentSpan(): ?SpanInterface
    {
        return $this->parentSpan;
    }

    public function setParentSpan(?SpanInterface $parentSpan): static
    {
        $this->parentSpan = $parentSpan;

        return $this;
    }

    /**
     * @return callable[]
     */
    public function getEndHandlers(): array
    {
        return $this->endHandlers;
    }

    public function addEndHandler(callable $endHandler): static
    {
        $this->endHandlers[] = $endHandler;

        return $this;
    }

    public function removeEndHandler(callable|int $endHandler): static
    {
        if (is_callable($endHandler)) {
            $endHandler = array_search($endHandler, $this->endHandlers, true);
        }

        if (is_int($endHandler) && isset($this->endHandlers[$endHandler])) {
            unset($this->endHandlers[$endHandler]);
        }

        return $this;
    }

    public function end(array $context = []): void
    {
        foreach ($this->endHandlers as $endHandler) {
            $endHandler($this, $context);
        }
    }
}
