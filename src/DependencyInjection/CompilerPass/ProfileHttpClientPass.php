<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\ProfilingHttpClient;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Every FrameworkBundle client, default and scoped, ends in the shared transport with an absolute URL: decorating
 * only it gives each real request exactly one span. Decorating the clients as well would profile requests twice and
 * see scoped clients before their base_uri is applied.
 *
 * @internal
 */
final class ProfileHttpClientPass implements CompilerPassInterface
{
    private const TRANSPORT_ID = 'http_client.transport';

    private const DECORATOR_ID = self::TRANSPORT_ID . '.decorator.profiling';

    // An unused slot of the family registry (bundle-standard doc/decoration-priorities.md: mock -10, tracing -15,
    // metrics -20); the decorator works in any order relative to the others.
    private const DECORATION_PRIORITY = -25;

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!AutoSpan::HTTP_CLIENT->isEnabledIn($container)) {
            return;
        }

        if (!$container->has(self::TRANSPORT_ID)) {
            throw new LogicException(sprintf('"%s" needs "framework.http_client" enabled.', AutoSpan::HTTP_CLIENT->option()));
        }

        $container->register(self::DECORATOR_ID, ProfilingHttpClient::class)
            ->setArguments([
                '$inner' => new Reference(self::DECORATOR_ID . '.inner'),
                '$profiling' => new Reference(DetachedSpanFactoryInterface::class),
            ])
            ->setDecoratedService(self::TRANSPORT_ID, null, self::DECORATION_PRIORITY)
        ;
    }
}
