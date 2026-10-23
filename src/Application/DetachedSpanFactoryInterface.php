<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application;

use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;

/**
 * Spans for work that finishes outside the nesting of the stack, such as an asynchronous HTTP response.
 */
interface DetachedSpanFactoryInterface extends ProfilingFactoryInterface
{
    /**
     * Merged into the context of every detached span, so create processors can recognise one.
     *
     * @var array{profiling_detached: true}
     */
    public const DETACHED = ['profiling_detached' => true];

    /**
     * Context of the spans the bundle's library integrations (auto_spans) open. While end processors run, such spans
     * are not recorded: a processor writing through an instrumented library would otherwise feed itself.
     *
     * @var array{profiling_automatic: true}
     */
    public const AUTOMATIC = ['profiling_automatic' => true];

    /**
     * Like createSpan(), but the span is not pushed on the stack: its parent is the span open now, spans opened
     * meanwhile do not become its children and ending its parent does not end it. Open detached spans end with
     * IMPLICIT_END on endAll() and on reset(), whatever keepOpenOnReset() marked.
     *
     * @param array<string, mixed> $context
     */
    public function createDetachedSpan(string $message, array $context = []): SpanInterface;
}
