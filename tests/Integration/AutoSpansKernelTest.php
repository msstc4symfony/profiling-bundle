<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AutoSpans\AutoSpansKernel;
use Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AutoSpans\RecordingFactoryDecorator;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Real wiring of the auto spans: DoctrineBundle's MiddlewaresPass, FrameworkBundle's transport with its
 * mock_response_factory decorator (-10) inside the profiling one, a Guzzle client service. Each test skips itself
 * when its library is missing (the CI job without optional libraries).
 */
#[CoversNothing]
final class AutoSpansKernelTest extends TestCase
{
    private const MAX_HANDLER_UNWIND = 8;

    private ?AutoSpansKernel $kernel = null;

    /** @var (callable(Throwable): void)|null */
    private $exceptionHandler;

    #[Override]
    protected function setUp(): void
    {
        $this->exceptionHandler = $this->currentExceptionHandler();
        (new Filesystem())->remove(AutoSpansKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(AutoSpansKernel::cacheRoot());
        for ($i = 0; $i < self::MAX_HANDLER_UNWIND && $this->currentExceptionHandler() !== $this->exceptionHandler; $i++) {
            restore_exception_handler();
        }
    }

    public function testTheKernelBootsWithWhateverLibrariesAreInstalled(): void
    {
        self::assertInstanceOf(RecordingEndProcessor::class, $this->container('test')->get('test.recorder'));
    }

    #[TestWith(['test'])]
    #[TestWith([AutoSpansKernel::PROFILING_FIRST])]
    public function testDbalQueriesGetSpansWhateverTheBundleOrder(string $environment): void
    {
        if (!AutoSpansKernel::hasDoctrine()) {
            self::markTestSkipped('doctrine/doctrine-bundle or ext-pdo_sqlite is missing');
        }

        $connection = $this->container($environment)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement('CREATE TABLE users (id INTEGER)');
        $connection->executeStatement('INSERT INTO users (id) VALUES (?)', [1]);
        $connection->executeStatement('INSERT INTO users (id) VALUES (2)');
        $connection->executeQuery('SELECT id FROM users WHERE id = ?', [1])->fetchAllAssociative();
        $connection->executeQuery('SELECT id FROM users')->fetchAllAssociative();

        self::assertSame(
            ['sql other unknown', 'sql insert users', 'sql insert users', 'sql select users', 'sql select users'],
            $this->sqlMessages(),
        );
        self::assertSame([['connection' => 'default', 'profiling_automatic' => true]], $this->contextsOf('sql other unknown'));
    }

    public function testEachConnectionNamesItsSpansAndAFailingQueryEndsWithTheDriverException(): void
    {
        if (!AutoSpansKernel::hasDoctrine()) {
            self::markTestSkipped('doctrine/doctrine-bundle or ext-pdo_sqlite is missing');
        }

        $replica = $this->container('test')->get('doctrine.dbal.replica_connection');
        self::assertInstanceOf(Connection::class, $replica);

        try {
            $replica->executeQuery('SELECT id FROM missing_table');
            self::fail('Querying a missing table must fail.');
        } catch (DbalException) {
        }

        self::assertSame([['connection' => 'replica', 'profiling_automatic' => true]], $this->contextsOf('sql select missing_table'));
        $ended = array_values(array_filter($this->recorder()->ended, static fn (array $ended): bool => $ended[0] === 'sql select missing_table'));
        self::assertCount(1, $ended);
        $exception = $ended[0][1]['exception'] ?? null;
        self::assertIsString($exception);
        self::assertStringStartsWith('Doctrine\\DBAL\\Driver\\', $exception);
    }

    public function testTheQuerySpanIsOutsideMiddlewaresOfTheDefaultPriority(): void
    {
        if (!AutoSpansKernel::hasDoctrine()) {
            self::markTestSkipped('doctrine/doctrine-bundle or ext-pdo_sqlite is missing');
        }

        $replica = $this->container('test')->get('doctrine.dbal.replica_connection');
        self::assertInstanceOf(Connection::class, $replica);

        $replica->executeQuery('SELECT 1');

        $spans = $this->recorder()->spans;
        self::assertSame(['inside middleware', 'sql select unknown'], $this->recorder()->messages());
        self::assertSame($spans[1], $spans[0]->getParentSpan());
    }

    /**
     * Decorating the ProfilingFactory service covers every span; decorating the DetachedSpanFactoryInterface alias
     * covers the automatic ones, which all go through that alias.
     */
    #[TestWith([AutoSpansKernel::DECORATED_FACTORY])]
    #[TestWith([AutoSpansKernel::DECORATED_ALIAS])]
    public function testADecoratedFactoryReceivesTheAutomaticSpans(string $environment): void
    {
        if (!AutoSpansKernel::hasDoctrine() || !AutoSpansKernel::hasHttpClient() || !AutoSpansKernel::hasGuzzle()) {
            self::markTestSkipped('doctrine/doctrine-bundle, symfony/http-client or guzzlehttp/guzzle is missing');
        }

        $container = $this->container($environment);
        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeQuery('SELECT 1');
        $http = $container->get('test.http_client');
        self::assertInstanceOf(HttpClientInterface::class, $http);
        $http->request('GET', 'https://example.com')->getContent();
        $guzzle = $container->get('test.guzzle');
        self::assertInstanceOf(ClientInterface::class, $guzzle);
        $guzzle->request('GET', 'https://guzzle.example.com');

        $decorator = $container->get('test.factory_decorator');
        self::assertInstanceOf(RecordingFactoryDecorator::class, $decorator);
        self::assertSame(['sql select unknown', 'http GET example.com', 'http GET guzzle.example.com'], $decorator->created);
        self::assertSame(['sql select unknown', 'http GET example.com', 'http GET guzzle.example.com'], $this->recorder()->messages());
    }

    public function testEachRetryAttemptGetsItsOwnSpan(): void
    {
        if (!AutoSpansKernel::hasHttpClient()) {
            self::markTestSkipped('symfony/http-client is missing');
        }

        $client = $this->container('test')->get('test.retrying');
        self::assertInstanceOf(HttpClientInterface::class, $client);

        self::assertSame('pong', $client->request('GET', '/flaky')->getContent());

        self::assertSame(
            [['http GET retry.example.com', ['body_cancelled' => true, 'status' => 503]], ['http GET retry.example.com', ['status' => 202]]],
            $this->recorder()->ended,
        );
    }

    public function testHttpClientRequestsGetADetachedSpanThroughTheMockedTransport(): void
    {
        if (!AutoSpansKernel::hasHttpClient()) {
            self::markTestSkipped('symfony/http-client is missing');
        }

        $container = $this->container('test');
        $client = $container->get('test.http_client');
        self::assertInstanceOf(HttpClientInterface::class, $client);
        $scoped = $container->get('test.scoped');
        self::assertInstanceOf(HttpClientInterface::class, $scoped);

        self::assertSame('pong', $client->request('GET', 'https://example.com/ping?x=1')->getContent());
        $scoped->request('POST', '/orders')->getContent();

        self::assertSame(
            [['http GET example.com', ['status' => 202]], ['http POST scoped.example.com', ['status' => 202]]],
            $this->recorder()->ended,
        );
        self::assertSame(['url_path' => '/ping', 'profiling_automatic' => true, 'profiling_detached' => true], $this->recorder()->spans[0]->getContext());
    }

    public function testGuzzleClientServicesGetADetachedSpan(): void
    {
        if (!AutoSpansKernel::hasGuzzle()) {
            self::markTestSkipped('guzzlehttp/guzzle is missing');
        }

        $client = $this->container('test')->get('test.guzzle');
        self::assertInstanceOf(ClientInterface::class, $client);

        $client->request('GET', 'https://example.com/a');

        try {
            $client->request('GET', 'https://example.com/b');
            self::fail('The mocked 404 must be rejected.');
        } catch (ClientException) {
        }

        self::assertSame(
            [['http GET example.com', ['status' => 200]], ['http GET example.com', ['exception' => ClientException::class, 'status' => 404]]],
            $this->recorder()->ended,
        );
    }

    public function testAGuzzleClientThatBuildsItsOwnHandlerIsProfiledThroughItsConfig(): void
    {
        if (!AutoSpansKernel::hasGuzzle()) {
            self::markTestSkipped('guzzlehttp/guzzle is missing');
        }

        $client = $this->container('test')->get('test.guzzle_capped');
        self::assertInstanceOf(Client::class, $client);
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        $stack->setHandler(new MockHandler([new Response(200)]));

        $client->request('GET', 'https://capped.example.com');

        self::assertSame([['http GET capped.example.com', ['status' => 200]]], $this->recorder()->ended);
    }

    private function container(string $environment): ContainerInterface
    {
        $this->kernel = new AutoSpansKernel($environment, false);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }

    /**
     * @return list<string>
     */
    private function sqlMessages(): array
    {
        return array_values(array_filter($this->recorder()->messages(), static fn (string $message): bool => str_starts_with($message, 'sql ')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contextsOf(string $message): array
    {
        return array_values(array_map(
            static fn (SpanInterface $span): array => $span->getContext(),
            array_filter($this->recorder()->spans, static fn (SpanInterface $span): bool => $span->getMessage() === $message),
        ));
    }

    private function recorder(): RecordingEndProcessor
    {
        $recorder = $this->kernel?->getContainer()->get('test.recorder');
        self::assertInstanceOf(RecordingEndProcessor::class, $recorder);

        return $recorder;
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
