<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\Connection;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\Driver;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\QuerySpans;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\QuerySpansMiddleware;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL\Statement;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingProfiling;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingStatement;
use Msstc4Symfony\SqlLabeller\Domain\Enum\StatementType;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabeller;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabelling;
use Msstc4Symfony\SqlLabeller\Domain\ValueObject\SqlLabels;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(QuerySpansMiddleware::class)]
#[CoversClass(Driver::class)]
#[CoversClass(Connection::class)]
#[CoversClass(Statement::class)]
#[UsesClass(QuerySpans::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[RequiresMethod(Middleware::class, 'wrap')]
#[RequiresMethod(SqlLabeller::class, 'label')]
final class QuerySpansMiddlewareTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
    }

    public function testQueriesWithoutParametersGetASpanWithTheConnectionName(): void
    {
        $result = self::createStub(Result::class);
        $inner = self::createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn($result);

        self::assertSame($result, $this->connect($inner)->query('SELECT * FROM users'));
        self::assertSame([['sql select users', []]], $this->recorder->ended);
    }

    public function testExecReturnsTheAffectedRows(): void
    {
        $inner = self::createStub(ConnectionInterface::class);
        $inner->method('exec')->willReturn(2);

        self::assertSame(2, $this->connect($inner)->exec('DELETE FROM users'));
        self::assertSame(['sql delete users'], $this->recorder->messages());
    }

    /**
     * DBAL 4 declares int|string, a string beyond PHP_INT_MAX rows; DBAL 3 only int.
     */
    public function testExecTurnsADbal4StringRowCountIntoAnInteger(): void
    {
        if (!str_contains((string) (new ReflectionMethod(ConnectionInterface::class, 'exec'))->getReturnType(), 'string')) {
            self::markTestSkipped('DBAL 3 returns an int row count.');
        }

        $inner = self::createStub(ConnectionInterface::class);
        $inner->method('exec')->willReturn('3');

        self::assertSame(3, $this->connect($inner)->exec('DELETE FROM users'));
    }

    public function testPreparingIsNotAQueryButExecutingThePreparedStatementIs(): void
    {
        $statement = new RecordingStatement(self::createStub(StatementInterface::class), self::createStub(Result::class));
        $inner = self::createStub(ConnectionInterface::class);
        $inner->method('prepare')->willReturn($statement);

        $prepared = $this->connect($inner)->prepare('INSERT INTO users (id) VALUES (?)');

        self::assertInstanceOf(Statement::class, $prepared);
        self::assertSame([], $this->recorder->messages());

        self::assertSame($statement->result, $prepared->execute());
        self::assertSame(['sql insert users'], $this->recorder->messages());
    }

    public function testExecuteOnDbal3ForwardsTheBoundValues(): void
    {
        if (!$this->isDbal3()) {
            self::markTestSkipped('DBAL 4 Statement::execute() takes no argument.');
        }

        $statement = $this->recordingStatement();

        self::assertSame($statement->result, $this->prepared($statement)->execute([1]));
        self::assertSame([[[1]]], $statement->arguments);
        self::assertSame(['sql select unknown'], $this->recorder->messages());
    }

    public function testExecuteWithoutArgumentsOnDbal4ForwardsNone(): void
    {
        if (self::isDbal3()) {
            self::markTestSkipped('DBAL 3 AbstractStatementMiddleware::execute() passes its own null default.');
        }

        $statement = $this->recordingStatement();

        self::assertSame($statement->result, $this->prepared($statement)->execute());
        self::assertSame([[]], $statement->arguments);
        self::assertSame(['sql select unknown'], $this->recorder->messages());
    }

    /**
     * The middleware forwards no argument; DBAL 3's AbstractStatementMiddleware::execute() then passes its default.
     */
    public function testExecuteWithoutArgumentsOnDbal3ForwardsOnlyTheDefaultOfDbal(): void
    {
        if (!self::isDbal3()) {
            self::markTestSkipped('DBAL 4 Statement::execute() takes no argument.');
        }

        $statement = $this->recordingStatement();

        self::assertSame($statement->result, $this->prepared($statement)->execute());
        self::assertSame([[null]], $statement->arguments);
    }

    /**
     * DBAL 4's AbstractStatementMiddleware::execute() forwards no argument, whatever this middleware receives.
     */
    public function testExecuteOnDbal4AcceptsTheBoundValuesOfDbal3Callers(): void
    {
        if ($this->isDbal3()) {
            self::markTestSkipped('DBAL 3 forwards the bound values.');
        }

        $statement = $this->recordingStatement();

        self::assertSame($statement->result, $this->prepared($statement)->execute([1]));
        self::assertSame([[]], $statement->arguments);
    }

    public function testAGivenLabellerNamesTheSpans(): void
    {
        $inner = self::createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(self::createStub(Result::class));
        $labeller = self::createStub(SqlLabelling::class);
        $labeller->method('label')->willReturn(new SqlLabels(StatementType::DELETE, 'audit'));
        $driver = self::createStub(DriverInterface::class);
        $driver->method('connect')->willReturn($inner);

        (new QuerySpansMiddleware(RecordingProfiling::factory($this->recorder), 'default', $labeller))->wrap($driver)->connect([])->query('SELECT 1');

        self::assertSame(['sql delete audit'], $this->recorder->messages());
    }

    public function testTheConnectionNameReachesTheSpanContext(): void
    {
        $inner = self::createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(self::createStub(Result::class));

        $this->connect($inner, 'replica')->query('SELECT 1');

        self::assertSame(['connection' => 'replica', 'profiling_automatic' => true], $this->recorder->spans[0]->getContext());
    }

    private function isDbal3(): bool
    {
        return (new ReflectionMethod(StatementInterface::class, 'execute'))->getNumberOfParameters() > 0;
    }

    private function recordingStatement(): RecordingStatement
    {
        return new RecordingStatement(self::createStub(StatementInterface::class), self::createStub(Result::class));
    }

    private function prepared(RecordingStatement $statement): Statement
    {
        return new Statement($statement, new QuerySpans(RecordingProfiling::factory($this->recorder), 'default', new SqlLabeller()), 'SELECT 1');
    }

    private function connect(ConnectionInterface $inner, string $name = 'default'): ConnectionInterface
    {
        $driver = self::createStub(DriverInterface::class);
        $driver->method('connect')->willReturn($inner);

        $wrapped = (new QuerySpansMiddleware(RecordingProfiling::factory($this->recorder), $name))->wrap($driver);
        self::assertInstanceOf(Driver::class, $wrapped);

        $connection = $wrapped->connect([]);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
