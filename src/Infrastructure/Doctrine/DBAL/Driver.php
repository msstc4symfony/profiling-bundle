<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Override;
use SensitiveParameter;

/**
 * @internal created by QuerySpansMiddleware only
 */
final class Driver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly QuerySpans $spans,
    ) {
        parent::__construct($driver);
    }

    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): ConnectionInterface {
        return new Connection(parent::connect($params), $this->spans);
    }
}
