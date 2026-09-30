<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Assembler;

use Hot\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(SpanAssemblerInterface::class)]
interface SpanAssemblerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function assemble(string $message, array $context): ?SpanInterface;

    public static function getDefaultPriority(): int;
}
