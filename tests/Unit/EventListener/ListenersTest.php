<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\EventListener;

use Msstc4Symfony\ProfilingBundle\EventListener\ConsoleEventListener;
use Msstc4Symfony\ProfilingBundle\EventListener\RequestEventListener;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ThrowingEndHandlerProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(RequestEventListener::class)]
#[CoversClass(ConsoleEventListener::class)]
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

    public function testCommandTerminateClosesSpansLeftOpenByTheCommand(): void
    {
        $listener = new ConsoleEventListener($this->factory, []);
        $this->factory->createSpan('left open by the command');

        $listener->onTerminateEnd();

        self::assertSame(['left open by the command'], $this->recorder->messages());
    }

    public function testCommandWithoutANameIsProfiledAsUnknown(): void
    {
        $listener = new ConsoleEventListener($this->factory, ['unknown']);

        $listener->onCommand(new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()));
        $listener->onTerminate();

        self::assertSame(['cli command unknown'], $this->recorder->messages());
    }

    public function testResetForgetsTheRequestSpanSoTerminateDoesNotEndIt(): void
    {
        $request = new RequestEventListener($this->factory, ['orders']);
        $request->onRequest($this->requestEvent('orders'));

        $request->reset();
        $request->onTerminate();

        self::assertSame([], $this->recorder->ended);
    }

    /**
     * messenger:consume resets services after every message; the command span must cover the whole run.
     */
    public function testACommandSpanOutlivesResetsUntilTheCommandTerminates(): void
    {
        $console = new ConsoleEventListener($this->factory, ['messenger:consume']);
        $console->onCommand(new ConsoleCommandEvent(new Command('messenger:consume'), new ArrayInput([]), new NullOutput()));

        $this->factory->createSpan('message');

        $this->factory->reset();

        $console->reset();
        $console->reset();

        $this->factory->reset();

        self::assertSame(['message'], $this->recorder->messages());

        $console->onTerminate();
        $console->onTerminateEnd();

        self::assertSame(['message', 'cli command messenger:consume'], $this->recorder->messages());
        self::assertSame([], $this->recorder->ended[1][1]);
    }

    public function testThrowingEndHandlersNeverEscapeTheListeners(): void
    {
        $factory = new ProfilingFactory([new SpanAssembler()], [new ThrowingEndHandlerProcessor()], [$this->recorder]);
        $request = new RequestEventListener($factory, ['orders']);
        $console = new ConsoleEventListener($factory, ['app:import']);

        $request->onRequest($this->requestEvent('orders'));
        $request->onTerminate();

        $console->onCommand(new ConsoleCommandEvent(new Command('app:import'), new ArrayInput([]), new NullOutput()));
        $console->onTerminate();

        self::assertSame(['request orders', 'cli command app:import'], $this->recorder->messages());
    }

    private function requestEvent(string $route, int $type = HttpKernelInterface::MAIN_REQUEST, string $method = 'GET'): RequestEvent
    {
        $request = Request::create('/x', $method);
        $request->attributes->set('_route', $route);

        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
    }
}
