<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Application;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Application\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\NullProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\DecoratingSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ProfilingFactory::class)]
#[CoversClass(NullProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(ListBasedDecisionMaker::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
final class DetachedSpanTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
    }

    public function testBothFactoriesCreateDetachedSpansAndRemainProfilingFactories(): void
    {
        self::assertInstanceOf(ProfilingFactoryInterface::class, $this->factory());
        self::assertInstanceOf(DetachedSpanFactoryInterface::class, $this->factory());
        self::assertInstanceOf(DetachedSpanFactoryInterface::class, new NullProfilingFactory());
    }

    public function testADetachedSpanHangsUnderTheOpenSpanWithoutBecomingAParent(): void
    {
        $factory = $this->factory();
        $outer = $factory->createSpan('outer');

        $detached = $factory->createDetachedSpan('http GET example.com', ['url_path' => '/a']);
        $inner = $factory->createSpan('inner');

        self::assertSame($outer, $detached->getParentSpan());
        self::assertSame($outer, $inner->getParentSpan());
        self::assertSame(['url_path' => '/a', 'profiling_detached' => true], $detached->getContext());
        self::assertTrue($detached->isRecorded());
    }

    public function testWithoutAnOpenSpanADetachedSpanIsARoot(): void
    {
        $factory = $this->factory();
        $factory->createSpan('closed')->end();

        self::assertNull($factory->createDetachedSpan('http GET example.com')->getParentSpan());
    }

    public function testTheMarkerCannotBeOverriddenByTheCallerContext(): void
    {
        $span = $this->factory()->createDetachedSpan('x', ['profiling_detached' => false]);

        self::assertSame(DetachedSpanFactoryInterface::DETACHED, $span->getContext());
    }

    public function testEndingTheParentLeavesTheDetachedSpanOpenUntilItEnds(): void
    {
        $factory = $this->factory();
        $outer = $factory->createSpan('outer');
        $detached = $factory->createDetachedSpan('detached');
        $factory->createSpan('inner');

        $outer->end();

        self::assertFalse($detached->isEnded());
        self::assertSame(['inner', 'outer'], $this->recorder->messages());

        $detached->end(['status' => 200]);

        self::assertSame(['inner', 'outer', 'detached'], $this->recorder->messages());
        self::assertSame(['status' => 200], $this->recorder->ended[2][1]);
        self::assertNull($factory->createSpan('next')->getParentSpan());
    }

    public function testEndingADetachedSpanLeavesTheStackAlone(): void
    {
        $factory = $this->factory();
        $outer = $factory->createSpan('outer');
        $detached = $factory->createDetachedSpan('detached');
        $inner = $factory->createSpan('inner');

        $detached->end();

        self::assertFalse($outer->isEnded());
        self::assertFalse($inner->isEnded());
        self::assertSame($inner, $factory->createSpan('innermost')->getParentSpan());
    }

    public function testDecisionMakersApplyToDetachedSpans(): void
    {
        $factory = $this->factory(new ListBasedDecisionMaker(null, ['http ']));

        $span = $factory->createDetachedSpan('http GET example.com');
        $span->end();

        self::assertFalse($span->isRecorded());
        self::assertSame([], $this->recorder->messages());
    }

    public function testEndAllEndsOpenDetachedSpansOnce(): void
    {
        $factory = $this->factory();
        $factory->createSpan('outer');

        $first = $factory->createDetachedSpan('first');
        $factory->createDetachedSpan('second');
        $first->end();

        $factory->endAll();
        $factory->endAll();

        self::assertSame(['first', 'second', 'outer'], $this->recorder->messages());
        self::assertSame(ProfilingFactoryInterface::IMPLICIT_END, $this->recorder->ended[1][1]);
    }

    public function testResetEndsDetachedSpansEvenBelowASpanKeptOpen(): void
    {
        $factory = $this->factory();
        $worker = $factory->createSpan('worker');
        $factory->keepOpenOnReset($worker);
        $detached = $factory->createDetachedSpan('detached');

        $factory->reset();

        self::assertTrue($detached->isEnded());
        self::assertFalse($worker->isEnded());
        self::assertSame(['detached'], $this->recorder->messages());
        self::assertSame(ProfilingFactoryInterface::IMPLICIT_END, $this->recorder->ended[0][1]);
    }

    public function testADetachedSpanWithoutTheFactoryHandlerIsForgottenByEndAll(): void
    {
        $logs = new TestHandler();
        $factory = new ProfilingFactory([new SpanAssembler([])], [], [$this->recorder], new Logger('test', [$logs]));
        $detached = $factory->createDetachedSpan('detached');
        $detached->removeEndHandler(0);

        $factory->endAll();
        $factory->endAll();

        self::assertTrue($detached->isEnded());
        self::assertSame([], $this->recorder->messages());
        self::assertSame([], $logs->getRecords());
    }

    public function testAFailingHandlerOfADetachedSpanDoesNotStopEndAll(): void
    {
        $factory = $this->factory();
        $factory->createDetachedSpan('failing')->addEndHandler(static function (): never {
            throw new RuntimeException('handler bug');
        });
        $factory->createDetachedSpan('next');

        $factory->endAll();

        self::assertSame(['failing', 'next'], $this->recorder->messages());
    }

    public function testCreateProcessorsWrapDetachedSpans(): void
    {
        $factory = new ProfilingFactory(
            [new SpanAssembler([])],
            [new class implements CreateSpanProcessorInterface {
                #[Override]
                public function process(SpanInterface $span): SpanInterface
                {
                    return new DecoratingSpan($span);
                }
            }],
            [$this->recorder],
            new NullLogger(),
        );
        $outer = $factory->createSpan('outer');

        $detached = $factory->createDetachedSpan('detached');

        self::assertInstanceOf(DecoratingSpan::class, $detached);
        self::assertSame($outer, $detached->getParentSpan());

        $detached->end();
        $factory->endAll();

        self::assertSame(['decorated detached', 'decorated outer'], $this->recorder->messages());
    }

    public function testAFailingAssemblerStillGivesAMarkedDetachedSpan(): void
    {
        $factory = new ProfilingFactory(
            [new class implements SpanAssemblerInterface {
                #[Override]
                public function assemble(string $message, array $context): ?SpanInterface
                {
                    throw new RuntimeException('assembler bug');
                }
            }],
            [],
            [$this->recorder],
            new NullLogger(),
        );

        $span = $factory->createDetachedSpan('detached', ['a' => 1]);

        self::assertInstanceOf(NullSpan::class, $span);
        self::assertSame(['a' => 1, 'profiling_detached' => true], $span->getContext());
    }

    public function testADetachedSpanEndedByAnEndProcessorIsProcessedAfterTheCurrentSpan(): void
    {
        $factory = $this->factory();
        $detached = $factory->createDetachedSpan('detached');
        $this->recorder->onProcess = static function (SpanInterface $span) use ($detached): void {
            if ($span->getMessage() === 'outer') {
                $detached->end();
            }
        };

        $factory->createSpan('outer')->end();

        self::assertSame(['outer', 'detached'], $this->recorder->messages());
    }

    public function testADetachedSpanOpenedByAnEndProcessorDuringEndAllIsEndedToo(): void
    {
        $factory = $this->factory();
        $factory->createDetachedSpan('first');

        $this->recorder->onProcess = static function (SpanInterface $span) use ($factory): void {
            if ($span->getMessage() === 'first') {
                $factory->createDetachedSpan('opened late');
            }
        };

        $factory->endAll();

        self::assertSame(['first', 'opened late'], $this->recorder->messages());
    }

    public function testTheFactoryHandlerOfADetachedSpanProcessesItOnlyOnce(): void
    {
        $span = $this->factory()->createDetachedSpan('detached');
        $handler = $span->getEndHandlers()[0];

        $span->end();
        $handler($span, ['again' => true]);

        self::assertSame([['detached', []]], $this->recorder->ended);
    }

    public function testTheNullFactoryGivesAMarkedNullSpan(): void
    {
        $span = (new NullProfilingFactory())->createDetachedSpan('detached', ['a' => 1, 'profiling_detached' => false]);

        self::assertInstanceOf(NullSpan::class, $span);
        self::assertSame(['a' => 1, 'profiling_detached' => true], $span->getContext());
    }

    public function testADetachedSpanOpenedWhileEndAllEndsTheStackIsEndedToo(): void
    {
        $factory = $this->factory();
        $factory->createSpan('outer');

        $late = null;
        $this->recorder->onProcess = static function (SpanInterface $span) use ($factory, &$late): void {
            if ($span->getMessage() === 'outer') {
                $late = $factory->createDetachedSpan('late');
            }
        };

        $factory->endAll();

        self::assertInstanceOf(SpanInterface::class, $late);
        self::assertTrue($late->isEnded());
        self::assertSame([['outer', ProfilingFactoryInterface::IMPLICIT_END], ['late', ProfilingFactoryInterface::IMPLICIT_END]], $this->recorder->ended);
    }

    public function testADetachedSpanOpenedWhileResetEndsTheStackIsEndedButTheKeptSpanStaysOpen(): void
    {
        $factory = $this->factory();
        $worker = $factory->createSpan('worker');
        $factory->keepOpenOnReset($worker);
        $factory->createSpan('job');

        $this->recorder->onProcess = static function (SpanInterface $span) use ($factory): void {
            if ($span->getMessage() === 'job') {
                $factory->createDetachedSpan('late');
            }
        };

        $factory->reset();

        self::assertSame(['job', 'late'], $this->recorder->messages());
        self::assertFalse($worker->isEnded());
    }

    public function testEndingSpansStopsWhenProcessorsKeepOpeningDetachedSpans(): void
    {
        $logs = new TestHandler();
        $factory = new ProfilingFactory([new SpanAssembler([])], [], [$this->recorder], new Logger('test', [$logs]));
        $factory->createDetachedSpan('detached');

        $this->recorder->onProcess = static function () use ($factory): void {
            $factory->createDetachedSpan('detached');
        };

        $factory->endAll();

        self::assertCount(ProfilingFactory::MAX_END_PASSES, $this->recorder->messages());
        $records = $logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('Profiling stopped ending spans after {passes} passes: end processors keep opening spans.', $records[0]->message);
        self::assertSame(['passes' => ProfilingFactory::MAX_END_PASSES], $records[0]->context);
    }

    public function testEndProcessorsRunOnlyOnceTheEndedParentAndAllItsChildrenAreSettled(): void
    {
        $factory = $this->factory();
        $detached = $factory->createDetachedSpan('detached');
        $outer = $factory->createSpan('outer');
        $factory->createSpan('middle');
        $factory->createSpan('inner')->addEndHandler(static function () use ($detached): void {
            $detached->end();
        });
        $endedWhenProcessed = [];
        $this->recorder->onProcess = static function (SpanInterface $span) use (&$endedWhenProcessed): void {
            $endedWhenProcessed[$span->getMessage()] = $span->isEnded();
        };

        $outer->end();

        self::assertSame(['inner', 'middle', 'outer', 'detached'], $this->recorder->messages());
        self::assertSame(['inner' => true, 'middle' => true, 'outer' => true, 'detached' => true], $endedWhenProcessed);
    }

    public function testEndingADetachedSpanAgainAfterEndAllChangesNothing(): void
    {
        $factory = $this->factory();
        $detached = $factory->createDetachedSpan('detached');

        $factory->endAll();
        $detached->end(['late' => true]);
        $factory->endSpan($detached, ['later' => true]);

        self::assertSame([['detached', ProfilingFactoryInterface::IMPLICIT_END]], $this->recorder->ended);
    }

    private function factory(?ListBasedDecisionMaker $decisionMaker = null): ProfilingFactory
    {
        return new ProfilingFactory(
            [new SpanAssembler($decisionMaker instanceof ListBasedDecisionMaker ? [$decisionMaker] : [])],
            [],
            [$this->recorder],
            new NullLogger(),
        );
    }
}
