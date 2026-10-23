<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Application;

use Closure;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\DecisionMaker\AllowSpan\AllowSpanDecisionMakerInterface;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spans of the library integrations (DetachedSpanFactoryInterface::AUTOMATIC) opened by profiling's own extension
 * points are not recorded: otherwise a log handler or decision maker writing through DBAL/HTTP would feed itself.
 */
#[CoversClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
final class AutomaticSpanGuardTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    private ProfilingFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->factory = new ProfilingFactory([new SpanAssembler([])], [], [$this->recorder], new NullLogger());
    }

    /**
     * @return iterable<string, array{bool, array<string, mixed>, bool}>
     */
    public static function spansOpenedByAnEndProcessor(): iterable
    {
        yield 'automatic detached span' => [true, DetachedSpanFactoryInterface::AUTOMATIC, false];
        yield 'automatic stacked span' => [false, DetachedSpanFactoryInterface::AUTOMATIC, false];
        yield 'automatic among other context' => [false, ['connection' => 'default', ...DetachedSpanFactoryInterface::AUTOMATIC], false];
        yield 'marker set to false' => [false, ['profiling_automatic' => false], true];
        yield 'manual span' => [false, [], true];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('spansOpenedByAnEndProcessor')]
    public function testSpansOpenedByAnEndProcessorAreRecordedUnlessAutomatic(bool $detached, array $context, bool $recorded): void
    {
        $opened = null;
        $this->recorder->onProcess = function (SpanInterface $span) use ($detached, $context, &$opened): void {
            if ($span->getMessage() === 'outer') {
                $opened = $detached ? $this->factory->createDetachedSpan('opened', $context) : $this->factory->createSpan('opened', $context);
                $opened->end();
            }
        };

        $this->factory->createSpan('outer')->end();

        self::assertInstanceOf(SpanInterface::class, $opened);
        self::assertSame($recorded, $opened->isRecorded());
        self::assertSame($recorded ? ['outer', 'opened'] : ['outer'], $this->recorder->messages());
    }

    public function testAnAutomaticSpanOpenedBeforeProcessingAndEndedDuringItIsRecorded(): void
    {
        $query = $this->factory->createDetachedSpan('sql select users', DetachedSpanFactoryInterface::AUTOMATIC);
        $this->recorder->onProcess = static function (SpanInterface $span) use ($query): void {
            if ($span->getMessage() === 'outer') {
                $query->end();
            }
        };

        $this->factory->createSpan('outer')->end();

        self::assertSame(['outer', 'sql select users'], $this->recorder->messages());
    }

    public function testAnAutomaticSpanOpenedWhileALaterQueuedSpanIsProcessedIsNotRecorded(): void
    {
        $outer = $this->factory->createSpan('outer');
        $this->factory->createSpan('inner');
        $this->recorder->onProcess = function (SpanInterface $span): void {
            if ($span->getMessage() === 'outer') {
                $this->factory->createSpan('sql insert logs', DetachedSpanFactoryInterface::AUTOMATIC)->end();
            }
        };

        $outer->end();

        self::assertSame(['inner', 'outer'], $this->recorder->messages());
    }

    public function testAnAutomaticSpanLeftOpenDuringProcessingStillParentsTheNextSpan(): void
    {
        $leftOpen = null;
        $this->recorder->onProcess = function (SpanInterface $span) use (&$leftOpen): void {
            if ($span->getMessage() === 'outer') {
                $leftOpen = $this->factory->createSpan('sql select', DetachedSpanFactoryInterface::AUTOMATIC);
            }
        };

        $this->factory->createSpan('outer')->end();
        $next = $this->factory->createSpan('next');

        self::assertInstanceOf(NullSpan::class, $leftOpen);
        self::assertSame($leftOpen, $next->getParentSpan());
    }

    public function testAnAutomaticSpanOpenedByADecisionMakerIsNotRecordedAndDoesNotRecurse(): void
    {
        $decisionMaker = new class implements AllowSpanDecisionMakerInterface {
            /** @var (Closure(): void)|null */
            public ?Closure $query = null;

            public int $asked = 0;

            #[Override]
            public function isAllowed(string $message): ?bool
            {
                $this->asked++;
                if ($this->query instanceof Closure) {
                    ($this->query)();
                }

                return null;
            }
        };
        $factory = new ProfilingFactory([new SpanAssembler([$decisionMaker])], [], [$this->recorder], new NullLogger());
        $decisionMaker->query = static function () use ($factory): void {
            $factory->createSpan('sql select rules', DetachedSpanFactoryInterface::AUTOMATIC)->end();
        };

        $factory->createSpan('request')->end();

        self::assertSame(1, $decisionMaker->asked);
        self::assertSame(['request'], $this->recorder->messages());
    }

    public function testAnAutomaticSpanOpenedByACreateProcessorIsNotRecorded(): void
    {
        $processor = new class implements CreateSpanProcessorInterface {
            /** @var (Closure(): void)|null */
            public ?Closure $query = null;

            #[Override]
            public function process(SpanInterface $span): SpanInterface
            {
                if ($this->query instanceof Closure) {
                    ($this->query)();
                }

                return $span;
            }
        };
        $factory = new ProfilingFactory([new SpanAssembler([])], [$processor], [$this->recorder], new NullLogger());
        $processor->query = static function () use ($factory): void {
            $factory->createDetachedSpan('http GET config', DetachedSpanFactoryInterface::AUTOMATIC)->end();
        };

        $factory->createSpan('request')->end();

        self::assertSame(['request'], $this->recorder->messages());
    }
}
