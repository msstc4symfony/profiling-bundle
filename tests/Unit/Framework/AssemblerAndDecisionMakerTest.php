<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\AllowSpanDecisionMakerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullableSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanAssembler::class)]
#[CoversClass(ListBasedDecisionMaker::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullableSpan::class)]
final class AssemblerAndDecisionMakerTest extends TestCase
{
    public function testWhitelistWinsOverBlacklist(): void
    {
        $maker = new ListBasedDecisionMaker(['request '], ['request health']);

        self::assertTrue($maker->isAllow('request health'));
        self::assertFalse($maker->isAllow('cli command x'));
    }

    public function testBlacklistAloneRejectsPrefixes(): void
    {
        $maker = new ListBasedDecisionMaker(spansBlacklist: ['sql ']);

        self::assertFalse($maker->isAllow('sql select'));
        self::assertTrue($maker->isAllow('request x'));
        self::assertTrue(new ListBasedDecisionMaker()->isAllow('anything'));
    }

    public function testFirstDecidingMakerWins(): void
    {
        $abstain = new class implements AllowSpanDecisionMakerInterface {
            #[Override]
            public function isAllow(string $message): ?bool
            {
                return null;
            }

            #[Override]
            public static function getDefaultPriority(): int
            {
                return 0;
            }
        };
        $assembler = new SpanAssembler([$abstain, new ListBasedDecisionMaker(spansBlacklist: ['x'])]);

        self::assertInstanceOf(NullableSpan::class, $assembler->assemble('x', []));
        self::assertInstanceOf(Span::class, $assembler->assemble('y', []));
        self::assertInstanceOf(Span::class, new SpanAssembler([$abstain])->assemble('x', []));
    }
}
