<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\EventListener;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One span per consumed message: a worker command span would end at the first kernel.reset.
 *
 * The span is closed by its own message's handled/failed event. Batch handlers acknowledge
 * later, so a span still open when the worker moves on (WorkerRunningEvent, before
 * kernel.reset at -1024; or the next message) is closed then, marked as not acknowledged.
 * Assumes Messenger's default synchronous execution: with an asynchronous execution strategy
 * (Symfony 8.1+) handled events arrive after WorkerRunningEvent, so every span would be
 * closed as not acknowledged and measure the dispatch only.
 */
#[AsEventListener(event: WorkerMessageReceivedEvent::class, method: 'onReceived', priority: -1024)]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onHandled')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onFailed')]
#[AsEventListener(event: WorkerRunningEvent::class, method: 'onWorkerRunning')]
final class MessageEventListener implements ResetInterface
{
    public const array NOT_ACKNOWLEDGED = ['message_acknowledged' => false];

    private ?object $message = null;

    private ?SpanInterface $span = null;

    /**
     * @param list<class-string> $messagesWhitelist classes, parents or interfaces of profiled messages
     */
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
        #[Autowire(param: 'msstc4symfony_profiling.messages.whitelist')]
        private readonly array $messagesWhitelist = [],
    ) {
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->close(self::NOT_ACKNOWLEDGED);

        $message = $event->getEnvelope()->getMessage();
        if (!$event->shouldHandle() || !array_any($this->messagesWhitelist, static fn (string $class): bool => $message instanceof $class)) {
            return;
        }

        $this->message = $message;
        $this->span = $this->profilingFactory->createSpan('message ' . $message::class, ['transport' => $event->getReceiverName()]);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        if ($event->getEnvelope()->getMessage() === $this->message) {
            $this->close([]);
        }
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->getEnvelope()->getMessage() === $this->message) {
            $this->close(['message_failed' => true, 'message_will_retry' => $event->willRetry()]);
        }
    }

    public function onWorkerRunning(): void
    {
        $this->close(self::NOT_ACKNOWLEDGED);
    }

    #[Override]
    public function reset(): void
    {
        $this->message = null;
        $this->span = null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function close(array $context): void
    {
        $span = $this->span;
        $this->reset();
        if ($span instanceof SpanInterface) {
            $this->profilingFactory->endSpan($span, $context);
        }
    }
}
