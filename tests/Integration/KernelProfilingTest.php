<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\ProfilingBundle\EventListener\ConsoleEventListener;
use Msstc4Symfony\ProfilingBundle\EventListener\MessageEventListener;
use Msstc4Symfony\ProfilingBundle\EventListener\RequestEventListener;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AbstainingDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\LateDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ProfiledMessage;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * One request or command per test: a second kernel->handle() runs services_resetter, which
 * also clears the monolog TestHandler.
 *
 * symfony/monolog-bundle and symfony/messenger are optional (composer-ci.json only): without
 * them the kernel still boots and the tests needing them are skipped. PHPUnit has no
 * "requires class" attribute: #[RequiresMethod] on a method of the class stands in for it.
 */
#[CoversNothing]
final class KernelProfilingTest extends TestCase
{
    private const int MAX_HANDLER_UNWIND = 8;

    private TestKernel $kernel;

    /** @var (callable(Throwable): void)|null */
    private $exceptionHandler;

    protected function setUp(): void
    {
        $this->exceptionHandler = $this->currentExceptionHandler();
        new Filesystem()->remove(TestKernel::cacheRoot());
        $this->kernel = new TestKernel('test', false);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        new Filesystem()->remove(TestKernel::cacheRoot());
        // symfony/error-handler < 6.4.44 leaves the exception handler FrameworkBundle::boot() pushes
        // on top of PHPUnit's; failOnRisky turns that into a failure on the lowest dependencies.
        for ($i = 0; $i < self::MAX_HANDLER_UNWIND && $this->currentExceptionHandler() !== $this->exceptionHandler; $i++) {
            restore_exception_handler();
        }
    }

