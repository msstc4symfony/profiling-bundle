<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\DecisionMaker\AllowSpan;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prefix lists from configuration: a whitelist, when set, wins over the blacklist.
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
        return 0;
    }

    #[Override]
    public function isAllow(string $message): bool
    {
        if ($this->spansWhitelist !== null) {
            return array_any($this->spansWhitelist, static fn (string $prefix): bool => str_starts_with($message, $prefix));
        }

        if ($this->spansBlacklist !== null) {
            return array_all($this->spansBlacklist, static fn (string $prefix): bool => !str_starts_with($message, $prefix));
        }

        return true;
    }
}
