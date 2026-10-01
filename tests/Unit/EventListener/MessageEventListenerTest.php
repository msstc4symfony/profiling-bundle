<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\EventListener;

use Msstc4Symfony\ProfilingBundle\EventListener\MessageEventListener;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ProfiledMessage;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ProfiledMessageInterface;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ThrowingEndHandlerProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * symfony/messenger is optional (composer-ci.json only): the minimal install skips this class.
 */
#[CoversClass(MessageEventListener::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[RequiresMethod(Envelope::class, 'getMessage')]
final class MessageEventListenerTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    private ProfilingFactory $factory;

    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->factory = new ProfilingFactory([new SpanAssembler()], [], [$this->recorder]);
    }

    public function testProfilesWhitelistedMessagesWhileTheWorkerHandlesThem(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessageInterface::class]);
        $profiled = new Envelope(new ProfiledMessage());

        $listener->onReceived(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $listener->onHandled(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        $listener->onReceived(new WorkerMessageReceivedEvent($profiled, 'async'));
        $listener->onHandled(new WorkerMessageHandledEvent($profiled, 'async'));
        $listener->onReceived(new WorkerMessageReceivedEvent($profiled, 'async'));

        $retried = new WorkerMessageFailedEvent($profiled, 'async', new RuntimeException('boom'));
        $retried->setForRetry();

        $listener->onFailed($retried);

        self::assertSame([
            ['message ' . ProfiledMessage::class, []],
            ['message ' . ProfiledMessage::class, ['message_failed' => true, 'message_will_retry' => true]],
        ], $this->recorder->ended);
    }

    public function testBatchedMessagesDoNotTakeEachOthersOutcome(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $first = new Envelope(new ProfiledMessage());
        $second = new Envelope(new ProfiledMessage());

        $listener->onReceived(new WorkerMessageReceivedEvent($first, 'async'));
        $listener->onReceived(new WorkerMessageReceivedEvent($second, 'async'));
        $listener->onFailed(new WorkerMessageFailedEvent($first, 'async', new RuntimeException('boom')));
        $listener->onHandled(new WorkerMessageHandledEvent($second, 'async'));

        self::assertSame([
            ['message ' . ProfiledMessage::class, MessageEventListener::NOT_ACKNOWLEDGED],
            ['message ' . ProfiledMessage::class, []],
        ], $this->recorder->ended);
        $this->factory->endAll();
        self::assertCount(2, $this->recorder->ended);
    }

    public function testHandledEventOfAnotherMessageDoesNotCloseTheSpan(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $first = new Envelope(new ProfiledMessage());
        $second = new Envelope(new ProfiledMessage());
        $listener->onReceived(new WorkerMessageReceivedEvent($first, 'async'));
        $listener->onReceived(new WorkerMessageReceivedEvent($second, 'async'));

        $listener->onHandled(new WorkerMessageHandledEvent($first, 'async'));
        self::assertCount(1, $this->recorder->ended);

        $listener->onHandled(new WorkerMessageHandledEvent($second, 'async'));
        self::assertCount(2, $this->recorder->ended);
    }

    public function testDeferredBatchSpanEndsWhenTheWorkerMovesOn(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $listener->onReceived(new WorkerMessageReceivedEvent(new Envelope(new ProfiledMessage()), 'async'));

        $listener->onWorkerRunning();
        $listener->onWorkerRunning();

        self::assertSame([['message ' . ProfiledMessage::class, MessageEventListener::NOT_ACKNOWLEDGED]], $this->recorder->ended);
    }

    public function testVetoedMessagesAreNotProfiled(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $event = new WorkerMessageReceivedEvent(new Envelope(new ProfiledMessage()), 'async');
        $event->shouldHandle(false);

        $listener->onReceived($event);
        $this->factory->endAll();

        self::assertSame([], $this->recorder->ended);
    }

    public function testMessageListenerResetForgetsTheSpan(): void
    {
        $listener = new MessageEventListener($this->factory, [ProfiledMessage::class]);
        $envelope = new Envelope(new ProfiledMessage());
        $listener->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $listener->reset();
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertSame([], $this->recorder->ended);
    }

    public function testThrowingEndHandlersNeverEscapeTheListener(): void
    {
        $factory = new ProfilingFactory([new SpanAssembler()], [new ThrowingEndHandlerProcessor()], [$this->recorder]);
        $listener = new MessageEventListener($factory, [ProfiledMessage::class]);

        $envelope = new Envelope(new ProfiledMessage());
        $listener->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertSame(['message ' . ProfiledMessage::class], $this->recorder->messages());
    }
}
