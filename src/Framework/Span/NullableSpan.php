<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Span;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A span filtered out by the decision makers: it keeps the nesting intact but is not recorded.
 */
#[Exclude]
final class NullableSpan extends AbstractSpan
{
    #[Override]
    public function isRecorded(): bool
    {
        return false;
    }
}
