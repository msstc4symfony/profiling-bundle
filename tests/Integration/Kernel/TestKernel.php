<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel;

use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\AllowSpanDecisionMakerInterface;
use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ProfiledMessage;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    // Per process: parallel PHPUnit runs must not wipe each other's container.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-profiling-bundle-test-' . getmypid();
    }

    public static function hasMonologBundle(): bool
    {
        return class_exists(MonologBundle::class);
    }

    public static function hasMessenger(): bool
    {
        return class_exists(Worker::class);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        // Optional (composer-ci.json only): the bundle must also boot without it.
        if (self::hasMonologBundle()) {
            yield new MonologBundle();
        }

        yield new ProfilingBundle();
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
            'test' => true,
            'router' => ['utf8' => true],
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
        ]);
        $container->extension('msstc4symfony_profiling', [
            'routes' => ['ping', '\\kept'],
            'commands' => ['test:ping', 'messenger:consume'],
            'messages' => ['\\App\\Message\\Import', ProfiledMessage::class],
            'spans' => ['blacklist' => ['sql ']],
        ]);

        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure();
        $services->set(OrphanSpanOpener::class)->public();
        $services->set(PingCommand::class);
        $services->set(AbstainingDecisionMaker::class);
        $services->set(LateDecisionMaker::class);
        // Not resettable, unlike the monolog TestHandler: keeps spans ended before a kernel.reset.
        $services->set('test.recorder', RecordingEndProcessor::class)->public();
        // Unused services are removed on compile; the tests fetch these.
        $services->alias('test.services_resetter', 'services_resetter')->public();

        if (self::hasMessenger()) {
            $container->extension('framework', [
                'messenger' => [
                    'transports' => ['memory' => 'in-memory://'],
                    'routing' => [ProfiledMessage::class => 'memory'],
                ],
            ]);
            $services->set(ProfiledMessageHandler::class);
            $services->alias('test.message_bus', 'messenger.default_bus')->public();
        }

        if (self::hasMonologBundle()) {
            $container->extension('monolog', [
                'handlers' => ['profiling' => ['type' => 'test', 'channels' => ['profiling']]],
            ]);
            $services->alias('test.profiling_handler', 'monolog.handler.profiling')->public();
        }
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

final class AbstainingDecisionMaker implements AllowSpanDecisionMakerInterface
{
    #[Override]
    public function isAllowed(string $message): ?bool
    {
        return null;
    }
}

/**
 * Registered before the bundle's maker; only its priority can put it after it.
 */
#[AsTaggedItem(priority: -2048)]
final class LateDecisionMaker implements AllowSpanDecisionMakerInterface
{
    #[Override]
    public function isAllowed(string $message): ?bool
    {
        return null;
    }
}
