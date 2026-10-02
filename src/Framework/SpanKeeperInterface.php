<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;

/**
 * Implemented by factories (and their decorators) whose reset() can spare a long-running span.
 * Separate from ProfilingFactoryInterface until 2.0: adding the method there breaks BC.
 */
interface SpanKeeperInterface
{
    /**
     * Lets an open span, and therefore the spans below it, survive reset(): for spans covering
     * many units of work, such as a worker command, whose kernel.reset runs after every message.
     * Spans opened above it still end on reset(); endAll() ends it too.
     */
    public function keepOpenOnReset(SpanInterface $span): void;
}
