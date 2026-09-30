<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\EventListener;

use Hot\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onCommand', priority: 4096)]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminate')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminateEnd', priority: -4096)]
final class ConsoleEventListener
{
    private ?SpanInterface $span = null;

    /**
     * @param string[] $commandsWhitelist
     */
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
        private readonly array $commandsWhitelist = [],
    ) {
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand()?->getName() ?? 'unknown';

        if (!in_array($command, $this->commandsWhitelist, true)) {
            return;
        }

        $this->span = $this->profilingFactory->createSpan('cli command ' . $command);
    }

    public function onTerminate(): void
    {
        if (!$this->span instanceof SpanInterface) {
            return;
        }

        $this->span->end();
    }

    public function onTerminateEnd(): void
    {
        $this->profilingFactory->endAll();
    }
}
