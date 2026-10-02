<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\DecoratingSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

#[CoversClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(ListBasedDecisionMaker::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
final class ProfilingFactoryTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
    }

    public function testNestsSpansUnderTheOpenOne(): void
    {
        $factory = $this->factory();

        $outer = $factory->createSpan('outer');
        $inner = $factory->createSpan('inner');

        self::assertNull($outer->getParentSpan());
        self::assertSame($outer, $inner->getParentSpan());
    }

    public function testEndingAParentEndsOpenChildrenInnermostFirst(): void
    {
        $factory = $this->factory();
        $outer = $factory->createSpan('outer');
        $factory->createSpan('middle');
        $factory->createSpan('inner');

        $outer->end(['status' => 'ok']);

        self::assertSame(['inner', 'middle', 'outer'], $this->recorder->messages());
        self::assertSame(['status' => 'ok'], $this->recorder->ended[2][1]);
    }

    public function testEachSpanIsProcessedOnce(): void
    {
        $factory = $this->factory();
        $span = $factory->createSpan('once');

        $span->end();
        $span->end();

        $factory->endAll();

        self::assertSame(['once'], $this->recorder->messages());
    }

    public function testEndAllClosesEverythingInnermostFirst(): void
    {
        $factory = $this->factory();
        $factory->createSpan('a');
        $factory->createSpan('b');

        $factory->endAll();

        self::assertSame(['b', 'a'], $this->recorder->messages());
        self::assertNull($factory->createSpan('c')->getParentSpan());
    }

    public function testResetClosesOpenSpans(): void
    {
        $factory = $this->factory();
        $factory->createSpan('left-open');

        $factory->reset();

        self::assertSame(['left-open'], $this->recorder->messages());
    }

    public function testResetKeepsASpanMarkedToOutliveResetsAndEndsEverythingAboveIt(): void
    {
        $factory = $this->factory();
        $worker = $factory->createSpan('worker');
        $factory->keepOpenOnReset($worker);
        $factory->createSpan('job');

        $factory->reset();
        $factory->reset();

        self::assertFalse($worker->isEnded());
        self::assertSame(['job'], $this->recorder->messages());
        self::assertSame(ProfilingFactoryInterface::IMPLICIT_END, $this->recorder->ended[0][1]);
        self::assertSame($worker, $factory->createSpan('next job')->getParentSpan());

        $worker->end();

        self::assertSame(['job', 'next job', 'worker'], $this->recorder->messages());
        self::assertSame([], $this->recorder->ended[2][1]);
    }

    public function testResetKeepsTheAncestorsOfASpanMarkedToOutliveResets(): void
    {
        $factory = $this->factory();
        $root = $factory->createSpan('root');
        $factory->keepOpenOnReset($factory->createSpan('worker'));

        $factory->reset();

        self::assertFalse($root->isEnded());
        self::assertSame([], $this->recorder->messages());
    }

    public function testEndAllStillEndsASpanMarkedToOutliveResets(): void
    {
        $factory = $this->factory();
        $worker = $factory->createSpan('worker');
        $factory->keepOpenOnReset($worker);

        $factory->endAll();

        self::assertTrue($worker->isEnded());
        self::assertSame(['worker'], $this->recorder->messages());
    }

    public function testFilteredOutSpansAreNotProcessedButKeepNesting(): void
    {
        $factory = $this->factory(new ListBasedDecisionMaker(spansBlacklist: ['noisy']));
        $kept = $factory->createSpan('kept');
        $noisy = $factory->createSpan('noisy query');
        $factory->createSpan('inside noisy');

        $kept->end();

        self::assertInstanceOf(NullSpan::class, $noisy);
        self::assertSame(['inside noisy', 'kept'], $this->recorder->messages());
    }

    public function testWithoutAssemblersSpansAreNotRecorded(): void
    {
        $factory = new ProfilingFactory(endSpanProcessors: [$this->recorder]);

        $factory->createSpan('unassembled')->end();

        self::assertSame([], $this->recorder->ended);
    }

    public function testCreateProcessorsCanWrapTheSpan(): void
    {
        $processor = new class implements CreateSpanProcessorInterface {
            #[Override]
            public function process(SpanInterface $span): SpanInterface
            {
                return new DecoratingSpan($span);
            }
        };
        $factory = new ProfilingFactory([new SpanAssembler()], [$processor], [$this->recorder]);

        $outer = $factory->createSpan('outer');
        $inner = $factory->createSpan('inner');
        self::assertSame($outer, $inner->getParentSpan());
        $outer->end();

        self::assertSame(['decorated inner', 'decorated outer'], $this->recorder->messages());
    }

    public function testAProcessorEndingAnAncestorKeepsOrderAndProcessesEachSpanOnce(): void
    {
        $second = new RecordingEndProcessor();
        $factory = new ProfilingFactory([new SpanAssembler()], [], [$this->recorder, $second]);
        $a = $factory->createSpan('a');
        $b = $factory->createSpan('b');
        $factory->createSpan('c');
        $this->recorder->onProcess = static function (SpanInterface $span) use ($a): void {
            if ($span->getMessage() === 'c') {
                $a->end();
            }
        };

        $b->end();

        self::assertSame(['c', 'b', 'a'], $this->recorder->messages());
        self::assertSame(['c', 'b', 'a'], $second->messages());
    }

    public function testAThrowingHandlerOnAnImplicitlyEndedChildIsLoggedAndEverythingIsProcessed(): void
    {
        $log = new TestHandler();
        $factory = new ProfilingFactory([new SpanAssembler()], [], [$this->recorder], new Logger('app', [$log]));
        $a = $factory->createSpan('a');
        $factory->createSpan('b');
        $factory->createSpan('c')->addEndHandler(static function (): never {
            throw new RuntimeException('handler bug');
        });

        $a->end();
        $factory->endAll();

        self::assertSame(['c', 'b', 'a'], $this->recorder->messages());
        self::assertCount(1, $log->getRecords());
        self::assertNull($factory->createSpan('next')->getParentSpan());
    }

    public function testEndAllLogsHandlerFailuresAndClosesEverything(): void
    {
        $throwingHandler = new class implements CreateSpanProcessorInterface {
            #[Override]
            public function process(SpanInterface $span): SpanInterface
            {
                // Registered before the factory's own handler, which must still run.
                return $span->getMessage() === 'inner' ? $span->addEndHandler(static function (): never {
                    throw new RuntimeException('processor handler');
                }) : $span;
            }
        };
        $log = new TestHandler();
        $factory = new ProfilingFactory([new SpanAssembler()], [$throwingHandler], [$this->recorder], new Logger('app', [$log]));
        $factory->createSpan('outer');
        $factory->createSpan('inner');

        $factory->endAll();

        self::assertSame(['inner', 'outer'], $this->recorder->messages());
        self::assertCount(1, $log->getRecords());
        self::assertNull($factory->createSpan('next')->getParentSpan());
    }

    public function testEndSpanNeverThrows(): void
    {
        $factory = $this->factory();
        $span = $factory->createSpan('x')->addEndHandler(static function (): never {
            throw new RuntimeException('handler bug');
        });

        $factory->endSpan($span, ['k' => 'v']);

        self::assertSame([['x', ['k' => 'v']]], $this->recorder->ended);
    }

    public function testABrokenLoggerDoesNotBreakTheProfiledCode(): void
    {
        $failing = new class implements EndSpanProcessorInterface {
            #[Override]
            public function process(SpanInterface $span, array $context): void
            {
                throw new RuntimeException('processor bug');
            }
        };
        $brokenLogger = new class extends AbstractLogger {
            #[Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };
        $factory = new ProfilingFactory([new SpanAssembler()], [], [$failing, $this->recorder], $brokenLogger);

        $factory->createSpan('x')->end();

        self::assertSame(['x'], $this->recorder->messages());
    }

    public function testImplicitlyEndedChildrenAreMarked(): void
    {
        $wrapping = new class implements CreateSpanProcessorInterface {
            #[Override]
            public function process(SpanInterface $span): SpanInterface
            {
                return $span->getMessage() === 'wrapped' ? new DecoratingSpan($span) : $span;
            }
        };
        $factory = new ProfilingFactory([new SpanAssembler()], [$wrapping], [$this->recorder]);
        $seen = [];
        $remember = static function (SpanInterface $span, array $context) use (&$seen): void {
            $seen[$span->getMessage()] = $context;
        };
        $parent = $factory->createSpan('parent');
        $factory->createSpan('child')->addEndHandler($remember);
        $factory->createSpan('wrapped')->addEndHandler($remember);

        $parent->end(['own' => true]);

        self::assertSame(['wrapped' => ProfilingFactoryInterface::IMPLICIT_END, 'child' => ProfilingFactoryInterface::IMPLICIT_END], $seen);
        $this->recorder->ended = [];
        $factory->createSpan('leftover');
        $factory->endAll();
        self::assertSame([['leftover', ProfilingFactoryInterface::IMPLICIT_END]], $this->recorder->ended);
    }

    public function testImplicitlyEndedChildIsProcessedWithTheMarker(): void
    {
        $factory = $this->factory();
        $parent = $factory->createSpan('parent');
        $factory->createSpan('child');

        $parent->end(['own' => true]);

        self::assertSame([['child', ProfilingFactoryInterface::IMPLICIT_END], ['parent', ['own' => true]]], $this->recorder->ended);
    }

    public function testAThrowingHandlerOnTheEndedSpanReachesItsCallerAfterProcessing(): void
    {
        $span = $this->factory()->createSpan('own');
        $span->addEndHandler(static function (): never {
            throw new RuntimeException('handler bug');
        });

        try {
            $span->end();
            self::fail('The application handler exception must reach the caller.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler bug', $exception->getMessage());
        }

        self::assertSame(['own'], $this->recorder->messages());
    }

    public function testChildrenEndAtTheirParentsEndTime(): void
    {
        $factory = $this->factory();
        $parent = $factory->createSpan('parent');
        $child = $factory->createSpan('child');
        $factory->createSpan('grandchild')->addEndHandler(static function (): void {
            usleep(20_000);
        });

        $parent->end();

        self::assertLessThanOrEqual($parent->getDuration(), $child->getDuration());
    }

    public function testADetachedChildEndedEarlierIsNotProcessed(): void
    {
        $factory = $this->factory();
        $a = $factory->createSpan('a');
        $detached = $factory->createSpan('detached');
        $factory->createSpan('c');
        $detached->removeEndHandler(0);
        $detached->end(['ctx' => 'lost']);

        $a->end();

        self::assertSame(['c', 'a'], $this->recorder->messages());
    }

    public function testFailuresWhileCreatingASpanAreLoggedAndASpanIsStillReturned(): void
    {
        $throwingAssembler = new class implements SpanAssemblerInterface {
            #[Override]
            public function assemble(string $message, array $context): ?SpanInterface
            {
                throw new RuntimeException('assembler bug');
            }
        };
        $throwingProcessor = new class implements CreateSpanProcessorInterface {
            #[Override]
            public function process(SpanInterface $span): SpanInterface
            {
                throw new RuntimeException('processor bug');
            }
        };
        $log = new TestHandler();

        $unassembled = new ProfilingFactory([$throwingAssembler], [], [], new Logger('app', [$log]))->createSpan('x');
        $unprocessed = new ProfilingFactory([new SpanAssembler()], [$throwingProcessor], [], new Logger('app', [$log]))->createSpan('y');

        self::assertInstanceOf(NullSpan::class, $unassembled);
        self::assertInstanceOf(Span::class, $unprocessed);
        self::assertCount(2, $log->getRecords());
    }

    public function testAProcessorOpeningASpanDuringEndAllDoesNotLoseIt(): void
    {
        $factory = $this->factory();
        $factory->createSpan('x');

        $opened = false;
        $this->recorder->onProcess = static function () use ($factory, &$opened): void {
            if (!$opened) {
                $opened = true;
                $factory->createSpan('opened in processor');
            }
        };

        $factory->endAll();

        self::assertSame(['x', 'opened in processor'], $this->recorder->messages());
    }

    public function testAFailingProcessorIsLoggedAndDoesNotBreakTheCallerOrTheStack(): void
    {
        $failing = new class implements EndSpanProcessorInterface {
            #[Override]
            public function process(SpanInterface $span, array $context): void
            {
                throw new RuntimeException('storage down');
            }
        };
        $log = new TestHandler();
        $factory = new ProfilingFactory([new SpanAssembler()], [], [$failing, $this->recorder], new Logger('app', [$log]));
        $a = $factory->createSpan('a');
        $factory->createSpan('b');

        $a->end();
        $next = $factory->createSpan('next');

        self::assertSame(['b', 'a'], $this->recorder->messages());
        self::assertNull($next->getParentSpan());
        self::assertCount(2, $log->getRecords());
        $exception = $log->getRecords()[0]->context['exception'] ?? null;
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('storage down', $exception->getMessage());
    }

    public function testASpanWithoutTheFactoryHandlerIsDroppedNotProcessed(): void
    {
        $factory = $this->factory();
        $detached = $factory->createSpan('detached');
        $detached->removeEndHandler(0);
        $detached->end();

        self::assertNull($factory->createSpan('next')->getParentSpan());

        $factory->createSpan('another detached')->removeEndHandler(0);
        $factory->endAll();

        self::assertSame(['next'], $this->recorder->messages());
    }

    public function testEndingASpanTwiceProcessesItOnce(): void
    {
        $span = $this->factory()->createSpan('once');

        $span->end();
        $span->end();

        self::assertSame(['once'], $this->recorder->messages());
    }

    private function factory(?ListBasedDecisionMaker $decisionMaker = null): ProfilingFactory
    {
        return new ProfilingFactory(
            [new SpanAssembler($decisionMaker instanceof ListBasedDecisionMaker ? [$decisionMaker] : [])],
            [],
            [$this->recorder],
        );
    }
}
