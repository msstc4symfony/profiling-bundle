<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\LoggerProcessor;
use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoggerProcessor::class)]
#[UsesClass(AbstractSpan::class)]
#[UsesClass(Span::class)]
final class LoggerProcessorTest extends TestCase
{
    public function testLogsThePathDurationAndMergedContext(): void
    {
        $handler = new TestHandler();
        $parent = new Span('request /orders');
        $span = new Span('sql', ['table' => 'orders'])->setParentSpan($parent);

        new LoggerProcessor(new Logger('profiling', [$handler]))->process($span, ['rows' => 2]);

        $record = $handler->getRecords()[0];
        self::assertSame('request /orders > sql', $record->message);
        self::assertSame('orders', $record->context['table'] ?? null);
        self::assertSame(2, $record->context['rows'] ?? null);
        self::assertIsFloat($record->context['duration'] ?? null);
    }
}
