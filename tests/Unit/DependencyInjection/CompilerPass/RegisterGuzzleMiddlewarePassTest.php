<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\DependencyInjection\CompilerPass;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\RegisterGuzzleMiddlewarePass;
use Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle\ProfilingGuzzleMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RegisterGuzzleMiddlewarePass::class)]
#[UsesClass(AutoSpan::class)]
final class RegisterGuzzleMiddlewarePassTest extends TestCase
{
    public function testRegistersTheMiddlewareOnTheDetachedSpanFactoryAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(AutoSpan::GUZZLE->option(), true);

        (new RegisterGuzzleMiddlewarePass())->process($container);

        $middleware = $container->getDefinition(ProfilingGuzzleMiddleware::class);
        self::assertSame(ProfilingGuzzleMiddleware::class, $middleware->getClass());
        self::assertEquals(['$profiling' => new Reference(DetachedSpanFactoryInterface::class)], $middleware->getArguments());
    }

    #[TestWith([false])]
    #[TestWith([null])]
    public function testRegistersNothingWhenGuzzleSpansAreOff(?bool $enabled): void
    {
        $container = new ContainerBuilder();
        if ($enabled !== null) {
            $container->setParameter(AutoSpan::GUZZLE->option(), $enabled);
        }

        (new RegisterGuzzleMiddlewarePass())->process($container);

        self::assertFalse($container->hasDefinition(ProfilingGuzzleMiddleware::class));
    }
}
