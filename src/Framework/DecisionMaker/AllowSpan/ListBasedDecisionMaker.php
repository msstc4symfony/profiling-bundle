<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\DecisionMaker\AllowSpan;

final readonly class ListBasedDecisionMaker implements AllowSpanDecisionMakerInterface
{
    public function __construct(
        private ?array $spansWhitelist = null,
        private ?array $spansBlacklist = null,
    ) {
    }

    public static function getDefaultPriority(): int
    {
        return 0;
    }

    public function isAllow(string $message): bool
    {
        if (is_array($this->spansWhitelist)) {
            return array_any($this->spansWhitelist, fn ($prefix): bool => str_starts_with($message, (string) $prefix));
        }

        if (is_array($this->spansBlacklist)) {
            return array_all($this->spansBlacklist, fn ($prefix): bool => !str_starts_with($message, (string) $prefix));
        }

        return true;
    }
}
