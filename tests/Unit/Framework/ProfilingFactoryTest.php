<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
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
use RuntimeException;

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
        $factory = $this->factory();
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
