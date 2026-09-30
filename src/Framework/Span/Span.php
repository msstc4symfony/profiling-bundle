<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework\Span;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class Span extends AbstractSpan
{
    public function __construct(string $message, array $context)
    {
        parent::__construct($message, $context);

        $this->startTime = microtime(true);
    }
}
