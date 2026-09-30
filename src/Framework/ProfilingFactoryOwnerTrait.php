<?php

declare(strict_types=1);

namespace Hot\ProfilingBundle\Framework;

use Symfony\Contracts\Service\Attribute\Required;

trait ProfilingFactoryOwnerTrait
{
    protected ?ProfilingFactoryInterface $profilingFactory = null;

    #[Required]
    public function setProfilingFactory(ProfilingFactoryInterface $profilingFactory): static
    {
        $this->profilingFactory = $profilingFactory;

        return $this;
    }

    protected function getProfilingFactory(): ProfilingFactoryInterface
    {
        if ($this->profilingFactory === null) {
            $this->profilingFactory = new NullableProfilingFactory();
        }

        return $this->profilingFactory;
    }
}
