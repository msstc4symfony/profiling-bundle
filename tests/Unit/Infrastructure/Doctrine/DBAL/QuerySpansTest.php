<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure\Doctrine\DBAL;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\QuerySpans;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingProfiling;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabeller;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabelling;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(QuerySpans::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
#[RequiresMethod(SqlLabeller::class, 'label')]
final class QuerySpansTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    private ProfilingFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->factory = RecordingProfiling::factory($this->recorder);
    }

    public function testAQuerySpanIsNamedAfterTheStatementTypeAndTable(): void
    {
        $result = $this->spans()->profile('SELECT id FROM "orders" WHERE id = ?', static fn (): int => 3);

        self::assertSame(3, $result);
        self::assertSame([['sql select orders', []]], $this->recorder->ended);
    }

    public function testAStatementWithoutATableIsNamedUnknown(): void
    {
        $this->spans()->profile('CREATE TABLE users (id INTEGER)', static fn (): int => 0);

        self::assertSame(['sql other unknown'], $this->recorder->messages());
    }

    public function testTheSpanCarriesTheConnectionNameAndNestsUnderTheOpenSpan(): void
    {
        $outer = $this->factory->createSpan('outer');
        $nested = null;

        $this->spans()->profile('DELETE FROM users', function () use (&$nested): int {
            $nested = $this->factory->createSpan('inside the query');
            $nested->end();

            return 1;
        });

        self::assertSame(['inside the query', 'sql delete users'], $this->recorder->messages());
        self::assertNotNull($nested);
        self::assertSame($outer, $nested->getParentSpan()?->getParentSpan());
        self::assertSame(['connection' => 'replica', 'profiling_automatic' => true], $nested->getParentSpan()->getContext());
        self::assertFalse($outer->isEnded());
    }

    public function testAFailingQueryEndsTheSpanWithTheExceptionClassAndRethrowsIt(): void
    {
        $failure = new RuntimeException('no such table');

        $caught = null;

        try {
            $this->spans()->profile('UPDATE users SET a = 1', static fn (): never => throw $failure);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught);

        self::assertSame([['sql update users', ['exception' => RuntimeException::class]]], $this->recorder->ended);
    }

    public function testQueriesOfAnEndProcessorAreNotRecordedSoTheyCannotFeedThemselves(): void
    {
        $queries = 0;
        $this->recorder->onProcess = function () use (&$queries): void {
            $queries++;
            $this->spans()->profile('INSERT INTO span_log (message) VALUES (?)', static fn (): int => 1);
        };

        $this->factory->createSpan('outer')->end();

        self::assertSame(1, $queries);
        self::assertSame(['outer'], $this->recorder->messages());

        $this->recorder->onProcess = null;
        $this->spans()->profile('SELECT 1 FROM users', static fn (): int => 1);

        self::assertSame(['outer', 'sql select users'], $this->recorder->messages());
    }

    public function testAFailingLabellerRunsTheQueryWithoutASpan(): void
    {
        $labeller = self::createStub(SqlLabelling::class);
        $labeller->method('label')->willThrowException(new RuntimeException('labeller bug'));

        self::assertSame(7, (new QuerySpans($this->factory, 'default', $labeller))->profile('SELECT 1', static fn (): int => 7));
        self::assertSame([], $this->recorder->messages());
    }

    public function testAFactoryFailingToCreateTheSpanRunsTheQueryWithoutASpan(): void
    {
        $factory = self::createStub(ProfilingFactoryInterface::class);
        $factory->method('createSpan')->willThrowException(new RuntimeException('decorator bug'));

        self::assertSame(7, (new QuerySpans($factory, 'default', new SqlLabeller()))->profile('SELECT 1', static fn (): int => 7));
    }

    public function testAFactoryFailingToEndTheSpanKeepsTheQueryResult(): void
    {
        $factory = self::createStub(ProfilingFactoryInterface::class);
        $factory->method('createSpan')->willReturn(new NullSpan('sql'));
        $factory->method('endSpan')->willThrowException(new RuntimeException('decorator bug'));

        self::assertSame(7, (new QuerySpans($factory, 'default', new SqlLabeller()))->profile('SELECT 1', static fn (): int => 7));
    }

    public function testAFactoryFailingToEndTheSpanKeepsTheQueryException(): void
    {
        $factory = self::createStub(ProfilingFactoryInterface::class);
        $factory->method('createSpan')->willReturn(new NullSpan('sql'));
        $factory->method('endSpan')->willThrowException(new RuntimeException('decorator bug'));
        $failure = new LogicException('no such table');

        $this->expectExceptionObject($failure);

        (new QuerySpans($factory, 'default', new SqlLabeller()))->profile('SELECT 1', static fn (): never => throw $failure);
    }

    private function spans(): QuerySpans
    {
        return new QuerySpans($this->factory, 'replica', new SqlLabeller());
    }
}
