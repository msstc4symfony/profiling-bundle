<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AutoSpans;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Override;
use SensitiveParameter;

/**
 * An application DBAL middleware of the default priority 0 that opens a span inside query(): the profiling middleware
 * (-100) is outside it, so that span is a child of the "sql ..." span.
 */
final readonly class SpanOpeningMiddleware implements Middleware
{
    public function __construct(
        private ProfilingFactoryInterface $profiling,
    ) {
    }

    #[Override]
    public function wrap(Driver $driver): Driver
    {
        $profiling = $this->profiling;

        return new class($driver, $profiling) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly ProfilingFactoryInterface $profiling,
            ) {
                parent::__construct($driver);
            }

            #[Override]
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): Connection {
                return new class(parent::connect($params), $this->profiling) extends AbstractConnectionMiddleware {
                    public function __construct(
                        Connection $connection,
                        private readonly ProfilingFactoryInterface $profiling,
                    ) {
                        parent::__construct($connection);
                    }

                    #[Override]
                    public function query(string $sql): Result
                    {
                        $this->profiling->createSpan('inside middleware')->end();

                        return parent::query($sql);
                    }
                };
            }
        };
    }
}
