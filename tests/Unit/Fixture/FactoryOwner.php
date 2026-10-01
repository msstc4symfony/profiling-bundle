<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryOwnerTrait;

final class FactoryOwner
{
    use ProfilingFactoryOwnerTrait;

    public function factory(): ProfilingFactoryInterface
    {
        return $this->getProfilingFactory();
    }
}
