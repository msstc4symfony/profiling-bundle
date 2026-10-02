<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\EventListener;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Framework\SpanKeeperInterface;
use Override;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onCommand', priority: 4096)]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminate')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminateEnd', priority: -4096)]
final class ConsoleEventListener implements ResetInterface
{
    private ?SpanInterface $span = null;

    /**
     * @param list<string> $commandsWhitelist
     */
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
        #[Autowire(param: 'msstc4symfony_profiling.commands.whitelist')]
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
        if ($this->profilingFactory instanceof SpanKeeperInterface) {
            $this->profilingFactory->keepOpenOnReset($this->span);
        }
    }

    public function onTerminate(): void
    {
        if ($this->span instanceof SpanInterface) {
            $this->profilingFactory->endSpan($this->span);
        }
        $this->span = null;
    }

    /**
     * Ends spans the command left open.
     */
    public function onTerminateEnd(): void
    {
        $this->profilingFactory->endAll();
    }

    /**
     * Keeps the span: kernel.reset runs between the messages of messenger:consume, and the
     * command span must cover the whole run until console.terminate.
     */
    #[Override]
    public function reset(): void
    {
    }
}
