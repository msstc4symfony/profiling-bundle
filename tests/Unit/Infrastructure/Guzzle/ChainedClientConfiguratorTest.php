<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ProfilingGuzzleMiddleware;
use Msstc4Symfony\ProfilingBundle\Infrastructure\OutgoingRequestSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingEndProcessor;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\RecordingProfiling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainedClientConfigurator::class)]
#[UsesClass(ProfilingGuzzleMiddleware::class)]
#[UsesClass(OutgoingRequestSpan::class)]
#[UsesClass(ProfilingFactory::class)]
#[UsesClass(SpanAssembler::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[RequiresMethod(ClientInterface::class, 'send')]
final class ChainedClientConfiguratorTest extends TestCase
{
    public function testRunsTheApplicationConfiguratorFirstThenAddsTheMiddleware(): void
    {
        $recorder = new RecordingEndProcessor();
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200), new Response(200)]))]);
        $profiledWhenTheApplicationConfiguratorRan = null;
        $configurator = static function (ClientInterface $configured) use (&$profiledWhenTheApplicationConfiguratorRan, $recorder): void {
            $configured->request('GET', 'https://configurator.example.com');
            $profiledWhenTheApplicationConfiguratorRan = $recorder->messages();
        };

        (new ChainedClientConfigurator($configurator, new ProfilingGuzzleMiddleware(RecordingProfiling::factory($recorder))))($client);
        $client->request('GET', 'https://example.com');

        self::assertSame([], $profiledWhenTheApplicationConfiguratorRan);
        self::assertSame(['http GET example.com'], $recorder->messages());
    }
}
