<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel;

use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\ProfiledMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Registered only when symfony/messenger is installed (see TestKernel).
 */
#[AsMessageHandler]
final class ProfiledMessageHandler
{
    public function __invoke(ProfiledMessage $message): void
    {
    }
}
