<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\QuerySpansMiddleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers one QuerySpansMiddleware per DoctrineBundle connection, tagged for that connection only, so
 * DoctrineBundle's MiddlewaresPass gives each connection a middleware that knows its name. DoctrineBundle declares
 * connections as class-less ChildDefinitions, so the connection list comes from the "doctrine.connections" parameter.
 *
 * @internal
 */
final class ProfileDoctrineDbalPass implements CompilerPassInterface
{
    // Before DoctrineBundle's MiddlewaresPass (priority 0) whatever the bundle order.
    public const PRIORITY = 1;

    // DoctrineBundle applies middlewares by descending priority, the first one innermost: below the default 0 of
    // other middlewares (metrics-bundle's included), so the span is the outermost and covers them.
    public const MIDDLEWARE_PRIORITY = -100;

    private const MIDDLEWARE_TAG = 'doctrine.middleware';

    private const MIDDLEWARE_ID_PREFIX = 'msstc4symfony_profiling.doctrine.middleware.';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!AutoSpan::DBAL->isEnabledIn($container)) {
            return;
        }

        $connections = $container->hasParameter('doctrine.connections') ? $container->getParameter('doctrine.connections') : null;
        if (!is_array($connections)) {
            throw new LogicException(sprintf('"%s" needs DoctrineBundle registered with doctrine.dbal configured.', AutoSpan::DBAL->option()));
        }

        foreach (array_keys($connections) as $name) {
            $name = (string) $name;
            $id = self::MIDDLEWARE_ID_PREFIX . $name;
            if ($container->hasDefinition($id)) {
                continue;
            }

            $container->register($id, QuerySpansMiddleware::class)
                ->setArguments(['$profiling' => new Reference(DetachedSpanFactoryInterface::class), '$connectionName' => $name])
                ->addTag(self::MIDDLEWARE_TAG, ['connection' => $name, 'priority' => self::MIDDLEWARE_PRIORITY])
            ;
        }
    }
}
