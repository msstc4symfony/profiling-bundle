<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\EventListener;

use Msstc4Symfony\ProfilingBundle\EventListener\ConsoleEventListener;
use Msstc4Symfony\ProfilingBundle\EventListener\MessageEventListener;
use Msstc4Symfony\ProfilingBundle\EventListener\RequestEventListener;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

#[CoversClass(RequestEventListener::class)]
#[CoversClass(ConsoleEventListener::class)]
#[CoversClass(MessageEventListener::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
final class ListenersTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    private ProfilingFactory $factory;

    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->factory = new ProfilingFactory([new SpanAssembler()], [], [$this->recorder]);
    }

    public function testProfilesWhitelistedRoutesAndClosesLeftoversOnTerminate(): void
    {
        $listener = new RequestEventListener($this->factory, ['orders']);

        $listener->onRequest($this->requestEvent('orders'));

        $this->factory->createSpan('left open by the app');
        $listener->onTerminate();
        $listener->onTerminateEnd();

        self::assertSame(['left open by the app', 'request orders'], $this->recorder->messages());
    }

    public function testIgnoresOtherRoutesSubRequestsAndPreflight(): void
    {
        $listener = new RequestEventListener($this->factory, ['orders']);

        $listener->onRequest($this->requestEvent('health'));
        $listener->onRequest($this->requestEvent('orders', HttpKernelInterface::SUB_REQUEST));
        $listener->onRequest($this->requestEvent('orders', method: 'OPTIONS'));
        $listener->onTerminate();

        self::assertSame([], $this->recorder->ended);
    }

    public function testProfilesWhitelistedCommands(): void
    {
        $listener = new ConsoleEventListener($this->factory, ['app:import']);

        $listener->onCommand(new ConsoleCommandEvent(new Command('app:import'), new ArrayInput([]), new NullOutput()));
        $listener->onCommand(new ConsoleCommandEvent(new Command('app:other'), new ArrayInput([]), new NullOutput()));
        $listener->onTerminate();
        $listener->onTerminateEnd();

        self::assertSame(['cli command app:import'], $this->recorder->messages());
    }

    public function testCommandWithoutANameIsProfiledAsUnknown(): void
    {
        $listener = new ConsoleEventListener($this->factory, ['unknown']);

        $listener->onCommand(new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()));
        $listener->onTerminate();

        self::assertSame(['cli command unknown'], $this->recorder->messages());
    }

    public function testResetForgetsTheSpanSoTerminateDoesNotEndIt(): void
    {
        $request = new RequestEventListener($this->factory, ['orders']);
        $console = new ConsoleEventListener($this->factory, ['app:import']);
        $request->onRequest($this->requestEvent('orders'));
        $console->onCommand(new ConsoleCommandEvent(new Command('app:import'), new ArrayInput([]), new NullOutput()));

        $request->reset();
        $console->reset();
        $request->onTerminate();
        $console->onTerminate();

        self::assertSame([], $this->recorder->ended);
    }

    public function testProfilesWhitelistedMessagesWhileTheWorkerHandlesThem(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $profiled = new Envelope(new ProfiledMessage());

        $listener->onReceived(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $listener->onHandled();
        $listener->onReceived(new WorkerMessageReceivedEvent($profiled, 'async'));
        $listener->onHandled();
        $listener->onReceived(new WorkerMessageReceivedEvent($profiled, 'async'));
        $listener->onFailed(new WorkerMessageFailedEvent($profiled, 'async', new RuntimeException('boom')));

        self::assertSame([
            ['message ' . ProfiledMessage::class, []],
            ['message ' . ProfiledMessage::class, ['failed' => true, 'will_retry' => false]],
        ], $this->recorder->ended);
    }

    public function testMessageListenerResetForgetsTheSpan(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $listener->onReceived(new WorkerMessageReceivedEvent(new Envelope(new ProfiledMessage()), 'async'));

        $listener->reset();
        $listener->onHandled();

        self::assertSame([], $this->recorder->ended);
    }

    private function requestEvent(string $route, int $type = HttpKernelInterface::MAIN_REQUEST, string $method = 'GET'): RequestEvent
    {
        $request = Request::create('/x', $method);
        $request->attributes->set('_route', $route);

        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
    }
}

final class ProfiledMessage
{
}
