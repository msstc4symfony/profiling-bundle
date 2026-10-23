<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ProfilingGuzzleMiddleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the Guzzle middleware before the optimization passes, so its factory reference follows decorations and
 * aliases like every other service; ProfileGuzzlePass adds it to the clients later. Not in the extension: the bundle
 * class must not depend on Infrastructure.
 *
 * @internal
 */
final class RegisterGuzzleMiddlewarePass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!AutoSpan::GUZZLE->isEnabledIn($container)) {
            return;
        }

        $container->register(ProfilingGuzzleMiddleware::class, ProfilingGuzzleMiddleware::class)
            ->setArguments(['$profiling' => new Reference(DetachedSpanFactoryInterface::class)])
        ;
    }
}
