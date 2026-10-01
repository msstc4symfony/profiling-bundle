<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel;

use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    // Per process: parallel PHPUnit runs must not wipe each other's container.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-profiling-bundle-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new ProfilingBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
    }

    public function ping(): Response
    {
        return new Response('pong');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            // Symfony 6.4 deprecates leaving it unset.
            'handle_all_throwables' => true,
            'test' => true,
            'router' => ['utf8' => true],
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
        ]);
        $container->extension('monolog', [
            'handlers' => ['profiling' => ['type' => 'test', 'channels' => ['profiling']]],
        ]);
        $container->extension('msstc4symfony_profiling', [
            'routes' => ['ping'],
            'commands' => ['test:ping'],
        ]);

        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure();
        $services->set(OrphanSpanOpener::class)->public();
        $services->set(PingCommand::class);
        // Unused services are removed on compile; the tests fetch these.
        $services->alias('test.profiling_handler', 'monolog.handler.profiling')->public();
        $services->alias('test.services_resetter', 'services_resetter')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller('kernel::ping');
        $routes->add('other', '/other')->controller('kernel::ping');
        $routes->add('orphan', '/orphan')->controller(OrphanSpanOpener::class . '::controller');
    }
}

#[AsCommand('test:ping')]
final class PingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
