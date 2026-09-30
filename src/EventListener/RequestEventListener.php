<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\EventListener;

use Hot\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(event: 'kernel.request', method: 'onRequest')]
#[AsEventListener(event: 'kernel.terminate', method: 'onTerminate')]
#[AsEventListener(event: 'kernel.terminate', method: 'onTerminate', priority: -4096)]
final class RequestEventListener
{
    private ?SpanInterface $span = null;

    /**
     * @param string[] $routesWhitelist
     */
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
        private readonly array $routesWhitelist = [],
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->isMethod('OPTIONS')) {
            return;
        }

        /** @var ?string $requestRoute */
        $requestRoute = $event->getRequest()->attributes->get('_route');

        if ($requestRoute === null || !in_array($requestRoute, $this->routesWhitelist, true)) {
            return;
        }

        $this->span = $this->profilingFactory->createSpan('request ' . $requestRoute);
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