    /**
     * @return (callable(Throwable): void)|null
     */
    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testWhitelistedRouteIsLoggedOnTheProfilingChannel(): void
    {
        $this->handle('/ping');

        $records = $this->handler()->getRecords();
        self::assertCount(1, $records);
        self::assertSame('request ping', $records[0]->message);
        self::assertSame('profiling', $records[0]->channel);
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testOtherRoutesAreNotProfiled(): void
    {
        $this->handle('/other');

        self::assertSame([], $this->handler()->getRecords());
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testSpansLeftOpenByARequestAreEndedOnTerminate(): void
    {
        $this->handle('/orphan');

        self::assertSame(['orphan'], $this->messages());
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testWhitelistedCommandIsProfiled(): void
    {
        $this->runCommand('test:ping');

        self::assertSame(['cli command test:ping'], $this->messages());
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testSpansLeftOpenByACommandAreEndedOnTerminate(): void
    {
        $this->runCommand('test:orphan');

        self::assertSame(['orphan'], $this->messages());
    }

    #[TestWith([KernelEvents::TERMINATE, RequestEventListener::class])]
    #[TestWith([ConsoleEvents::TERMINATE, ConsoleEventListener::class])]
    public function testTerminateEndsTheOwnSpanThenEverythingElse(string $event, string $listener): void
    {
        $dispatcher = $this->testContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $methods = [];
        foreach ($dispatcher->getListeners($event) as $callable) {
            if (is_array($callable) && $callable[0] instanceof $listener && is_string($callable[1])) {
                $methods[] = $callable[1];
            }
        }

        self::assertSame(['onTerminate', 'onTerminateEnd'], $methods);
    }

    #[RequiresMethod(Worker::class, 'run')]
    public function testMessageListenerIsRegisteredWithMessenger(): void
    {
        $dispatcher = $this->testContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach ([WorkerMessageReceivedEvent::class, WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            self::assertNotSame([], array_filter(
                $dispatcher->getListeners($event),
                static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof MessageEventListener,
            ), $event);
        }
    }

    #[RequiresMethod(Worker::class, 'run')]
    public function testMessageSpansAreClosedBeforeKernelReset(): void
    {
        self::assertSame(-1024, $this->listenerPriority(WorkerMessageReceivedEvent::class, 'onReceived'));
        // messenger:consume subscribes ResetServicesListener at run time; compare with its declared priority.
        $reset = ResetServicesListener::getSubscribedEvents()[WorkerRunningEvent::class];
        self::assertIsArray($reset);
        self::assertIsInt($reset[1] ?? null);
        self::assertGreaterThan($reset[1], $this->listenerPriority(WorkerRunningEvent::class, 'onWorkerRunning'));
    }

    #[RequiresMethod(Worker::class, 'run')]
    public function testAConsumeCommandSpanCoversTheWholeRunDespiteResetsAfterEachMessage(): void
    {
        $bus = $this->testContainer()->get('test.message_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new ProfiledMessage());

        $this->runCommand('messenger:consume', ['receivers' => ['memory'], '--limit' => 1]);

        $recorder = $this->recorder();
        self::assertSame(['message ' . ProfiledMessage::class, 'cli command messenger:consume'], $recorder->messages());
        self::assertSame([], $recorder->ended[1][1], 'ended by console.terminate, not by kernel.reset');
    }

    public function testApplicationDecisionMakersAreAskedBeforeTheListBasedOne(): void
    {
        $assembler = $this->testContainer()->get(SpanAssembler::class);
        self::assertInstanceOf(SpanAssembler::class, $assembler);
        $makers = new ReflectionProperty($assembler, 'allowSpanDecisionMakers')->getValue($assembler);
        self::assertIsIterable($makers);

        $classes = [];
        foreach ($makers as $maker) {
            self::assertIsObject($maker);
            $classes[] = $maker::class;
        }

        self::assertSame([AbstainingDecisionMaker::class, ListBasedDecisionMaker::class, LateDecisionMaker::class], $classes);
    }

    public function testConfigurationReachesTheParameters(): void
    {
        $container = $this->kernel->getContainer();

        self::assertSame(['ping', '\\kept'], $container->getParameter('msstc4symfony_profiling.routes.whitelist'));
        self::assertSame(['test:ping', 'messenger:consume'], $container->getParameter('msstc4symfony_profiling.commands.whitelist'));
        self::assertSame(['App\\Message\\Import', ProfiledMessage::class], $container->getParameter('msstc4symfony_profiling.messages.whitelist'));
        self::assertNull($container->getParameter('msstc4symfony_profiling.spans.whitelist'));
        self::assertSame(['sql '], $container->getParameter('msstc4symfony_profiling.spans.blacklist'));
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testKernelResetEndsOpenSpans(): void
    {
        $factory = $this->testContainer()->get(ProfilingFactoryInterface::class);
        self::assertInstanceOf(ProfilingFactoryInterface::class, $factory);
        $factory->createSpan('worker job');

        $resetter = $this->kernel->getContainer()->get('test.services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertSame(['worker job'], $this->messages());
    }

    public function testBothSpanListsCannotBeSet(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set either "whitelist" or "blacklist"');

        $this->processConfig(['spans' => ['whitelist' => ['a'], 'blacklist' => ['b']]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[TestWith([['routes' => [1]]])]
    #[TestWith([['spans' => ['whitelist' => 'request ']]])]
    public function testListsMustHoldStrings(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function processConfig(array $config): void
    {
        $extension = new ProfilingBundle()->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);
        new Processor()->processConfiguration($configuration, [$config]);
    }

    private function listenerPriority(string $event, string $method): ?int
    {
        $dispatcher = $this->testContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach ($dispatcher->getListeners($event) as $listener) {
            if (is_array($listener) && $listener[0] instanceof MessageEventListener && $listener[1] === $method) {
                return $dispatcher->getListenerPriority($event, $listener);
            }
        }

        self::fail(sprintf('No %s::%s listener on %s.', MessageEventListener::class, $method, $event));
    }

    private function handle(string $path): void
    {
        $request = Request::create($path);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
    }

    /**
     * @param array<string, string|int|list<string>> $arguments
     */
    private function runCommand(string $name, array $arguments = []): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $application->run(new ArrayInput(['command' => $name] + $arguments), new NullOutput());
    }

    private function recorder(): RecordingEndProcessor
    {
        $recorder = $this->kernel->getContainer()->get('test.recorder');
        self::assertInstanceOf(RecordingEndProcessor::class, $recorder);

        return $recorder;
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        $messages = [];
        foreach ($this->handler()->getRecords() as $record) {
            $messages[] = $record->message;
        }

        return $messages;
    }

    private function handler(): TestHandler
    {
        $handler = $this->kernel->getContainer()->get('test.profiling_handler');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }

    private function testContainer(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
