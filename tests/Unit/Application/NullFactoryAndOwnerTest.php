<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Application;

use Msstc4Symfony\ProfilingBundle\Application\NullProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryOwnerTrait;
use Msstc4Symfony\ProfilingBundle\Application\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\FactoryOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(NullProfilingFactory::class)]
#[CoversTrait(ProfilingFactoryOwnerTrait::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(NullSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(ProfilingFactory::class)]
final class NullFactoryAndOwnerTest extends TestCase
{
    public function testOwnerFallsBackToTheNullFactory(): void
    {
        $factory = (new FactoryOwner())->factory();

        self::assertInstanceOf(NullProfilingFactory::class, $factory);
        self::assertFalse($factory->createSpan('x')->isRecorded());
        $factory->endAll();
    }

    public function testNullFactoryEndSpanNeverThrows(): void
    {
        $span = (new Span('x'))->addEndHandler(static function (): never {
            throw new RuntimeException('handler bug');
        });

        (new NullProfilingFactory())->endSpan($span);

        self::assertTrue($span->isEnded());
    }

    public function testNullFactoryKeepOpenOnResetLeavesTheSpanUntouched(): void
    {
        $span = new Span('x');

        (new NullProfilingFactory())->keepOpenOnReset($span);

        self::assertFalse($span->isEnded());
        self::assertSame('x', $span->getMessage());
    }

    public function testOwnerUsesTheInjectedFactory(): void
    {
        $factory = new ProfilingFactory([], [], [], new NullLogger());

        self::assertSame($factory, (new FactoryOwner())->setProfilingFactory($factory)->factory());
    }
}
