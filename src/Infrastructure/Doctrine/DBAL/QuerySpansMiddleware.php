<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabeller;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabelling;
use Override;

/**
 * Gives every query of one connection a "sql <type> <table>" span with the connection name in its context.
 *
 * @internal registered per DoctrineBundle connection by ProfileDoctrineDbalPass
 */
final readonly class QuerySpansMiddleware implements Middleware
{
    private QuerySpans $spans;

    public function __construct(ProfilingFactoryInterface $profiling, string $connectionName, ?SqlLabelling $labeller = null)
    {
        // One labeller per connection by default: its cache holds that connection's statements.
        $this->spans = new QuerySpans($profiling, $connectionName, $labeller ?? new SqlLabeller());
    }

    #[Override]
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->spans);
    }
}
