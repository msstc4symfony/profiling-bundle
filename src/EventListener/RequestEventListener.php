<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\EventListener;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest')]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onTerminate')]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onTerminateEnd', priority: -4096)]
final class RequestEventListener implements ResetInterface
{
    private ?SpanInterface $span = null;

    /**
     * @param list<string> $routesWhitelist
     */
    public function __construct(
        private readonly ProfilingFactoryInterface $profilingFactory,
        #[Autowire(param: 'msstc4symfony_profiling.routes.whitelist')]
        private readonly array $routesWhitelist = [],
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethod('OPTIONS')) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route) || !in_array($route, $this->routesWhitelist, true)) {
            return;
        }

        $this->span = $this->profilingFactory->createSpan('request ' . $route);
    }

    public function onTerminate(): void
    {
        if ($this->span instanceof SpanInterface) {
            $this->profilingFactory->endSpan($this->span);
        }
        $this->span = null;
    }

    /**
     * Ends spans the application left open during the request.
     */
    public function onTerminateEnd(): void
    {
        $this->profilingFactory->endAll();
    }

    #[Override]
    public function reset(): void
    {
        $this->span = null;
    }
}
