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
 */
#[AsEventListener(event: WorkerMessageReceivedEvent::class, method: 'onReceived')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onHandled')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onFailed')]
final class MessageEventListener implements ResetInterface
{
    private ?SpanInterface $span = null;

    /**
     * @param list<class-string> $messagesWhitelist
     */
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
        #[Autowire(param: 'msstc4symfony_profiling.messages.whitelist')]
        private readonly array $messagesWhitelist = [],
    ) {
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $class = $event->getEnvelope()->getMessage()::class;
        if (!in_array($class, $this->messagesWhitelist, true)) {
            return;
        }

        $this->span = $this->profilingFactory->createSpan('message ' . $class, ['transport' => $event->getReceiverName()]);
    }

    public function onHandled(): void
    {
        $this->span?->end();
        $this->span = null;
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->span?->end(['failed' => true, 'will_retry' => $event->willRetry()]);
        $this->span = null;
    }

    #[Override]
    public function reset(): void
    {
        $this->span = null;
    }
}
