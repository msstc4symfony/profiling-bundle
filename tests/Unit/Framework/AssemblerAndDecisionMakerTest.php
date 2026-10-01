<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\AllowSpanDecisionMakerInterface;
use Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan\ListBasedDecisionMaker;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[CoversClass(SpanAssembler::class)]
#[CoversClass(ListBasedDecisionMaker::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
#[UsesClass(NullSpan::class)]
final class AssemblerAndDecisionMakerTest extends TestCase
{
    public function testWhitelistRecordsOnlyMatchingPrefixes(): void
    {
        $maker = new ListBasedDecisionMaker(['request ']);

        self::assertTrue($maker->isAllowed('request health'));
        self::assertFalse($maker->isAllowed('cli command x'));
        self::assertFalse(new ListBasedDecisionMaker([])->isAllowed('request health'));
    }

    public function testBlacklistAloneRejectsPrefixes(): void
    {
        $maker = new ListBasedDecisionMaker(spansBlacklist: ['sql ']);

        self::assertFalse($maker->isAllowed('sql select'));
        self::assertTrue($maker->isAllowed('request x'));
    }

    public function testWithoutListsItAbstainsAfterApplicationMakers(): void
    {
        self::assertNull(new ListBasedDecisionMaker()->isAllowed('anything'));
        $tagged = new ReflectionClass(ListBasedDecisionMaker::class)->getAttributes(AsTaggedItem::class)[0] ?? null;
        self::assertNotNull($tagged);
        self::assertLessThan(0, $tagged->newInstance()->priority);
    }

    public function testFirstDecidingMakerWins(): void
    {
        $abstain = new class implements AllowSpanDecisionMakerInterface {
            #[Override]
            public function isAllowed(string $message): ?bool
            {
                return null;
            }
        };
        $assembler = new SpanAssembler([$abstain, new ListBasedDecisionMaker(spansBlacklist: ['x'])]);

        self::assertInstanceOf(NullSpan::class, $assembler->assemble('x', []));
        self::assertInstanceOf(Span::class, $assembler->assemble('y', []));
        self::assertInstanceOf(Span::class, new SpanAssembler([$abstain])->assemble('x', []));
    }
}
