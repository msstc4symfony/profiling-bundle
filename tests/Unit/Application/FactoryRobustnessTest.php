<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Application;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\FailingSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End processors and custom spans that misbehave: profiling neither hangs nor throws into the profiled code.
 */
#[CoversClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
final class FactoryRobustnessTest extends TestCase
{
    private const STOPPED = 'Profiling stopped ending spans after {passes} passes: end processors keep opening spans.';

    private RecordingEndProcessor $recorder;

    private TestHandler $logs;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->logs = new TestHandler();
    }

    public function testEndAllStopsWhenProcessorsKeepOpeningStackSpans(): void
    {
        $factory = $this->factory();
        $factory->createSpan('first');

        $this->recorder->onProcess = static function () use ($factory): void {
            $factory->createSpan('opened by a processor');
        };

        $factory->endAll();

        self::assertCount(ProfilingFactory::MAX_END_PASSES, $this->recorder->messages());
        $this->assertStoppedOnce();
    }

    public function testResetStopsWhenProcessorsKeepOpeningSpansAboveAKeptSpan(): void
    {
        $factory = $this->factory();
        $worker = $factory->createSpan('worker');
        $factory->keepOpenOnReset($worker);
        $factory->createSpan('job');

        $this->recorder->onProcess = static function () use ($factory): void {
            $factory->createSpan('opened by a processor');
        };

        $factory->reset();

        self::assertFalse($worker->isEnded());
        self::assertNotContains('worker', $this->recorder->messages());
        $this->assertStoppedOnce();
    }

    public function testEndingEverythingInOneRoundLogsNothing(): void
    {
        $factory = $this->factory();
        $factory->createSpan('outer');
        $factory->createSpan('inner');
        $factory->createDetachedSpan('detached');

        $factory->endAll();

        self::assertSame(['detached', 'inner', 'outer'], $this->recorder->messages());
        self::assertSame([], $this->logs->getRecords());
    }

    public function testEndAllDropsAStackSpanWithoutTheFactoryHandlerInOneRound(): void
    {
        $factory = $this->factory();
        $root = $factory->createSpan('handler removed')->removeEndHandler(0);
        $factory->createSpan('inner');

        $factory->endAll();

        self::assertTrue($root->isEnded());
        self::assertSame(['inner'], $this->recorder->messages());
        self::assertNull($factory->createSpan('next')->getParentSpan());
        self::assertSame([], $this->logs->getRecords());
    }

    public function testAFailingAssemblerDoesNotLeaveTheFactoryCreatingASpan(): void
    {
        $failing = new class implements SpanAssemblerInterface {
            public bool $fail = true;

            #[Override]
            public function assemble(string $message, array $context): SpanInterface
            {
                if ($this->fail) {
                    $this->fail = false;

                    throw new RuntimeException('assembler bug');
                }

                return new Span($message, $context);
            }
        };
        $factory = new ProfilingFactory([$failing], [], [$this->recorder], new Logger('test', [$this->logs]));

        $factory->createSpan('first')->end();
        $automatic = $factory->createSpan('sql select users', DetachedSpanFactoryInterface::AUTOMATIC);

        self::assertTrue($automatic->isRecorded());
    }

    public function testACustomSpanFailingToTakeItsParentIsLoggedAndStillTracked(): void
    {
        $custom = new FailingSpan('custom', failsToSetParent: true, failsToAddHandler: false);
        $factory = $this->factoryAssembling($custom);
        $factory->createSpan('outer');

        self::assertSame($custom, $factory->createSpan('custom'));
        self::assertSame($custom, $factory->createSpan('next')->getParentSpan());
        $this->assertLogged('Profiling could not set the parent of span "{span}".');
    }

    public function testACustomSpanRefusingTheFactoryHandlerIsLoggedAndNotTracked(): void
    {
        $custom = new FailingSpan('custom', failsToSetParent: false, failsToAddHandler: true);
        $factory = $this->factoryAssembling($custom);
        $outer = $factory->createSpan('outer');

        self::assertSame($custom, $factory->createSpan('custom'));
        self::assertSame($custom, $factory->createDetachedSpan('custom'));
        self::assertSame($outer, $factory->createSpan('next')->getParentSpan());
        $this->assertLogged('Profiling could not watch the end of span "{span}".');

        $factory->endAll();

        self::assertSame(['next', 'outer'], $this->recorder->messages());
    }

    private function factoryAssembling(SpanInterface $custom): ProfilingFactory
    {
        $assembler = new class($custom) implements SpanAssemblerInterface {
            public function __construct(
                private readonly SpanInterface $span,
            ) {
            }

            #[Override]
            public function assemble(string $message, array $context): SpanInterface
            {
                return $message === 'custom' ? $this->span : new Span($message, $context);
            }
        };

        return new ProfilingFactory([$assembler], [], [$this->recorder], new Logger('test', [$this->logs]));
    }

    private function assertLogged(string $message): void
    {
        $records = $this->logs->getRecords();
        self::assertNotSame([], $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame($message, $records[0]->message);
        self::assertSame('decorated custom', $records[0]->context['span'] ?? null);
    }

    private function assertStoppedOnce(): void
    {
        $records = $this->logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame(self::STOPPED, $records[0]->message);
        self::assertSame(['passes' => ProfilingFactory::MAX_END_PASSES], $records[0]->context);
    }

    private function factory(): ProfilingFactory
    {
        return new ProfilingFactory([new SpanAssembler([])], [], [$this->recorder], new Logger('test', [$this->logs]));
    }
}
