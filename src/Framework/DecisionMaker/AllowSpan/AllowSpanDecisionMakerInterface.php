<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\DecisionMaker\AllowSpan;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(AllowSpanDecisionMakerInterface::class)]
interface AllowSpanDecisionMakerInterface
{
    public function isAllow(string $message): ?bool;

    public static function getDefaultPriority(): int;
}
