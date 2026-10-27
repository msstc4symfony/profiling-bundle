<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\NullProfilingFactory;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileHttpClientPass;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\ProfilingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\CurlHttpClient;

#[CoversClass(ProfileHttpClientPass::class)]
#[UsesClass(AutoSpan::class)]
final class ProfileHttpClientPassTest extends TestCase
{
    public function testDecoratesTheSharedTransportOutsideTheOtherFamilyDecorators(): void
    {
        $container = $this->container(true);
        $container->setDefinition('http_client.transport', new Definition(CurlHttpClient::class));

        (new ProfileHttpClientPass())->process($container);

        $decorator = $container->getDefinition('http_client.transport.decorator.profiling');
        self::assertSame(ProfilingHttpClient::class, $decorator->getClass());
        self::assertSame(['http_client.transport', null, -25], $decorator->getDecoratedService());
        self::assertEquals(
            ['$inner' => new Reference('http_client.transport.decorator.profiling.inner'), '$profiling' => new Reference(DetachedSpanFactoryInterface::class), '$baseUri' => null],
            $decorator->getArguments(),
        );
    }

    /**
     * @param array<int|string, mixed> $transportArguments
     * @param string|array<string, string>|null $baseUri
     */
    #[TestWith([[['base_uri' => 'https://api.example.com']], 'https://api.example.com'])]
    #[TestWith([['$defaultOptions' => ['base_uri' => 'https://api.example.com']], 'https://api.example.com'])]
    #[TestWith([['%transport.options%'], 'https://param.example.com'])]
    #[TestWith([[['timeout' => 3]], null])]
    #[TestWith([[['base_uri' => ['scheme' => 'https:', 'authority' => '//parts.example.com']]], ['scheme' => 'https:', 'authority' => '//parts.example.com']])]
    #[TestWith([[['base_uri' => 'https://example.com/%%7E/']], 'https://example.com/%%7E/'])]
    #[TestWith([[['base_uri' => 42]], null])]
    public function testPassesTheTransportsDefaultBaseUri(array $transportArguments, string|array|null $baseUri): void
    {
        $container = $this->container(true);
        $container->setParameter('transport.options', ['base_uri' => 'https://param.example.com']);
        $container->setDefinition('http_client.transport', new Definition(CurlHttpClient::class, $transportArguments));

        (new ProfileHttpClientPass())->process($container);

        self::assertSame($baseUri, $container->getDefinition('http_client.transport.decorator.profiling')->getArgument('$baseUri'));
    }

    public function testReadsTheDefinitionBehindAnAliasedTransport(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.transport', new Definition(CurlHttpClient::class, [['base_uri' => 'https://api.example.com']]));
        $container->setAlias('http_client.transport', 'app.transport');

        (new ProfileHttpClientPass())->process($container);

        self::assertSame('https://api.example.com', $container->getDefinition('http_client.transport.decorator.profiling')->getArgument('$baseUri'));
    }

    public function testAnEscapedPercentReachesTheDecoratorOnce(): void
    {
        $container = $this->container(true);
        $container->setDefinition('http_client.transport', (new Definition(CurlHttpClient::class, [['base_uri' => 'https://example.com/%%41%%42/']]))->setPublic(true));
        $container->register(DetachedSpanFactoryInterface::class, NullProfilingFactory::class);
        $container->addCompilerPass(new ProfileHttpClientPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);

        $container->compile(true);

        $decorator = $container->get('http_client.transport');
        self::assertInstanceOf(ProfilingHttpClient::class, $decorator);
        self::assertSame('https://example.com/%41%42/', (new ReflectionProperty($decorator, 'baseUri'))->getValue($decorator));
    }

    public function testKeepsAnEnvPlaceholderForRuntime(): void
    {
        $container = $this->container(true);
        $container->setDefinition('http_client.transport', new Definition(CurlHttpClient::class, [['base_uri' => '%env(API_URL)%']]));

        (new ProfileHttpClientPass())->process($container);

        $baseUri = $container->getDefinition('http_client.transport.decorator.profiling')->getArgument('$baseUri');
        self::assertIsString($baseUri);
        self::assertSame('%env(API_URL)%', $container->resolveEnvPlaceholders($baseUri));
    }

    #[TestWith([false])]
    #[TestWith([null])]
    public function testDoesNothingWhenHttpClientSpansAreOff(?bool $enabled): void
    {
        $container = $this->container($enabled);
        $container->setDefinition('http_client.transport', new Definition(CurlHttpClient::class));

        (new ProfileHttpClientPass())->process($container);

        self::assertFalse($container->hasDefinition('http_client.transport.decorator.profiling'));
    }

    public function testFailsWithoutTheFrameworkTransport(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"msstc4symfony_profiling.auto_spans.http_client" needs "framework.http_client" enabled.');

        (new ProfileHttpClientPass())->process($this->container(true));
    }

    private function container(?bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($enabled !== null) {
            $container->setParameter(AutoSpan::HTTP_CLIENT->option(), $enabled);
        }

        return $container;
    }
}
