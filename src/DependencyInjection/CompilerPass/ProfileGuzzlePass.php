<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ProfilingGuzzleMiddleware;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds the middleware RegisterGuzzleMiddlewarePass registered to every Guzzle client service. The same three paths as
 * tracing-bundle's GuzzlePass: a client constructed by `GuzzleHttp\Client::__construct()` gets the middleware in its
 * `handler` config (Guzzle 7 and 8); other clients are configured after creation through `getConfig()`; clients
 * without it are only reported in the container compilation log. Runs as TYPE_BEFORE_REMOVING, after the named
 * arguments were resolved.
 *
 * @internal
 */
final class ProfileGuzzlePass implements CompilerPassInterface
{
    private const UNREACHABLE_CLIENT_LOG = 'Guzzle client "%s" is not profiled: its handler cannot be set from an array config of %s::__construct() and it has no getConfig(); '
        . 'create it with the handler returned by %s::decorateHandler().';

    private const BARE_HANDLER_LOG = 'Guzzle client "%s" is not profiled: its "handler" is not a %s.';

    // Guzzle 8 builds its default handler from these options and rejects them next to a given handler.
    private const DEFAULT_HANDLER_OPTIONS = ['max_host_connections', 'max_total_connections', 'transport_sharing', 'multiplex'];

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(ClientInterface::class) || !$container->hasDefinition(ProfilingGuzzleMiddleware::class)) {
            return;
        }

        $profiling = new Reference(ProfilingGuzzleMiddleware::class);

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            // getReflectionClass() resolves parameters and survives classes whose parent comes from a missing
            // package; is_a() would autoload them into a fatal error.
            $reflection = $container->getReflectionClass($definition->getClass(), false);
            if (!$reflection instanceof ReflectionClass || !$reflection->implementsInterface(ClientInterface::class)) {
                continue;
            }

            $config = $this->constructorConfig($definition, $reflection);
            if ($config !== null) {
                $this->profileThroughConfig($container, $id, $definition, $config, $profiling);

                continue;
            }

            if (!$reflection->hasMethod('getConfig')) {
                $container->log($this, sprintf(self::UNREACHABLE_CLIENT_LOG, $id, Client::class, ProfilingGuzzleMiddleware::class));

                continue;
            }

            $configurator = $definition->getConfigurator();
            $definition->setConfigurator($configurator === null
                ? [$profiling, 'addHandler']
                : [new Definition(ChainedClientConfigurator::class, [$configurator, $profiling]), '__invoke']);
        }
    }

    /**
     * The array config of a client built by `GuzzleHttp\Client::__construct()`, or null when the handler cannot be set
     * through it: another constructor, a factory, a parameter or expression resolved only later, or options that make
     * Guzzle 8 build the default handler itself.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return array<array-key, mixed>|null
     */
    private function constructorConfig(Definition $definition, ReflectionClass $reflection): ?array
    {
        if ($definition->getFactory() !== null || $reflection->getConstructor()?->getDeclaringClass()->getName() !== Client::class) {
            return null;
        }

        $config = $definition->getArguments()[0] ?? [];
        if (!is_array($config)) {
            return null;
        }

        $buildsItsOwnHandler = !isset($config['handler']) && array_intersect(self::DEFAULT_HANDLER_OPTIONS, array_keys($config)) !== [];

        return $buildsItsOwnHandler ? null : $config;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function profileThroughConfig(ContainerBuilder $container, string $id, Definition $definition, array $config, Reference $profiling): void
    {
        $handler = $config['handler'] ?? null;
        if ($handler !== null && !$this->isHandlerStack($container, $handler)) {
            $container->log($this, sprintf(self::BARE_HANDLER_LOG, $id, HandlerStack::class));

            return;
        }

        $config['handler'] = (new Definition(HandlerStack::class, [$handler]))
            ->setFactory([$profiling, 'decorateHandler'])
        ;
        $definition->setArgument(0, $config);
    }

    /**
     * Only a stack can take the middleware; decorateHandler() would return anything else unchanged.
     */
    private function isHandlerStack(ContainerBuilder $container, mixed $handler): bool
    {
        if ($handler instanceof Reference) {
            if (!$container->has((string) $handler)) {
                return false;
            }

            $handler = $container->findDefinition((string) $handler);
        }

        if (!$handler instanceof Definition) {
            return false;
        }

        // A class-less definition built by a static factory, e.g. HandlerStack::create(), is of the factory's class.
        $factory = $handler->getFactory();
        $class = $handler->getClass() ?? (is_array($factory) && is_string($factory[0] ?? null) ? $factory[0] : null);
        $reflection = $container->getReflectionClass($class, false);

        // HandlerStack is @final: no subclass to accept.
        return $reflection instanceof ReflectionClass && $reflection->getName() === HandlerStack::class;
    }
}
