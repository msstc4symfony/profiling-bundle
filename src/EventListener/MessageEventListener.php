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
use Symfony\Contracts\Service\ResetInterface;

/**
 * One span per consumed message: a worker command span would end at the first kernel.reset.
 *
 * The span is closed by its own message's handled/failed event. Batch handlers acknowledge
 * later and vetoed messages never get one, so a span still open when the next message
 * arrives is closed then, marked as not acknowledged.
 */
#[AsEventListener(event: WorkerMessageReceivedEvent::class, method: 'onReceived', priority: -1024)]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onHandled')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onFailed')]
final class MessageEventListener implements ResetInterface
{
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
        $this->close(['acknowledged' => false]);

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
            $this->close(['failed' => true, 'will_retry' => $event->willRetry()]);
        }
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
        $span?->end($context);
    }
}
