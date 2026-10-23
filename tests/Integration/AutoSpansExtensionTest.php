<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpanRequirements;
use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Loads the extension, services.php included, so it stays out of the unit suite's coverage.
 */
#[CoversNothing]
final class AutoSpansExtensionTest extends TestCase
{
    public function testTheFlagsReachTheParameters(): void
    {
        $requirements = new AutoSpanRequirements(['dbal' => [], 'http_client' => [], 'guzzle' => []]);

        $container = $this->load(['auto_spans' => ['dbal' => true, 'guzzle' => true]], $requirements);

        self::assertTrue($container->getParameter(AutoSpan::DBAL->option()));
        self::assertFalse($container->getParameter(AutoSpan::HTTP_CLIENT->option()));
        self::assertTrue($container->getParameter(AutoSpan::GUZZLE->option()));
        self::assertSame(ProfilingFactory::class, (string) $container->getAlias(DetachedSpanFactoryInterface::class));
    }

    public function testEveryAutoSpanIsOffByDefaultInTheParameters(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter(AutoSpan::DBAL->option()));
        self::assertFalse($container->getParameter(AutoSpan::HTTP_CLIENT->option()));
        self::assertFalse($container->getParameter(AutoSpan::GUZZLE->option()));
    }

    #[TestWith(['dbal'])]
    #[TestWith(['http_client'])]
    #[TestWith(['guzzle'])]
    public function testAnEnvironmentVariableCannotSwitchAnAutoSpan(string $autoSpan): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new ProfilingBundle(new AutoSpanRequirements([])))->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('msstc4symfony_profiling', ['auto_spans' => [$autoSpan => '%env(bool:PROFILE)%']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"msstc4symfony_profiling.auto_spans.%s" must be true or false; an environment variable cannot decide the container wiring.', $autoSpan));

        (new MergeExtensionConfigurationPass())->process($container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, AutoSpanRequirements $requirements = new AutoSpanRequirements([])): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new ProfilingBundle($requirements))->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
