<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\DependencyInjection\CompilerPass;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileGuzzlePass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\RegisterGuzzleMiddlewarePass;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ProfilingGuzzleMiddleware;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\Guzzle\ClientWithoutConfig;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\Guzzle\CustomConstructorClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ProfileGuzzlePass::class)]
#[UsesClass(RegisterGuzzleMiddlewarePass::class)]
#[UsesClass(AutoSpan::class)]
#[RequiresMethod(ClientInterface::class, 'send')]
final class ProfileGuzzlePassTest extends TestCase
{
    public function testWrapsTheHandlerOfGuzzleClientsWithoutTouchingOthers(): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class));
        $container->setDefinition('abstract', (new Definition(Client::class))->setAbstract(true));
        $container->setDefinition('other', new Definition(stdClass::class));
        $container->setDefinition('no class', new Definition());

        (new ProfileGuzzlePass())->process($container);

        self::assertEquals([['handler' => $this->profiledHandler(null)]], $container->getDefinition('github')->getArguments());
        self::assertNull($container->getDefinition('github')->getConfigurator());
        self::assertSame([], $container->getCompiler()->getLog());
        self::assertSame([], $container->getDefinition('abstract')->getArguments());
        self::assertSame([], $container->getDefinition('other')->getArguments());
    }

    public function testKeepsTheClientConfigAndItsHandler(): void
    {
        $container = $this->container();
        $container->setDefinition('app.stack', new Definition(HandlerStack::class));
        $container->setDefinition('github', new Definition(Client::class, [['handler' => new Reference('app.stack'), 'timeout' => 3]]));

        (new ProfileGuzzlePass())->process($container);

        self::assertEquals(
            [['handler' => $this->profiledHandler(new Reference('app.stack')), 'timeout' => 3]],
            $container->getDefinition('github')->getArguments(),
        );
    }

    public function testProfilesANamedConfigArgumentOnceTheContainerResolvedIt(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(AutoSpan::GUZZLE->option(), true);
        $container->setDefinition(ProfilingFactory::class, (new Definition(ProfilingFactory::class))->setSynthetic(true));
        $container->setAlias(DetachedSpanFactoryInterface::class, ProfilingFactory::class);
        $container->setDefinition('github', (new Definition(Client::class, ['$config' => ['timeout' => 3]]))->setPublic(true));
        $container->addCompilerPass(new RegisterGuzzleMiddlewarePass());
        $container->addCompilerPass(new ProfileGuzzlePass(), PassConfig::TYPE_BEFORE_REMOVING);

        $container->compile(true);

        $config = $container->getDefinition('github')->getArguments()[0] ?? null;
        self::assertIsArray($config);
        self::assertSame(3, $config['timeout'] ?? null);
        $handler = $config['handler'] ?? null;
        self::assertInstanceOf(Definition::class, $handler);
        self::assertSame(HandlerStack::class, $handler->getClass());
        $factory = $handler->getFactory();
        self::assertIsArray($factory);
        self::assertSame('decorateHandler', $factory[1]);
        // The middleware is used once, so the container inlines it.
        self::assertInstanceOf(Definition::class, $factory[0]);
        self::assertSame(ProfilingGuzzleMiddleware::class, $factory[0]->getClass());
    }

    /**
     * Guzzle 8 builds its default handler from these options and rejects them next to a given handler.
     */
    #[TestWith(['max_host_connections', 4])]
    #[TestWith(['max_total_connections', 16])]
    #[TestWith(['transport_sharing', 'none'])]
    #[TestWith(['multiplex', 'none'])]
    public function testConfiguresAClientWhoseDefaultHandlerOptionsForbidAHandler(string $option, int|string $value): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class, [[$option => $value, 'timeout' => 3]]));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame([[$option => $value, 'timeout' => 3]], $container->getDefinition('github')->getArguments());
        self::assertEquals([new Reference(ProfilingGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('github')->getConfigurator());
    }

    public function testProfilesAClientWithItsOwnConfiguratorAndKeepsIt(): void
    {
        $container = $this->container();
        $container->setDefinition('own', (new Definition(Client::class))->setConfigurator(['app', 'configure']));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame(['app', 'configure'], $container->getDefinition('own')->getConfigurator());
        self::assertEquals([['handler' => $this->profiledHandler(null)]], $container->getDefinition('own')->getArguments());
    }

    public function testResolvesParameterisedClasses(): void
    {
        $container = $this->container();
        $container->setParameter('app.client.class', Client::class);
        $container->setDefinition('github', new Definition('%app.client.class%'));

        (new ProfileGuzzlePass())->process($container);

        self::assertCount(1, $container->getDefinition('github')->getArguments());
    }

    public function testConfiguresAClientBuiltByAFactory(): void
    {
        $container = $this->container();
        $container->setDefinition('built', (new Definition(Client::class, ['token']))->setFactory(['app.factory', 'create']));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame(['token'], $container->getDefinition('built')->getArguments());
        self::assertEquals([new Reference(ProfilingGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('built')->getConfigurator());
    }

    public function testChainsTheOwnConfiguratorOfAClientBuiltByAFactory(): void
    {
        $container = $this->container();
        $container->setDefinition('built', (new Definition(Client::class))->setFactory(['app.factory', 'create'])->setConfigurator(['app', 'configure']));

        (new ProfileGuzzlePass())->process($container);

        $configurator = $container->getDefinition('built')->getConfigurator();
        self::assertIsArray($configurator);
        self::assertSame('__invoke', $configurator[1]);
        self::assertInstanceOf(Definition::class, $configurator[0]);
        self::assertSame(ChainedClientConfigurator::class, $configurator[0]->getClass());
        self::assertEquals([['app', 'configure'], new Reference(ProfilingGuzzleMiddleware::class)], $configurator[0]->getArguments());
    }

    public function testConfiguresAClientWhoseConfigIsAParameter(): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class, ['%app.guzzle_config%']));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame(['%app.guzzle_config%'], $container->getDefinition('github')->getArguments());
        self::assertEquals([new Reference(ProfilingGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('github')->getConfigurator());
    }

    public function testConfiguresAClientWithItsOwnConstructor(): void
    {
        $container = $this->container();
        $container->setDefinition('custom', new Definition(CustomConstructorClient::class, ['token']));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame(['token'], $container->getDefinition('custom')->getArguments());
        self::assertEquals([new Reference(ProfilingGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('custom')->getConfigurator());
    }

    public function testLeavesAClientItCannotReachAndLogsIt(): void
    {
        if (method_exists(ClientInterface::class, 'getConfig')) {
            self::markTestSkipped('Guzzle 7 clients always have getConfig()');
        }

        $container = $this->container();
        $container->setDefinition('custom', (new Definition(ClientWithoutConfig::class))->setConfigurator(['app', 'configure']));
        $container->setDefinition('last', new Definition(Client::class));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame(['app', 'configure'], $container->getDefinition('custom')->getConfigurator());
        self::assertSame(
            [ProfileGuzzlePass::class . ': Guzzle client "custom" is not profiled: its handler cannot be set from an array config of ' . Client::class
                . '::__construct() and it has no getConfig(); create it with the handler returned by ' . ProfilingGuzzleMiddleware::class . '::decorateHandler().'],
            $container->getCompiler()->getLog(),
        );
        self::assertEquals([['handler' => $this->profiledHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    public function testHandlesEveryClientWhateverComesBefore(): void
    {
        $container = $this->container();
        $container->setDefinition('abstract', (new Definition(Client::class))->setAbstract(true));
        $container->setDefinition('other', new Definition(stdClass::class));
        $container->setDefinition('github', new Definition(Client::class));
        $container->setDefinition('custom', new Definition(CustomConstructorClient::class, ['token']));
        $container->setDefinition('last', new Definition(Client::class));

        (new ProfileGuzzlePass())->process($container);

        self::assertCount(1, $container->getDefinition('github')->getArguments());
        self::assertNotNull($container->getDefinition('custom')->getConfigurator());
        self::assertEquals([['handler' => $this->profiledHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    public function testLeavesClientsAloneWithoutTheMiddlewareService(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('github', new Definition(Client::class));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame([], $container->getDefinition('github')->getArguments());
    }

    /**
     * @return iterable<string, array{Reference|Definition|string}>
     */
    public static function bareHandlers(): iterable
    {
        yield 'service of another class' => [new Reference('app.mock_handler')];
        yield 'missing service' => [new Reference('app.missing')];
        yield 'inline definition of another class' => [new Definition(MockHandler::class)];
        yield 'inline definition without class' => [new Definition()];
        yield 'inline definition of a missing class' => [new Definition('Not\\Installed\\Handler')];
        yield 'container parameter' => ['%app.guzzle_handler%'];
        yield 'definition built by another factory' => [(new Definition())->setFactory([MockHandler::class, 'create'])];
        yield 'declared class wins over the factory class' => [(new Definition(MockHandler::class))->setFactory([HandlerStack::class, 'create'])];
    }

    #[DataProvider('bareHandlers')]
    public function testLeavesAClientWithABareHandlerAloneAndLogsIt(Reference|Definition|string $handler): void
    {
        $container = $this->container();
        $container->setDefinition('app.mock_handler', new Definition(MockHandler::class));
        $container->setDefinition('github', new Definition(Client::class, [['handler' => $handler]]));
        $container->setDefinition('last', new Definition(Client::class));

        (new ProfileGuzzlePass())->process($container);

        self::assertSame([['handler' => $handler]], $container->getDefinition('github')->getArguments());
        self::assertNull($container->getDefinition('github')->getConfigurator());
        self::assertSame(
            [ProfileGuzzlePass::class . ': Guzzle client "github" is not profiled: its "handler" is not a ' . HandlerStack::class . '.'],
            $container->getCompiler()->getLog(),
        );
        self::assertEquals([['handler' => $this->profiledHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    /**
     * @return iterable<string, array{Definition}>
     */
    public static function handlerStacks(): iterable
    {
        yield 'HandlerStack' => [new Definition(HandlerStack::class)];
        yield 'class-less definition built by HandlerStack::create()' => [(new Definition())->setFactory([HandlerStack::class, 'create'])];
    }

    #[DataProvider('handlerStacks')]
    public function testProfilesAnInlineHandlerStack(Definition $handler): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class, [['handler' => $handler]]));

        (new ProfileGuzzlePass())->process($container);

        self::assertEquals([['handler' => $this->profiledHandler($handler)]], $container->getDefinition('github')->getArguments());
        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testProfilesAHandlerStackServiceBehindAnAlias(): void
    {
        $container = $this->container();
        $container->setDefinition('app.stack', (new Definition(HandlerStack::class))->setFactory([HandlerStack::class, 'create']));
        $container->setAlias('app.stack_alias', 'app.stack');
        $container->setDefinition('github', new Definition(Client::class, [['handler' => new Reference('app.stack_alias')]]));

        (new ProfileGuzzlePass())->process($container);

        self::assertEquals([['handler' => $this->profiledHandler(new Reference('app.stack_alias'))]], $container->getDefinition('github')->getArguments());
        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testSurvivesClassesWithAMissingParent(): void
    {
        // Generated at run time: a source file extending a missing class would fail static analysis.
        $class = 'Msstc4Symfony\\ProfilingBundle\\Test\\Generated\\OrphanClient';
        $file = sys_get_temp_dir() . '/msstc4symfony-profiling-orphan-client-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace Msstc4Symfony\\ProfilingBundle\\Test\\Generated;\nfinal class OrphanClient extends \\Not\\Installed\\BaseClient {}\n");
        $autoload = static function (string $name) use ($class, $file): void {
            if ($name === $class) {
                require $file;
            }
        };
        spl_autoload_register($autoload);

        try {
            $container = $this->container();
            $container->setDefinition('orphan', new Definition($class));
            $container->setDefinition('orphan_handler', new Definition(Client::class, [['handler' => new Definition($class)]]));

            (new ProfileGuzzlePass())->process($container);

            self::assertNull($container->getDefinition('orphan')->getConfigurator());
            self::assertSame([], $container->getDefinition('orphan')->getArguments());
            self::assertEquals([['handler' => new Definition($class)]], $container->getDefinition('orphan_handler')->getArguments());
        } finally {
            spl_autoload_unregister($autoload);
            unlink($file);
        }
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProfilingGuzzleMiddleware::class, new Definition(ProfilingGuzzleMiddleware::class));

        return $container;
    }

    private function profiledHandler(Reference|Definition|null $handler): Definition
    {
        return (new Definition(HandlerStack::class, [$handler]))
            ->setFactory([new Reference(ProfilingGuzzleMiddleware::class), 'decorateHandler'])
        ;
    }
}
