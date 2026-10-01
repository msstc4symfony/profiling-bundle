<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(AllowSpanDecisionMakerInterface::class)]
interface AllowSpanDecisionMakerInterface
{
    public function isAllowed(string $message): ?bool;
}
