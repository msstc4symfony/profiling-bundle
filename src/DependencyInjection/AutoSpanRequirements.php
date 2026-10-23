<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\DependencyInjection;

/**
 * Tells which optional packages of an auto span are missing; checked when the configuration enables it.
 *
 * Class or interface name by package name, by auto span option, replacing AutoSpan::packages() (tests only).
 *
 * @phpstan-type PackagesSpec array<value-of<AutoSpan>, array<non-empty-string, string>>
 *
 * @internal
 */
final readonly class AutoSpanRequirements
{
    /**
     * @param PackagesSpec|null $packages
     */
    public function __construct(
        private ?array $packages = null,
    ) {
    }

    /**
     * @return list<non-empty-string>
     */
    public function missingPackages(AutoSpan $autoSpan): array
    {
        $packages = $this->packages === null ? $autoSpan->packages() : $this->packages[$autoSpan->value] ?? [];

        $missing = [];
        foreach ($packages as $package => $type) {
            if (!class_exists($type) && !interface_exists($type)) {
                $missing[] = $package;
            }
        }

        return $missing;
    }
}
