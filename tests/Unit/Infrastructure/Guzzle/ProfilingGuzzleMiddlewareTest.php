<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure\Guzzle;

use Closure;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssemblerInterface;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ProfilingGuzzleMiddleware;
use Msstc4Symfony\ProfilingBundle\Infrastructure\OutgoingRequestSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\Guzzle\ClientWithoutConfig;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingProfiling;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ProfilingGuzzleMiddleware::class)]
#[UsesClass(OutgoingRequestSpan::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
#[RequiresMethod(ClientInterface::class, 'send')]
final class ProfilingGuzzleMiddlewareTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    private ProfilingFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->factory = RecordingProfiling::factory($this->recorder);
    }

    public function testASentRequestGetsADetachedSpanEndedWithTheStatus(): void
    {
        $outer = $this->factory->createSpan('outer');

        $response = $this->client(new Response(201))->request('POST', 'https://api.example.com/v1/orders?x=1');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([['http POST api.example.com', ['status' => 201]]], $this->recorder->ended);
        self::assertSame($outer, $this->recorder->spans[0]->getParentSpan());
        self::assertSame(['url_path' => '/v1/orders', 'profiling_automatic' => true, 'profiling_detached' => true], $this->recorder->spans[0]->getContext());
        self::assertFalse($outer->isEnded());
    }

    public function testARequestWithoutPathIsProfiledWithSlash(): void
    {
        $this->client(new Response(200))->request('GET', 'https://example.com');

        self::assertSame(['url_path' => '/', 'profiling_automatic' => true, 'profiling_detached' => true], $this->recorder->spans[0]->getContext());
    }

    public function testOneSpanCoversTheRedirects(): void
    {
        $this->client(new Response(302, ['Location' => 'https://example.com/next']), new Response(200))->request('GET', 'https://example.com/start');

        self::assertSame([['http GET example.com', ['status' => 200]]], $this->recorder->ended);
    }

    public function testOneSpanCoversTheRetriesOfTheRetryMiddleware(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(503), new Response(200)]));
        $stack->push(Middleware::retry(
            static fn (int $retries, RequestInterface $request, ?ResponseInterface $response = null): bool => $retries < 1 && $response?->getStatusCode() === 503,
            static fn (): int => 0,
        ));
        $client = new Client(['handler' => (new ProfilingGuzzleMiddleware($this->factory))->decorateHandler($stack)]);

        self::assertSame(200, $client->request('GET', 'https://example.com')->getStatusCode());
        self::assertSame([['http GET example.com', ['status' => 200]]], $this->recorder->ended);
    }

    public function testAnErrorResponseEndsTheSpanWithTheExceptionAndTheStatus(): void
    {
        try {
            $this->client(new Response(404))->request('GET', 'https://example.com/missing');
            self::fail('http_errors must reject a 404.');
        } catch (ClientException) {
        }

        self::assertSame([['http GET example.com', ['exception' => ClientException::class, 'status' => 404]]], $this->recorder->ended);
    }

    public function testATransportFailureEndsTheSpanWithTheException(): void
    {
        $failure = new ConnectException('connection refused', new Request('GET', 'https://example.com'));

        try {
            $this->client($failure)->request('GET', 'https://example.com');
            self::fail('A connection failure must reach the caller.');
        } catch (ConnectException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([['http GET example.com', ['exception' => ConnectException::class]]], $this->recorder->ended);
    }

    public function testAnAsynchronousRequestEndsWhenItsPromiseSettles(): void
    {
        $client = $this->client(new Response(200), new Response(500));

        $first = $client->requestAsync('GET', 'https://a.example.com');
        $second = $client->requestAsync('GET', 'https://b.example.com', ['http_errors' => false]);

        self::assertSame([], $this->recorder->messages());

        Utils::all([$first, $second])->wait();

        $ended = array_column($this->recorder->ended, 1, 0);
        ksort($ended);
        self::assertSame(['http GET a.example.com' => ['status' => 200], 'http GET b.example.com' => ['status' => 500]], $ended);
    }

    public function testAPoolWithConcurrencyGetsOneSpanPerRequest(): void
    {
        $client = $this->client(new Response(200), new Response(201), new Response(202));
        $requests = static function (): Generator {
            foreach (['a', 'b', 'c'] as $host) {
                yield new Request('GET', 'https://' . $host . '.example.com');
            }
        };

        (new Pool($client, $requests(), ['concurrency' => 2]))->promise()->wait();

        $ended = array_column($this->recorder->ended, 1, 0);
        ksort($ended);
        self::assertSame(['http GET a.example.com', 'http GET b.example.com', 'http GET c.example.com'], array_keys($ended));
        self::assertEqualsCanonicalizing([200, 201, 202], array_column($ended, 'status'));
    }

    /**
     * @return iterable<string, array{PromiseInterface, array<string, int|string>}>
     */
    public static function settledPromises(): iterable
    {
        $request = new Request('GET', 'https://example.com');

        yield 'request exception without response' => [Create::rejectionFor(new RequestException('failed', $request)), ['exception' => RequestException::class]];
        yield 'reason that is no object' => [Create::rejectionFor('failed'), ['exception' => 'string']];
        yield 'fulfilled with something else than a response' => [Create::promiseFor('not a response'), []];
    }

    /**
     * @param array<string, int|string> $endContext
     */
    #[DataProvider('settledPromises')]
    public function testTheEndContextOnlyNamesWhatThePromiseSettledWith(PromiseInterface $settled, array $endContext): void
    {
        $handler = (new ProfilingGuzzleMiddleware($this->factory))->middleware()(static fn (): PromiseInterface => $settled);

        $handler(new Request('GET', 'https://example.com'), [])->wait(false);

        self::assertSame([['http GET example.com', $endContext]], $this->recorder->ended);
    }

    public function testAFactoryFailingToEndTheSpanKeepsAFulfilledPromiseFulfilled(): void
    {
        $handler = (new ProfilingGuzzleMiddleware($this->failingToEnd()))->middleware()(static fn (): PromiseInterface => Create::promiseFor(new Response(200)));

        $response = $handler(new Request('GET', 'https://example.com'), [])->wait();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAFactoryFailingToEndTheSpanKeepsTheRejectionReason(): void
    {
        $reason = new ConnectException('refused', new Request('GET', 'https://example.com'));
        $handler = (new ProfilingGuzzleMiddleware($this->failingToEnd()))->middleware()(static fn (): PromiseInterface => Create::rejectionFor($reason));

        $this->expectExceptionObject($reason);

        $handler(new Request('GET', 'https://example.com'), [])->wait();
    }

    public function testAFactoryFailingToEndTheSpanKeepsTheHandlerException(): void
    {
        $failure = new RuntimeException('handler bug');
        $handler = (new ProfilingGuzzleMiddleware($this->failingToEnd()))->middleware()(static fn (): never => throw $failure);

        $this->expectExceptionObject($failure);

        $handler(new Request('GET', 'https://example.com'), []);
    }

    public function testAFactoryFailingToOpenTheSpanStillSendsTheRequest(): void
    {
        $factory = self::createStub(DetachedSpanFactoryInterface::class);
        $factory->method('createDetachedSpan')->willThrowException(new RuntimeException('profiling bug'));
        $stack = HandlerStack::create(new MockHandler([new Response(204)]));
        $client = new Client(['handler' => (new ProfilingGuzzleMiddleware($factory))->decorateHandler($stack)]);

        self::assertSame(204, $client->request('GET', 'https://example.com')->getStatusCode());
    }

    public function testAHandlerThrowingSynchronouslyEndsTheSpanAndRethrows(): void
    {
        $failure = new RuntimeException('handler bug');
        $handler = (new ProfilingGuzzleMiddleware($this->factory))->middleware()(
            static fn (): never => throw $failure,
        );

        try {
            $handler(new Request('GET', 'https://example.com'), []);
            self::fail('The handler exception must reach the caller.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([['http GET example.com', ['exception' => RuntimeException::class]]], $this->recorder->ended);
    }

    public function testDecoratingAStackTwiceKeepsOneMiddleware(): void
    {
        $profiling = new ProfilingGuzzleMiddleware($this->factory);
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));

        $profiling->decorateHandler($stack);
        $client = new Client(['handler' => $profiling->decorateHandler($stack)]);
        $client->request('GET', 'https://example.com');

        self::assertSame(['http GET example.com'], $this->recorder->messages());
    }

    public function testTheMiddlewareRunsOutsideEveryOtherMiddleware(): void
    {
        $log = [];
        $remember = static function (string $entry) use (&$log): void {
            $log[] = $entry;
        };
        $assembler = new class($remember) implements SpanAssemblerInterface {
            /** @param Closure(string): void $remember */
            public function __construct(
                private readonly Closure $remember,
            ) {
            }

            #[Override]
            public function assemble(string $message, array $context): ?SpanInterface
            {
                ($this->remember)($message);

                return null;
            }
        };
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->unshift(static fn (callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $remember): PromiseInterface {
            $remember('application middleware');

            return $handler($request, $options);
        });

        (new ProfilingGuzzleMiddleware(new ProfilingFactory([$assembler], [], [], new NullLogger())))->decorateHandler($stack);
        (new Client(['handler' => $stack]))->request('GET', 'https://example.com');

        self::assertSame(['http GET example.com', 'application middleware'], $log);
    }

    public function testWithoutAStackDecoratingCreatesGuzzlesDefaultOne(): void
    {
        $stack = (new ProfilingGuzzleMiddleware($this->factory))->decorateHandler();

        self::assertInstanceOf(HandlerStack::class, $stack);
        self::assertTrue($stack->hasHandler());
    }

    public function testABareHandlerIsReturnedUnchanged(): void
    {
        $handler = new MockHandler([]);

        self::assertSame($handler, (new ProfilingGuzzleMiddleware($this->factory))->decorateHandler($handler));
    }

    public function testAddHandlerProfilesAClientThroughItsConfig(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]);

        (new ProfilingGuzzleMiddleware($this->factory))->addHandler($client);
        $client->request('GET', 'https://example.com');

        self::assertSame(['http GET example.com'], $this->recorder->messages());
    }

    public function testAddHandlerLeavesAClientWithABareHandlerAlone(): void
    {
        $client = new Client(['handler' => new MockHandler([new Response(200)])]);

        (new ProfilingGuzzleMiddleware($this->factory))->addHandler($client);
        $client->request('GET', 'https://example.com');

        self::assertSame([], $this->recorder->messages());
    }

    public function testAddHandlerLeavesAClientWithoutConfigAlone(): void
    {
        if (method_exists(ClientInterface::class, 'getConfig')) {
            self::markTestSkipped('Guzzle 7 clients always have getConfig().');
        }

        $client = new ClientWithoutConfig();

        (new ProfilingGuzzleMiddleware($this->factory))->addHandler($client);

        self::assertSame([], $this->recorder->messages());
    }

    public function testAFailingEndHandlerNeverReachesTheCaller(): void
    {
        $factory = new ProfilingFactory(
            [new SpanAssembler([])],
            [new class implements CreateSpanProcessorInterface {
                #[Override]
                public function process(SpanInterface $span): SpanInterface
                {
                    return $span->addEndHandler(static function (): never {
                        throw new RuntimeException('handler bug');
                    });
                }
            }],
            [$this->recorder],
            new NullLogger(),
        );
        $client = new Client(['handler' => (new ProfilingGuzzleMiddleware($factory))->decorateHandler(HandlerStack::create(new MockHandler([new Response(200)])))]);

        self::assertSame(200, $client->request('GET', 'https://example.com')->getStatusCode());
        self::assertSame(['http GET example.com'], $this->recorder->messages());
    }

    private function failingToEnd(): DetachedSpanFactoryInterface
    {
        $factory = self::createStub(DetachedSpanFactoryInterface::class);
        $factory->method('createDetachedSpan')->willReturn(new NullSpan('http'));
        $factory->method('endSpan')->willThrowException(new LogicException('decorator bug'));

        return $factory;
    }

    private function client(ResponseInterface|ConnectException ...$queue): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));

        return new Client(['handler' => (new ProfilingGuzzleMiddleware($this->factory))->decorateHandler($stack)]);
    }
}
