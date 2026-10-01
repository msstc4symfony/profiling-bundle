<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Span;

use Override;
use Throwable;

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

    /**
     * Final: the factory may end a child through endAt(), which an override would miss.
     */
    #[Override]
    final public function end(array $context = []): void
    {
        $this->endAt(hrtime(true), $context);
    }

    /**
     * @internal the factory ends children at their parent's end time, so a child never
     *           outlasts its parent
     *
     * @param array<string, mixed> $context
     */
    public function endAt(int $endedAt, array $context = []): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $this->endedAt = max($endedAt, $this->startedAt);

        // Every handler runs, the factory's included, even when an application handler throws.
        $failure = null;
        foreach ($this->endHandlers as $endHandler) {
            try {
                $endHandler($this, $context);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * @internal
     */
    public function getEndedAt(): ?int
    {
        return $this->endedAt;
    }
}
