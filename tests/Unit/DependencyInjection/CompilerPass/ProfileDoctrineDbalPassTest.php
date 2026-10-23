<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileDoctrineDbalPass;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\QuerySpansMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ProfileDoctrineDbalPass::class)]
#[UsesClass(AutoSpan::class)]
final class ProfileDoctrineDbalPassTest extends TestCase
{
    #[TestWith(['default'])]
    #[TestWith(['replica'])]
    public function testRegistersOneTaggedMiddlewarePerConnection(string $name): void
    {
        $container = $this->container(true);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'replica' => 'doctrine.dbal.replica_connection']);

        (new ProfileDoctrineDbalPass())->process($container);

        $definition = $container->getDefinition('msstc4symfony_profiling.doctrine.middleware.' . $name);
        self::assertSame(QuerySpansMiddleware::class, $definition->getClass());
        self::assertEquals(['$profiling' => new Reference(DetachedSpanFactoryInterface::class), '$connectionName' => $name], $definition->getArguments());
        self::assertSame(['doctrine.middleware' => [['connection' => $name, 'priority' => -100]]], $definition->getTags());
    }

    public function testKeepsAMiddlewareTheApplicationDefinedAndRegistersTheOthers(): void
    {
        $container = $this->container(true);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'replica' => 'doctrine.dbal.replica_connection']);

        $own = new Definition('App\\Middleware');
        $container->setDefinition('msstc4symfony_profiling.doctrine.middleware.default', $own);

        (new ProfileDoctrineDbalPass())->process($container);

        self::assertSame($own, $container->getDefinition('msstc4symfony_profiling.doctrine.middleware.default'));
        self::assertSame(QuerySpansMiddleware::class, $container->getDefinition('msstc4symfony_profiling.doctrine.middleware.replica')->getClass());
    }

    public function testANumericConnectionNameStaysAString(): void
    {
        $container = $this->container(true);
        $container->setParameter('doctrine.connections', ['1' => 'doctrine.dbal.1_connection']);

        (new ProfileDoctrineDbalPass())->process($container);

        $definition = $container->getDefinition('msstc4symfony_profiling.doctrine.middleware.1');
        self::assertSame('1', $definition->getArgument('$connectionName'));
        self::assertSame([['connection' => '1', 'priority' => -100]], $definition->getTag('doctrine.middleware'));
    }

    #[TestWith([false])]
    #[TestWith([null])]
    public function testDoesNothingWhenDbalSpansAreOff(?bool $enabled): void
    {
        $container = $this->container($enabled);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);

        (new ProfileDoctrineDbalPass())->process($container);

        self::assertFalse($container->hasDefinition('msstc4symfony_profiling.doctrine.middleware.default'));
    }

    #[TestWith([null])]
    #[TestWith(['default'])]
    public function testFailsWithoutDoctrineBundleConnections(?string $connections): void
    {
        $container = $this->container(true);
        if ($connections !== null) {
            $container->setParameter('doctrine.connections', $connections);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"msstc4symfony_profiling.auto_spans.dbal" needs DoctrineBundle registered with doctrine.dbal configured.');

        (new ProfileDoctrineDbalPass())->process($container);
    }

    private function container(?bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($enabled !== null) {
            $container->setParameter(AutoSpan::DBAL->option(), $enabled);
        }

        return $container;
    }
}
