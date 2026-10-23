<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileHttpClientPass;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\ProfilingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
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
            ['$inner' => new Reference('http_client.transport.decorator.profiling.inner'), '$profiling' => new Reference(DetachedSpanFactoryInterface::class)],
            $decorator->getArguments(),
        );
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
