<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\NullableProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryOwnerTrait;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullableSpan;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\FactoryOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullableProfilingFactory::class)]
#[CoversTrait(ProfilingFactoryOwnerTrait::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(NullableSpan::class)]
#[UsesClass(ProfilingFactory::class)]
final class NullableFactoryAndOwnerTest extends TestCase
{
    public function testOwnerFallsBackToTheNullFactory(): void
    {
        $factory = new FactoryOwner()->factory();

        self::assertInstanceOf(NullableProfilingFactory::class, $factory);
        self::assertFalse($factory->createSpan('x')->isRecorded());
        $factory->endAll();
    }

    public function testOwnerUsesTheInjectedFactory(): void
    {
        $factory = new ProfilingFactory();

        self::assertSame($factory, new FactoryOwner()->setProfilingFactory($factory)->factory());
    }
}
