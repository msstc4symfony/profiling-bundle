<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prefix lists from configuration (only one of them can be set). Abstains when neither is
 * set and runs last, so application decision makers are asked first.
 */
final readonly class ListBasedDecisionMaker implements AllowSpanDecisionMakerInterface
{
    /**
     * @param list<string>|null $spansWhitelist
     * @param list<string>|null $spansBlacklist
     */
    public function __construct(
        #[Autowire(param: 'msstc4symfony_profiling.spans.whitelist')]
        private ?array $spansWhitelist = null,
        #[Autowire(param: 'msstc4symfony_profiling.spans.blacklist')]
        private ?array $spansBlacklist = null,
    ) {
    }

    #[Override]
    public static function getDefaultPriority(): int
    {
        return -1024;
    }

    #[Override]
    public function isAllowed(string $message): ?bool
    {
        if ($this->spansWhitelist !== null) {
            return array_any($this->spansWhitelist, static fn (string $prefix): bool => str_starts_with($message, $prefix));
        }

        if ($this->spansBlacklist !== null) {
            return array_all($this->spansBlacklist, static fn (string $prefix): bool => !str_starts_with($message, $prefix));
        }

        return null;
    }
}
