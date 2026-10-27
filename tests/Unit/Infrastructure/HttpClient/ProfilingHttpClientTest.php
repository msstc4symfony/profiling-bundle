<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure\HttpClient;

use Generator;
use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\NullProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Processor\CreateSpan\CreateSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\CompletionStreamFilter;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\ProfiledResponse;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\ProfilingHttpClient;
use Msstc4Symfony\ProfilingBundle\Infrastructure\OutgoingRequestSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\HttpClient\ResettableLoggingClient;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingDetachedFactory;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingProfiling;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(ProfilingHttpClient::class)]
#[CoversClass(ProfiledResponse::class)]
#[CoversClass(CompletionStreamFilter::class)]
#[CoversClass(OutgoingRequestSpan::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
#[UsesClass(NullProfilingFactory::class)]
#[RequiresMethod(MockHttpClient::class, 'request')]
final class ProfilingHttpClientTest extends TestCase
{
    private RecordingEndProcessor $recorder;

    private ProfilingFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEndProcessor();
        $this->factory = RecordingProfiling::factory($this->recorder);
    }

    public function testADetachedSpanCoversTheRequestUntilTheBodyIsRead(): void
    {
        $outer = $this->factory->createSpan('outer');
        $response = $this->client(new MockResponse('body'))->request('GET', 'https://example.com/a/b?id=42');
        $inner = $this->factory->createSpan('inner');

        self::assertInstanceOf(ProfiledResponse::class, $response);
        self::assertSame($outer, $inner->getParentSpan());
        self::assertSame([], $this->recorder->messages());

        self::assertSame('body', $response->getContent());

        self::assertSame([['http GET example.com', ['status' => 200]]], $this->recorder->ended);
        $span = $this->recorder->spans[0];
        self::assertSame($outer, $span->getParentSpan());
        self::assertSame(['url_path' => '/a/b', 'profiling_automatic' => true, 'profiling_outgoing_request' => true, 'profiling_detached' => true], $span->getContext());
    }

    public function testTheHostAndPathComeFromTheResolvedUrl(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}'), 'https://api.example.com');

        (new ProfilingHttpClient($inner, $this->factory))->request('POST', '/v1/orders')->toArray();

        self::assertSame(['http POST api.example.com'], $this->recorder->messages());
        self::assertSame(['url_path' => '/v1/orders', 'profiling_automatic' => true, 'profiling_outgoing_request' => true, 'profiling_detached' => true], $this->recorder->spans[0]->getContext());
    }

    public function testWithoutAResolvedUrlAPathlessRequestIsProfiledAsSlash(): void
    {
        $inner = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse('', ['url' => '']));

        (new ProfilingHttpClient($inner, $this->factory))->request('GET', 'http://h')->getContent();

        self::assertSame(['http GET h'], $this->recorder->messages());
        self::assertSame('/', $this->recorder->spans[0]->getContext()['url_path']);
    }

    public function testIdsInThePathAreReplacedByPlaceholders(): void
    {
        $this->client(new MockResponse('body'))->request('GET', 'https://example.com/orders/42/items/550e8400-e29b-41d4-a716-446655440000?token=secret')->getContent();

        self::assertSame('/orders/:id/items/:uuid', $this->recorder->spans[0]->getContext()['url_path']);
    }

    public function testAnHttpErrorEndsTheSpanWithTheExceptionAndTheStatus(): void
    {
        $response = $this->client(new MockResponse('missing', ['http_code' => 404]))->request('GET', 'https://example.com/x');

        try {
            $response->getContent();
            self::fail('A 404 must throw.');
        } catch (ClientException) {
        }

        self::assertSame([['http GET example.com', ['exception' => ClientException::class, 'status' => 404]]], $this->recorder->ended);
    }

    public function testReadingAnErrorStatusWithoutThrowingEndsTheSpanWithTheStatus(): void
    {
        $response = $this->client(new MockResponse('[]', ['http_code' => 500]))->request('GET', 'https://example.com');

        self::assertSame([], $response->toArray(false));
        self::assertSame([['http GET example.com', ['status' => 500]]], $this->recorder->ended);
    }

    public function testAFailingToArrayEndsTheSpanWithTheException(): void
    {
        $response = $this->client(new MockResponse('missing', ['http_code' => 404]))->request('GET', 'https://example.com');

        try {
            $response->toArray();
            self::fail('A 404 must throw.');
        } catch (ClientException) {
        }

        self::assertSame([['http GET example.com', ['exception' => ClientException::class, 'status' => 404]]], $this->recorder->ended);
    }

    public function testATransportErrorEndsTheSpanWithTheException(): void
    {
        $response = $this->client(new MockResponse('', ['error' => 'connection refused']))->request('GET', 'https://example.com');

        try {
            $response->getStatusCode();
            self::fail('A transport error must throw.');
        } catch (TransportException) {
        }

        // MockResponse reports http_code 200 even for a transport error; a real transport reports 0, i.e. no status.
        self::assertSame(['http GET example.com'], $this->recorder->messages());
        self::assertSame(TransportException::class, $this->recorder->ended[0][1]['exception'] ?? null);
    }

    public function testFailingHeadersEndTheSpan(): void
    {
        $response = $this->client(new MockResponse('', ['http_code' => 404]))->request('GET', 'https://example.com');

        try {
            $response->getHeaders();
            self::fail('A 404 must throw.');
        } catch (ClientException) {
        }

        self::assertSame([['http GET example.com', ['exception' => ClientException::class, 'status' => 404]]], $this->recorder->ended);
    }

    public function testStatusAndHeadersAloneDoNotEndTheSpan(): void
    {
        $response = $this->client(new MockResponse('body', ['response_headers' => ['X-A' => 'b']]))->request('GET', 'https://example.com');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['b'], $response->getHeaders()['x-a']);
        self::assertSame('https://example.com/', $response->getInfo('url'));
        self::assertSame([], $this->recorder->messages());
    }

    public function testCancellingBeforeTheHeadersEndsTheSpanAsCancelledOnce(): void
    {
        $factory = new RecordingDetachedFactory();
        $response = (new ProfilingHttpClient(new MockHttpClient(new MockResponse('body')), $factory))->request('GET', 'https://example.com');

        $response->cancel();
        $response->cancel();
        unset($response);

        self::assertSame([['cancelled' => true]], $factory->endContexts);
    }

    public function testCancellingAfterTheHeadersKeepsTheStatus(): void
    {
        $response = $this->client(new MockResponse('body', ['http_code' => 503]))->request('GET', 'https://example.com');
        $response->getStatusCode();

        $response->cancel();

        self::assertSame([['http GET example.com', ['body_cancelled' => true, 'status' => 503]]], $this->recorder->ended);
    }

    public function testACancelledResponseReportsItself(): void
    {
        $response = $this->client(new MockResponse('body'))->request('GET', 'https://example.com');

        $response->cancel();

        self::assertTrue($response->getInfo('canceled'));
        self::assertSame([['http GET example.com', ['cancelled' => true]]], $this->recorder->ended);
    }

    public function testToArrayReturnsTheWholeDocument(): void
    {
        $response = $this->client(new MockResponse('{"a": 1, "b": 2}'))->request('GET', 'https://example.com');

        self::assertSame(['a' => 1, 'b' => 2], $response->toArray());
    }

    public function testWithoutAResolvedUrlTheRequestedOneNamesTheSpan(): void
    {
        $inner = self::createStub(HttpClientInterface::class);
        $inner->method('request')->willReturn(self::createStub(ResponseInterface::class));

        $response = (new ProfilingHttpClient($inner, $this->factory))->request('GET', 'https://fallback.example.com/p');
        $this->factory->endAll();

        self::assertInstanceOf(ProfiledResponse::class, $response);
        self::assertSame(['http GET fallback.example.com'], $this->recorder->messages());
        self::assertSame(['url_path' => '/p', 'profiling_automatic' => true, 'profiling_outgoing_request' => true, 'profiling_detached' => true], $this->recorder->spans[0]->getContext());
    }

    public function testARequestThatThrowsGetsASpanWithTheException(): void
    {
        $failure = new TransportException('invalid option');
        $inner = self::createStub(HttpClientInterface::class);
        $inner->method('request')->willThrowException($failure);
        $caught = null;

        try {
            (new ProfilingHttpClient($inner, $this->factory))->request('GET', 'https://example.com/x');
        } catch (TransportException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught);
        self::assertSame([['http GET example.com', ['exception' => TransportException::class]]], $this->recorder->ended);
    }

    public function testAFactoryFailingWhileARequestThrowsLeavesTheRequestExceptionAlone(): void
    {
        $failure = new TransportException('invalid option');
        $inner = self::createStub(HttpClientInterface::class);
        $inner->method('request')->willThrowException($failure);
        $factory = self::createStub(DetachedSpanFactoryInterface::class);
        $factory->method('createDetachedSpan')->willThrowException(new RuntimeException('profiling bug'));

        $this->expectExceptionObject($failure);

        (new ProfilingHttpClient($inner, $factory))->request('GET', 'https://example.com/x');
    }

    public function testAFactoryFailingToOpenTheSpanGivesTheInnerResponse(): void
    {
        $response = self::createStub(ResponseInterface::class);
        $inner = self::createStub(HttpClientInterface::class);
        $inner->method('request')->willReturn($response);
        $factory = self::createStub(DetachedSpanFactoryInterface::class);
        $factory->method('createDetachedSpan')->willThrowException(new RuntimeException('profiling bug'));

        $returned = (new ProfilingHttpClient($inner, $factory))->request('GET', 'https://example.com');

        self::assertSame($response, $returned);
    }

    public function testAFactoryFailingToEndTheSpanNeverReachesTheCaller(): void
    {
        $factory = self::createStub(DetachedSpanFactoryInterface::class);
        $factory->method('createDetachedSpan')->willReturn(new NullSpan('http'));
        $factory->method('endSpan')->willThrowException(new LogicException('decorator bug'));
        $client = new ProfilingHttpClient(new MockHttpClient([new MockResponse('body'), new MockResponse('stream')]), $factory);

        self::assertSame('body', $client->request('GET', 'https://example.com')->getContent());

        $response = $client->request('GET', 'https://example.com');
        self::assertInstanceOf(ProfiledResponse::class, $response);
        $stream = $response->toStream();
        self::assertSame('s', fread($stream, 1));
        self::assertTrue(fclose($stream));
    }

    public function testAnErrorInfoThatCannotBeReadDoesNotFailTheStream(): void
    {
        $inner = self::createStub(ResponseInterface::class);
        $inner->method('getInfo')->willThrowException(new RuntimeException('broken response'));
        $factory = new RecordingDetachedFactory();
        $response = new ProfiledResponse($inner, new MockHttpClient(), $factory->createDetachedSpan('x'), $factory);

        $response->failIfTransportError();

        self::assertSame([], $factory->endContexts);
    }

    public function testAStatusThatCannotBeReadIsLeftOut(): void
    {
        $inner = self::createStub(ResponseInterface::class);
        $inner->method('getInfo')->willThrowException(new RuntimeException('broken response'));
        $inner->method('getContent')->willReturn('body');
        $factory = new RecordingDetachedFactory();

        (new ProfiledResponse($inner, new MockHttpClient(), $factory->createDetachedSpan('x'), $factory))->getContent();

        self::assertSame([[]], $factory->endContexts);
    }

    public function testAResponseThatCannotStreamItselfIsStreamedThroughTheClient(): void
    {
        $factory = new RecordingDetachedFactory();
        $response = new ProfiledResponse(self::createStub(ResponseInterface::class), new MockHttpClient(), $factory->createDetachedSpan('x'), $factory);

        self::assertIsResource($response->toStream(false));
    }

    public function testClosingAPartiallyReadStreamEndsTheSpanAsClosed(): void
    {
        $response = $this->profiled(new MockResponse(['first chunk ', 'second chunk']));
        $stream = $response->toStream();

        fread($stream, 1);
        fclose($stream);

        self::assertSame([['http GET example.com', ['stream_closed' => true, 'status' => 200]]], $this->recorder->ended);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testThePartiallyReadStreamLeavesTheSpanOpen(): void
    {
        $response = $this->profiled(new MockResponse(['first chunk ', 'second chunk']));

        $stream = $response->toStream();

        self::assertSame('f', fread($stream, 1));
        self::assertSame([], $this->recorder->messages());
        self::assertSame('irst chunk second chunk', stream_get_contents($stream));
        self::assertSame(['http GET example.com'], $this->recorder->messages());
    }

    public function testDestroyingAnUnreadResponseEndsTheSpan(): void
    {
        $response = $this->client(new MockResponse('body'))->request('GET', 'https://example.com');

        unset($response);

        self::assertSame([['http GET example.com', ['response_destroyed' => true, 'status' => 200]]], $this->recorder->ended);
    }

    public function testDestroyingAReadResponseDoesNotEndTheSpanAgain(): void
    {
        $response = $this->client(new MockResponse('body'))->request('GET', 'https://example.com');
        $response->getContent();

        unset($response);

        self::assertSame([['http GET example.com', ['status' => 200]]], $this->recorder->ended);
    }

    /**
     * @param class-string<RuntimeException> $exception
     */
    #[TestWith([404, ClientException::class])]
    #[TestWith([500, ServerException::class])]
    public function testDestroyingAnUnreadErrorResponseEndsTheSpanAndKeepsTheDestructorException(int $status, string $exception): void
    {
        $request = fn (): ResponseInterface => $this->client(new MockResponse('', ['http_code' => $status]))->request('GET', 'https://example.com');
        $caught = null;

        try {
            // The response is destroyed right after the call, unread.
            $request();
        } catch (Throwable $destructorException) {
            $caught = $destructorException;
        }

        self::assertInstanceOf($exception, $caught);
        self::assertSame([['http GET example.com', ['exception' => $exception, 'status' => $status]]], $this->recorder->ended);
    }

    public function testStreamingToTheLastChunkEndsTheSpanAndYieldsTheProfiledResponse(): void
    {
        $client = $this->client(new MockResponse(['a', 'b']));
        $response = $client->request('GET', 'https://example.com');

        $content = '';
        foreach ($client->stream($response) as $streamed => $chunk) {
            self::assertSame($response, $streamed);
            $content .= $chunk->getContent();
        }

        self::assertSame('ab', $content);
        self::assertSame([['http GET example.com', ['status' => 200]]], $this->recorder->ended);
    }

    public function testStreamingAForeignResponsePassesItThrough(): void
    {
        $inner = new MockHttpClient([new MockResponse('a')]);
        $foreign = $inner->request('GET', 'https://example.com');

        $responses = [];
        foreach ((new ProfilingHttpClient($inner, $this->factory))->stream([$foreign]) as $streamed => $chunk) {
            $responses[] = $streamed;
            $chunk->getContent();
        }

        self::assertSame($foreign, $responses[0]);
        self::assertSame([], $this->recorder->messages());
    }

    public function testAStreamedTransportErrorEndsTheSpanWithTheException(): void
    {
        $client = $this->client(new MockResponse('', ['error' => 'connection refused']));
        $response = $client->request('GET', 'https://example.com');

        $errors = 0;
        foreach ($client->stream($response) as $chunk) {
            try {
                $chunk->isLast();
            } catch (TransportException) {
                $errors++;
            }
        }

        self::assertSame(1, $errors);
        // MockResponse reports http_code 200 even for a transport error; a real transport reports 0, i.e. no status.
        self::assertSame(['http GET example.com'], $this->recorder->messages());
        self::assertSame(TransportException::class, $this->recorder->ended[0][1]['exception'] ?? null);
    }

    public function testAStreamTimeoutLeavesTheSpanOpen(): void
    {
        // An empty chunk of a MockResponse body stands for an idle timeout.
        $client = $this->client(new MockResponse(['', 'body']));
        $response = $client->request('GET', 'https://example.com');

        $timeouts = 0;
        foreach ($client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $timeouts++;

                break;
            }
        }

        self::assertSame(1, $timeouts);
        self::assertSame([], $this->recorder->messages());
        $response->getContent();
        self::assertSame(['http GET example.com'], $this->recorder->messages());
    }

    public function testStreamingProfiledAndForeignResponsesTogetherEndsOnlyTheProfiledSpans(): void
    {
        $inner = new MockHttpClient([new MockResponse('a'), new MockResponse('b')]);
        $client = new ProfilingHttpClient($inner, $this->factory);
        $profiled = $client->request('GET', 'https://profiled.example.com');
        $foreign = $inner->request('GET', 'https://foreign.example.com');

        $seen = [];
        foreach ($client->stream([$profiled, $foreign]) as $streamed => $chunk) {
            if ($chunk->isLast()) {
                $seen[] = $streamed;
            }
        }

        self::assertCount(2, $seen);
        self::assertContains($profiled, $seen);
        self::assertContains($foreign, $seen);
        self::assertSame([['http GET profiled.example.com', ['status' => 200]]], $this->recorder->ended);
    }

    public function testReadingTheStreamToItsEndEndsTheSpan(): void
    {
        $response = $this->profiled(new MockResponse('body'));

        $stream = $response->toStream();

        self::assertSame([], $this->recorder->messages());
        self::assertSame('body', stream_get_contents($stream));
        self::assertSame([['http GET example.com', ['status' => 200]]], $this->recorder->ended);
    }

    public function testToStreamThrowsAnHttpErrorAndEndsTheSpanWithIt(): void
    {
        $response = $this->profiled(new MockResponse('missing', ['http_code' => 404]));

        try {
            $response->toStream();
            self::fail('A 404 must throw.');
        } catch (ClientException) {
        }

        self::assertSame([['http GET example.com', ['exception' => ClientException::class, 'status' => 404]]], $this->recorder->ended);
    }

    public function testToStreamWithoutThrowingGivesTheErrorBody(): void
    {
        $response = $this->profiled(new MockResponse('missing', ['http_code' => 404]));

        self::assertSame('missing', stream_get_contents($response->toStream(false)));
        self::assertSame([['http GET example.com', ['status' => 404]]], $this->recorder->ended);
    }

    public function testATransportErrorInTheMiddleOfAStreamedBodyEndsTheSpanWithTheException(): void
    {
        $body = static function (): Generator {
            yield 'first chunk ';

            throw new TransportException('connection reset');
        };
        $response = $this->profiled(new MockResponse($body()));
        $stream = $response->toStream();

        stream_get_contents($stream);
        fclose($stream);

        self::assertSame('http GET example.com', $this->recorder->ended[0][0] ?? null);
        self::assertSame(TransportException::class, $this->recorder->ended[0][1]['exception'] ?? null);
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
        $response = (new ProfilingHttpClient(new MockHttpClient(new MockResponse('body')), $factory))->request('GET', 'https://example.com');

        self::assertSame('body', $response->getContent());
        self::assertSame(['http GET example.com'], $this->recorder->messages());
    }

    public function testWithOptionsKeepsProfiling(): void
    {
        $client = $this->client(new MockResponse('body'))->withOptions(['base_uri' => 'https://scoped.example.com']);

        self::assertInstanceOf(ProfilingHttpClient::class, $client);
        $client->request('GET', '/x')->getContent();

        self::assertSame(['http GET scoped.example.com'], $this->recorder->messages());
    }

    public function testResetReachesTheInnerClient(): void
    {
        $inner = new ResettableLoggingClient();

        (new ProfilingHttpClient($inner, $this->factory))->reset();

        self::assertSame(1, $inner->resets);
    }

    public function testTheLoggerReachesAnInnerClientThatTakesOne(): void
    {
        $inner = new ResettableLoggingClient();
        $logger = new NullLogger();

        (new ProfilingHttpClient($inner, $this->factory))->setLogger($logger);
        (new ProfilingHttpClient(self::createStub(HttpClientInterface::class), $this->factory))->setLogger($logger);

        self::assertSame($logger, $inner->logger);
    }

    private function profiled(MockResponse $response): ProfiledResponse
    {
        $profiled = $this->client($response)->request('GET', 'https://example.com');
        self::assertInstanceOf(ProfiledResponse::class, $profiled);

        return $profiled;
    }

    private function client(MockResponse $response): ProfilingHttpClient
    {
        return new ProfilingHttpClient(new MockHttpClient($response), $this->factory);
    }
}
