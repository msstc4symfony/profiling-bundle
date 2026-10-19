<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Application\Assembler;

use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(SpanAssemblerInterface::class)]
interface SpanAssemblerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function assemble(string $message, array $context): ?SpanInterface;
}
