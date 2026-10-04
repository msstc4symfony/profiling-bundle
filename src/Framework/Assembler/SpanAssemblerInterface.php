<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Framework\Assembler;

use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(SpanAssemblerInterface::class)]
interface SpanAssemblerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function assemble(string $message, array $context): ?SpanInterface;
}
