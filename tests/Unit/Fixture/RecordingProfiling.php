<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Application\Assembler\SpanAssembler;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactory;
use Psr\Log\NullLogger;

/**
 * A real factory that records every span and keeps nothing out.
 */
final class RecordingProfiling
{
    public static function factory(RecordingEndProcessor $recorder): ProfilingFactory
    {
        return new ProfilingFactory([new SpanAssembler([])], [], [$recorder], new NullLogger());
    }
}
