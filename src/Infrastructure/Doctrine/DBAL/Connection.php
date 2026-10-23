<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Override;

/**
 * @internal created by Driver only
 */
final class Connection extends AbstractConnectionMiddleware
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly QuerySpans $spans,
    ) {
        parent::__construct($connection);
    }

    #[Override]
    public function prepare(string $sql): StatementInterface
    {
        return new Statement(parent::prepare($sql), $this->spans, $sql);
    }

    /** DBAL sends queries without bound parameters here, bypassing prepare(). */
    #[Override]
    public function query(string $sql): Result
    {
        return $this->spans->profile($sql, fn (): Result => parent::query($sql));
    }

    /**
     * DBAL 3 declares int, DBAL 4 int|string; int satisfies both. DBAL 4 returns a string only for row counts
     * beyond PHP_INT_MAX.
     */
    #[Override]
    public function exec(string $sql): int
    {
        $affected = $this->spans->profile($sql, fn (): int|string => parent::exec($sql));

        return (int) $affected;
    }
}
