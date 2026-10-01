<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Span;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class Span extends AbstractSpan
{
    #[Override]
    public function isRecorded(): bool
    {
        return true;
    }
}
