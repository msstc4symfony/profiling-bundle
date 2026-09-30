<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Span;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class NullableSpan extends AbstractSpan
{
    #[Override]
    public function end(array $context = []): void
    {
        parent::end($context);
    }
}
