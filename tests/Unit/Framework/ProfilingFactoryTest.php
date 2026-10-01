<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullableSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(ListBasedDecisionMaker::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullableSpan::class)]
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

        self::assertInstanceOf(NullableSpan::class, $noisy);
        self::assertSame(['inside noisy', 'kept'], $this->recorder->messages());
    }

    public function testWithoutAssemblersSpansAreNotRecorded(): void
    {
        $factory = new ProfilingFactory(endSpanProcessors: [$this->recorder]);

        $factory->createSpan('unassembled')->end();

        self::assertSame([], $this->recorder->ended);
    }

    public function testCreateProcessorsCanReplaceTheSpan(): void
    {
        $replacement = new Span('replaced');
        $processor = new readonly class($replacement) implements CreateSpanProcessorInterface {
            public function __construct(private SpanInterface $replacement)
            {
            }

            #[Override]
            public function process(SpanInterface $span): SpanInterface
            {
                foreach ($span->getEndHandlers() as $handler) {
                    $this->replacement->addEndHandler($handler);
                }

                return $this->replacement;
            }
        };
        $factory = new ProfilingFactory([new SpanAssembler()], [$processor], [$this->recorder]);

        $factory->createSpan('original')->end();

        self::assertSame(['replaced'], $this->recorder->messages());
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
